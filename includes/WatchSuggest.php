<?php

use MediaWiki\Linker\Linker;
use MediaWiki\Title\Title;

class WatchSuggest {

	/**
	 * @var User : reference to current user
	 */
	public $mUser;

	/**
	 * @var DatabaseBase : FIXME confirm correct class
	 */
	public $dbr;

	public function __construct( User $user ) {
		$this->mUser = $user;
		$this->dbr = WatchAnalyticsUtils::getReadDB();
	}

	/**
	 * Handles something.
	 *
	 * @return string
	 */
	public function getWatchSuggestionList() {
		$html = '';

		// gets id, NS and title of all pages in users watchlist in NS_MAIN
		$userWatchlist = $this->getUserWatchlist( $this->mUser, NS_MAIN );
		$linkedPages = [];

		// if the user has pages from NS_MAIN in their watchlist then find all
		// pages linked to/from those pages
		if ( count( $userWatchlist ) > 0 ) {
			$linkedPages = $this->getPagesRelatedByLinks( $userWatchlist );
		}

		if ( count( $linkedPages ) == 0 ) {

			// if the users watchlist in NS_MAIN is empty (e.g. probably a new user)
			// create a list of all pages in NS_MAIN as an approximation. Ideally it'd
			// find some other way to suggest pages (by pages the person has viewed, perhaps)
			$noWatchlist = $this->dbr->select(
				'page',
				'page_id',
				'page_namespace = 0',
				__METHOD__
			);

			$linkedPages = [];

			// create "fake" $linkedPages where all pages in NS_MAIN are given the same number
			// of inbound/outbound links from the users watchlist
			while ( $row = $noWatchlist->fetchObject() ) {
				$linkedPages[ $row->page_id ] = [ 'num_links' => 1 ];
			}

		}

		$pageWatchQuery = new PageWatchesQuery;
		$pageWatchesAndViews = $pageWatchQuery->getPageWatchesAndViews( array_keys( $linkedPages ) );

		// add newly found number of watchers to linkedPages...
		foreach ( $pageWatchesAndViews as $row ) {
			$linkedPages[ $row->page_id ][ 'num_watches' ] = $row->num_watches;
			$linkedPages[ $row->page_id ][ 'num_views' ] = $row->num_views;
		}

		$sortedPages = $this->sortPagesByWatchImportance( $linkedPages );

		$userIsViewer = RequestContext::getMain()->getUser()->getId() == $this->mUser->getId();

		$count = 1;
		$watchSuggestionsTitle = wfMessage( 'pendingreviews-watch-suggestion-title' )->escaped();
		$watchSuggestionsDescription = wfMessage( 'pendingreviews-watch-suggestion-description' )->escaped();

		global $egPendingReviewsNumberWatchSuggestions;

		$watchSuggestionsLIs = [];
		foreach ( $sortedPages as $pageId => $pageInfo ) {

			$suggestedTitle = Title::newFromID( $pageInfo[ 'page_id' ] );
			if ( !$suggestedTitle // for some reason some pages in the pagelinks table don't exist in either table page or table archive...
				|| $suggestedTitle->getNamespace() !== 0 // skip pages not in the main namespace
				|| $suggestedTitle->isRedirect() ) { // don't need redirects
				continue;
			}

			if ( $userIsViewer ) {
				$watchLink = '<strong>' . self::getWatchLink( $suggestedTitle ) . ':</strong> ';
			} else {
				$watchLink = '';
			}

			$pageLink = '<a href="' . $suggestedTitle->getLinkURL() . '">' . $suggestedTitle->getFullText() . '</a>';

			$watchSuggestionsLIs[] = '<li>' . $watchLink . $pageLink . '</li>';

			$count++;
			if ( $count > $egPendingReviewsNumberWatchSuggestions ) {
				break;
			}

		}

		$numTopWatchers = 20;

		$html .= "<br /><br />"
			. "<h3>$watchSuggestionsTitle</h3>"
			. "<p>$watchSuggestionsDescription</p>"
			. WatchAnalyticsHtmlHelper::formatListArray( $watchSuggestionsLIs, 2 )
			. '<br /><h3>' . wfMessage( 'pendingreviews-watch-suggestion-leaders-title' )->text() . '</h3>'
			. "<p>" . wfMessage( 'pendingreviews-watch-suggestion-leaders-desc' )->text() . "</p>"
			. WatchAnalyticsHtmlHelper::formatListArray( $this->getMostWatchesListArray( $numTopWatchers ), 2 );

		return $html;
	}

	public function getUserWatchlist( User $user, $namespaces = [] ) {
		if ( !is_array( $namespaces ) ) {
			if ( intval( $namespaces ) < 0 ) {
				throw new MWException( __METHOD__ . ' argument $namespace requires integer or array' );
			}
			$namespaces = [ $namespaces ];
		}

		if ( count( $namespaces ) > 1 ) {
			$namespaceCondition = 'AND p.page_namespace IN (' . $this->dbr->makeList( $namespaces ) . ')';
		} elseif ( count( $namespaces ) === 1 ) {
			$namespaceCondition = 'AND p.page_namespace = ' . $namespaces[0];
		} else {
			$namespaceCondition = '';
		}

		$userId = $user->getId();

		// SELECT
		// p.page_id AS p_id,
		// w.wl_title AS p_title
		// FROM page AS p
		// LEFT JOIN watchlist AS w
		// ON (
		// w.wl_namespace = p.page_namespace
		// AND w.wl_title = p.page_title
		// )
		// WHERE
		// w.wl_user = $userId
		// AND p.page_namespace = 0
		$userWatchlist = $this->dbr->select(
			[
				'p' => 'page',
				'w' => 'watchlist',
			],
			[
				'p.page_id AS p_id',
				'w.wl_namespace AS p_namespace',
				'w.wl_title AS p_title',
			],
			"w.wl_user=$userId " . $namespaceCondition,
			__METHOD__,
			[], // options
			[
				'w' => [
					'LEFT JOIN',
					'w.wl_namespace = p.page_namespace AND w.wl_title = p.page_title'
				]
			]
		);

		$return = [];
		while ( $row = $userWatchlist->fetchObject() ) {
			$return[] = $row;
		}

		return $return;
	}

	public function getPagesRelatedByLinks( $userWatchlist ) {
		$userWatchlistPageIds = [];
		$userWatchlistPageTitles = [];
		foreach ( $userWatchlist as $row ) {
			$userWatchlistPageIds[] = $row->p_id;
			$userWatchlistPageTitles[] = $row->p_title;
		}
		$pageIdsFromTitles = [];
		if ( !empty( $userWatchlistPageTitles ) ) {
			$titleResult = $this->dbr->select(
				'page',
				'page_id',
				[ 'page_title' => $userWatchlistPageTitles, 'page_namespace' => 0 ], // namespace 0 for NS_MAIN
				__METHOD__
			);
			foreach ( $titleResult as $row ) {
				$pageIdsFromTitles[] = $row->page_id;
			}
		}
		// Collect all unique page IDs that could be either the source (pl_from) or the target (pl_target_id) of a link.
		$relevantPageIds = array_unique( array_merge( $userWatchlistPageIds, $pageIdsFromTitles ) );

		// Prepare the list for the SQL IN clause
		$idsList = $this->dbr->makeList( $relevantPageIds );

		$where = "pl.pl_from IN ($idsList)";

		// Only add the pl_target_id part if we have relevant target IDs to check against
		if ( !empty( $idsList ) ) {
			$where .= " OR pl.pl_target_id IN ($idsList)";
		}

		$linkedPagesResult = $this->dbr->select(
			[
				'pl' => 'pagelinks',
				'p_to' => 'page',
			],
			[
				'pl.pl_from AS pl_from_id',
				'p_to.page_id AS pl_to_id',
			],
			$where,
			__METHOD__,
			[], // options
			[
				'p_to' => [
					'INNER JOIN',
					'pl.pl_target_id = p_to.page_id'
				],
			]
		);
		$linkedPages = [];
		while ( $row = $linkedPagesResult->fetchObject() ) {
			if ( !isset( $linkedPages[ $row->pl_from_id ] ) ) {
				$linkedPages[ $row->pl_from_id ] = 1;
			} else {
				$linkedPages[ $row->pl_from_id ]++;
			}

			if ( !isset( $linkedPages[ $row->pl_to_id ] ) ) {
				$linkedPages[ $row->pl_to_id ] = 1;
			} else {
				$linkedPages[ $row->pl_to_id ]++;
			}
		}

		$linkedPagesToKeep = [];
		foreach ( $linkedPages as $pageId => $numLinks ) {
			if ( !in_array( $pageId, $userWatchlistPageIds ) ) {
				$linkedPagesToKeep[ $pageId ] = [ 'num_links' => $numLinks ];
			}
		}

		return $linkedPagesToKeep;
	}

	public function sortPagesByWatchImportance( $pages ) {
		$watches = [];
		$links = [];
		$watchNeedArray = [];
		$sortedPages = [];
		foreach ( $pages as $pageId => $pageData ) {
			if ( isset( $pageData[ 'num_watches' ] ) ) {
				$numWatches = intval( $pageData[ 'num_watches' ] );
				$numViews = intval( $pageData[ 'num_views' ] );
			} else {
				$numWatches = 0;
				$numViews = 0;
			}
			$numLinks = intval( $pageData[ 'num_links' ] );

			$watchNeed = $numLinks * pow( $numViews, 2 );

			$sortedPages[] = [
				'page_id' => $pageId,
				'num_watches' => $numWatches,
				'num_links' => $numLinks,
				'num_views' => $numViews,
				'watch_need' => $watchNeed,
			];
			$watches[] = $numWatches;
			$links[] = $numLinks;
			$watchNeedArray[] = $watchNeed;
		}
		array_multisort( $watches, SORT_ASC, $watchNeedArray, SORT_DESC, $sortedPages );

		return $sortedPages;
	}

	public function getMostWatchesListArray( $limit = 20 ) {
		$mostWatches = $this->dbr->select(
			[
				'w' => 'watchlist',
				'p' => 'page',
				'u' => 'user',
			],
			[
				'MAX(u.user_name) AS user_name',
				'MAX(u.user_real_name) AS real_name',
				'COUNT( * ) AS user_watches',
			],
			'p.page_is_redirect = 0 AND w.wl_user != 0', // no redirects, and don't include maintenance scripts and other non-users
			__METHOD__,
			[
				'GROUP BY' => 'w.wl_user',
				'ORDER BY' => 'user_watches DESC',
				'LIMIT' => $limit,
			],
			[
				'p' => [
					'RIGHT JOIN',
					'w.wl_namespace = p.page_namespace AND w.wl_title = p.page_title'
				],
				'u' => [
					'LEFT JOIN',
					'w.wl_user = u.user_id'
				],
			]
		);

		$return = [];
		$count = 0;
		while ( $user = $mostWatches->fetchObject() ) {
			$count++;
			// CONSIDERING usering real name
			// if ( $user->real_name ) {
			// $displayName = $user->real_name;
			// }
			// else {
			// $displayName = $user->user_name;
			// }

			$watchUser = User::newFromName( $user->user_name );
			if ( $watchUser ) {
				$userPage = $watchUser->getUserPage();
				$userPageLink = Linker::link( $userPage, htmlspecialchars( $userPage->getFullText() ) );

				$watches = '<strong>' . $user->user_watches . '</strong> pages watched';

				$return[] = "<li>$userPageLink - $watches</li>";
			}

		}

		return $return;
	}

	public static function getWatchLink( Title $title ) {
		$user = RequestContext::getMain()->getUser();

		// action=watch&token=9d1186bca6dd20866e607538b92be6c8%2B%5C
		$watchLinkURL = $title->getLinkURL( [
			'action' => 'watch',
			'token' => $user->getEditToken(),
		] );

		$watchLink =
			Xml::element(
				'a',
				[
					'href' => $watchLinkURL,
					'class' => 'pendingreviews-watch-suggest-link',
					'suggest-title-prefixed-text' => $title->getPrefixedDBkey(),
					'thanks-msg' => wfMessage( 'pendingreviews-watch-suggestion-thanks' )->text()// FIXME: there's a better way
				],
				wfMessage( 'pendingreviews-watch-suggestion-watchlink' )->text()
			);

		return $watchLink;
	}

}
