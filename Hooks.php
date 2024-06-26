<?php

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;

class WatchAnalyticsHooks {

	/**
	 * Handler for skin template navigation
	 *
	 * @param SkinTemplate $skin
	 * @param array &$links
	 * @throws MWException
	 * @throws ConfigException
	 */
	public static function onSkinTemplateNavigation__Universal( $skin, &$links ): void {
		global $wgOut;

		$user = $skin->getUser();
		if ( !$user->isAllowed( 'pendingreviewslink' ) ) {
			return;
		}

		$wgOut->addModuleStyles( 'ext.watchanalytics.base' );
		// NOTE: $wgOut->addModules() does not appear to work here, so
		// the onBeforePageDisplay() method was created below.

		// Get user's watch/review stats
		$watchStats = $user->watchStats; // set in onBeforePageDisplay() hook
		$numPending = $watchStats['num_pending'];
		$maxPendingDays = $watchStats['max_pending_days'];

		// Get user's pending approvals
		// Check that Approved Revs is installed
		$numPendingApprovals = 0;
		if ( class_exists( 'ApprovedRevs' ) ) {
			$numPendingApprovals = count( PendingApproval::getUserPendingApprovals( $user ) );
		}

		// Determine CSS class of Watchlist/PendingReviews link
		$watchlistLinkClasses = [ 'mw-watchanalytics-watchlist-badge' ];
		if ( $numPending != 0 ) {
			$watchlistLinkClasses = [ 'mw-watchanalytics-watchlist-pending' ];
		}

		// Determine text of Watchlist/PendingReviews link
		global $egPendingReviewsEmphasizeDays;
		if ( $maxPendingDays > $egPendingReviewsEmphasizeDays ) {
			$watchlistLinkClasses[] = 'mw-watchanalytics-watchlist-pending-old';
			if ( $numPendingApprovals != 0 ) {
				$text = $skin->msg( 'watchanalytics-personal-url-approvals-old' )->params( $numPending, $maxPendingDays, $numPendingApprovals )->text();
			} else {
				$text = $skin->msg( 'watchanalytics-personal-url-old' )->params( $numPending, $maxPendingDays )->text();
			}
		} else {
			if ( $numPendingApprovals != 0 ) {
				$text = $skin->msg( 'watchanalytics-personal-url-approvals' )->params( $numPending, $numPendingApprovals )->text();
			} else {
				$text = $skin->msg( 'watchanalytics-personal-url' )->params( $numPending )->text();
			}

		}

		// set "watchlist" link to Pending Reviews
		$links['user-menu']['watchlist'] = [
			'href' => SpecialPage::getTitleFor( 'PendingReviews' )->getLocalURL(),
			'text' => $text,
			'class' => $watchlistLinkClasses
		];
	}

	/**
	 * Handler for BeforePageDisplay hook. This function does the following:
	 *
	 * 1) Determine if user should see shaky pending reviews link
	 * 2) Insert page scores on applicable pages
	 *
	 * Also supports parameter: Skin $skin.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 *
	 * @param OutputPage $out reference to OutputPage object
	 */
	public static function onBeforePageDisplay( $out /*, $skin*/ ) {
		$user = $out->getUser();
		$title = $out->getTitle();

		#
		# 1) Is user's oldest pending review old enough to require emphasis?
		#
		$userWatch = new UserWatchesQuery();
		$user->watchStats = $userWatch->getUserWatchStats( $user );
		$user->watchStats['max_pending_days'] = ceil(
			$user->watchStats['max_pending_minutes'] / ( 60 * 24 )
		);

		global $egPendingReviewsEmphasizeDays;
		if ( $user->watchStats['max_pending_days'] > $egPendingReviewsEmphasizeDays ) {
			$out->addModules( [ 'ext.watchanalytics.shakependingreviews' ] );
		}

		#
		# 2) Insert page scores
		#
		if ( in_array( $title->getNamespace(), $GLOBALS['egWatchAnalyticsPageScoreNamespaces'] )
			&& $user->isAllowed( 'viewpagescore' )
			&& PageScore::pageScoreIsEnabled() ) {

			$pageScore = new PageScore( $title );
			$out->addScript( $pageScore->getPageScoreTemplate() );
			$out->addModules( 'ext.watchanalytics.pagescores.scripts' );
			$out->addModuleStyles( 'ext.watchanalytics.pagescores.styles' );
		}
	}

	/**
	 * Handler for PageMoveComplete hook. This function makes it so page-moves
	 * are handled correctly in the `watchlist` table. Prior to a MW 1.25 alpha
	 * release when a page is moved, the new entries into the `watchlist` table
	 * are given an notification timestamp of NULL; they should be identical to
	 * the notification timestamps of the original title so users are notified
	 * of changes prior to the move. Code taken from MediaWiki core head branch
	 * WatchedItem::doDuplicateEntries() method.
	 *
	 * @todo FIXME: make this work for <1.25 and 1.25+
	 * @todo document which commit fixes this issue specifically.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageMoveComplete
	 *
	 * @param LinkTarget $old
	 * @param LinkTarget $new
	 * @param UserIdentity $userIdentity
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param RevisionRecord $revision
	 */
	public static function onPageMoveComplete( LinkTarget $old, LinkTarget $new,
			UserIdentity $userIdentity, int $pageid, int $redirid, string $reason, RevisionRecord $revision ) {
		#
		# Record move in watch stats
		#
		WatchStateRecorder::recordPageChange( Article::newFromID( $pageid ) );

		// if a redirect was created, record data for the "new" page (the redirect)
		if ( $redirid > 0 ) {
			WatchStateRecorder::recordPageChange( Article::newFromID( $redirid ) );
		}

		#
		# BELOW IS THE pre-MW 1.25 FIX.
		#
		$oldNS = $old->getNamespace();
		$newNS = $new->getNamespace();
		$oldDBkey = $old->getDBkey();
		$newDBkey = $new->getDBkey();

		$dbw = WatchAnalyticsUtils::getWriteDB();
		$results = $dbw->select( 'watchlist',
			[ 'wl_user', 'wl_notificationtimestamp' ],
			[ 'wl_namespace' => $oldNS, 'wl_title' => $oldDBkey ],
			__METHOD__
		);
		# Construct array to replace into the watchlist
		$values = [];
		foreach ( $results as $oldRow ) {
			$values[] = [
				'wl_user' => $oldRow->wl_user,
				'wl_namespace' => $newNS,
				'wl_title' => $newDBkey,
				'wl_notificationtimestamp' => $oldRow->wl_notificationtimestamp,
			];
		}

		if ( empty( $values ) ) {
			// Nothing to do
			return;
		}

		# Perform replace
		# Note that multi-row replace is very efficient for MySQL but may be inefficient for
		# some other DBMSes, mostly due to poor simulation by us
		$dbw->replace(
			'watchlist',
			[ [ 'wl_user', 'wl_namespace', 'wl_title' ] ],
			$values,
			__METHOD__
		);
	}

	/**
	 * Register magic-word variable ID to hide page score from select pages.
	 *
	 * @see FIXME (include link to hook documentation)
	 *
	 * @param array &$magicWordVariableIDs array of names of magic words
	 */
	public static function onGetMagicVariableIDs( &$magicWordVariableIDs ) {
		$magicWordVariableIDs[] = 'MAG_NOPAGESCORE';
	}

	/**
	 * Set values in the page_props table based on the presence of the
	 * 'NOPAGESCORE' magic word in a page
	 *
	 * @see FIXME (include link to hook documentation)
	 *
	 * @param Parser &$parser reference to MediaWiki parser.
	 * @param string &$text FIXME html/wikitext? of output page before complete
	 */
	public static function handleMagicWords( &$parser, &$text ) {
		$factory = MediaWikiServices::getInstance()->getMagicWordFactory();
		$magicWord = $factory->get( 'MAG_NOPAGESCORE' );

		if ( $magicWord->matchAndRemove( $text ) ) {
			PageScore::noPageScore();
		}
	}

	/**
	 * Prior to clearing notification timestamp determines if user is watching page,
	 * and if so determines what their review status is. Records review and adds
	 * "defer" banner if required.
	 *
	 * @see FIXME (include link to hook documentation)
	 *
	 * @param WikiPage $wikiPage
	 * @param User $user
	 */
	public static function onPageViewUpdates( WikiPage $wikiPage, User $user ) {
		$title = $wikiPage->getTitle();
		$article = new Article( $title );
		$req = $article->getContext()->getRequest();
		$isDiff = $req->getText( 'oldid' );
		$reviewHandler = ReviewHandler::setup( $user, $title, $isDiff );

		if ( $reviewHandler::pageIsBeingReviewed() ) {

			global $wgOut;

			// display "unreview" button
			$wgOut->addScript( $reviewHandler->getTemplate() );
			$wgOut->addModules( [
				'ext.watchanalytics.reviewhandler.scripts',
				'ext.watchanalytics.reviewhandler.styles'
			] );

			// record change in user/page stats
			WatchStateRecorder::recordReview( $user, $title );

		}
	}

	/**
	 * Occurs after the save page request has been processed, and causes the
	 * new state of "watches" and "reviews" to be recorded for the page and all
	 * of its watchers.
	 *
	 * Additional parameters available include: User $user, Content $content,
	 * string $summary, boolean $isMinor, boolean $isWatch, $section Deprecated,
	 * integer $flags, {Revision|null} $revision, Status $status, integer $baseRevId
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 *
	 * @param WikiPage $wikipage
	 */
	public static function onPageSaveComplete( WikiPage $wikipage ) {
		WatchStateRecorder::recordPageChange( $wikipage );
	}

}
