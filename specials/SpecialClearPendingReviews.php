<?php
/**
 * ClearPendingReviews SpecialPage
 *
 * @file
 * @ingroup Extensions
 */

use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Title\Title;

class SpecialClearPendingReviews extends SpecialPage {
	public function __construct() {
		parent::__construct( 'ClearPendingReviews', 'clearreviews' );
	}

	public function execute( $par ) {
		$output = $this->getOutput();

		if ( !$this->getUser()->isAllowed( 'clearreviews' ) ) {
			throw new PermissionsError( 'clearreviews' );
		}

		$this->setHeaders();
		$output->addModules( 'ext.watchanalytics.clearpendingreviews.scripts' );

		// Defines input form
		$formDescriptor = [
			'start' => [
				'section' => 'section1',
				'label-message' => 'clearpendingreview-start-time',
				'type' => 'text',
				'required' => 'true',
				'validation-callback' => [ $this, 'validateTime' ],
			],
			'end' => [
				'section' => 'section1',
				'label-message' => 'clearpendingreview-end-time',
				'type' => 'text',
				'required' => 'true',
				'validation-callback' => [ $this, 'validateTime' ],
				'help' => '<b>Current time:</b> ' . date( 'YmdHi' ) . '00',
			],
			'category' => [
				'section' => 'section2',
				'label-message' => 'clearpendingreview-category',
				'type' => 'text',
				'validation-callback' => [ $this, 'validateCategory' ],
			],
			'page' => [
				'section' => 'section2',
				'label-message' => 'clearpendingreview-page-title',
				'type' => 'text',
			],
		];

		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'clearform' );

		$form->setSubmitText( 'Preview' );
		$form->setSubmitName( 'preview' );
		$form->setSubmitCallback( [ $this, 'trySubmit' ] );
		$form->show();
	}

	public function validateTime( $dateField, $allData ) {
		if ( !is_string( $dateField ) ) {
			return wfMessage( 'clearpendingreviews-date-invalid' )->inContentLanguage();
		}

		// Validates start time is before end time
		if ( $allData['start'] > $allData['end'] ) {
			return wfMessage( 'clearpendingreviews-date-order-invalid' )->inContentLanguage();
		}

		// Verifys input format is ISO
		$dateTime = DateTime::createFromFormat( 'YmdHis', $dateField );
		if ( $dateTime ) {
				return $dateTime->format( 'YmdHis' ) === $dateField;
		}

		return wfMessage( 'clearpendingreviews-date-invalid' )->inContentLanguage();
	}

	public function validateCategory( $categoryField, $allData ) {
		// Validates either Category or Title field is used
		if ( empty( $categoryField ) && empty( $allData['page'] ) ) {
			return wfMessage( 'clearpendingreviews-missing-date-category' )->inContentLanguage();
		}
		if ( empty( $categoryField ) ) {
			return true;
		}

		// Verify that category exists in wiki
		$categoryTitle = Title::makeTitleSafe( NS_CATEGORY, $categoryField );
		if ( !$categoryTitle->exists() ) {
			return wfMessage( 'clearpendingreviews-category-invalid' )->inContentLanguage();
		}

		return true;
	}

	/**
	 * @param array $data
	 * @param bool $clearPages
	 * @return \Wikimedia\Rdbms\IResultWrapper
	 */
	public static function doSearchQuery( $data, $clearPages ) {
		$dbw = WatchAnalyticsUtils::getWriteDB();
		$tables = [ 'w' => 'watchlist', 'p' => 'page', 'c' => 'categorylinks' ];
		$vars = [ 'w.*' ];
		$join_conds = [
			'p' => [
				'LEFT JOIN', 'w.wl_title=p.page_title'
			],
			'c' => [
				'LEFT JOIN', 'c.cl_from=p.page_id'
			]
		];

		$category = preg_replace( '/\s+/', '_', $data['category'] );
		$page = preg_replace( '/\s+/', '_', $data['page'] );
		$start = preg_replace( '/\s+/', '', $data['start'] );
		$end = preg_replace( '/\s+/', '', $data['end'] );
		$conditions = '';

		if ( $category ) {
			$quotedCategory = $dbw->addQuotes( $category );
			// MW 1.45+
			$useTargetID = !$dbw->fieldExists( 'categorylinks', 'cl_to' );
			if ( $useTargetID ) {
				$tables['l'] = 'linktarget';
				$join_conds['l'] = [ 'LEFT JOIN', 'l.lt_id=c.cl_target_id' ];
				$conditions .= "l.lt_title=$quotedCategory AND ";
			} else {
				$conditions .= "c.cl_to=$quotedCategory AND ";
			}
		}
		if ( $page ) {
			$conditions .= 'w.wl_title ' . $dbw->buildLike( $page, $dbw->anyString() ) . ' AND ';
		}

		$conditions .= "w.wl_notificationtimestamp IS NOT NULL AND w.wl_notificationtimestamp < $end AND w.wl_notificationtimestamp > $start";

		$results = $dbw->select( $tables, $vars, $conditions, __METHOD__, 'DISTINCT', $join_conds );

		if ( $clearPages ) {
			foreach ( $results as $result ) {
				$values = [ 'wl_notificationtimestamp' => null ];
				$conds = [ 'wl_id' => $result->wl_id ];
				$options = [];
				$dbw->update( 'watchlist', $values, $conds, __METHOD__, $options );
			}
		}

		return $results;
	}

	/**
	 * @param array $data
	 * @param HTMLForm $form
	 * @return bool
	 */
	public function trySubmit( $data, $form ) {
		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();

		if ( $request->wasPosted() && $request->getVal( 'clearpages' ) ) {
			// Clears pending reviews
			$results = $this->doSearchQuery( $data, true );

			// Count how many pages were cleared
			$pageCount = 0;
			foreach ( $results as $result ) {
				$pageCount += 1;
			}

			// Log when pages are cleared in Special:Log
			$logEntry = new ManualLogEntry( 'pendingreviews', 'clearreviews' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $this->getPageTitle() );
			$logEntry->setParameters( [
				'3::paramname' => '(' . $data['start'] . ' - ' . $data['end'] . ')',
				'4::paramname' => '(' . $pageCount . ')',
				'5::paramname' => '(' . $data['category'] . ')',
				'6::paramname' => '(' . $data['page'] . ')',
				] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
			$this->getHookContainer()->run( 'PendingReviewsCleared', [ &$data, &$results, &$pageCount ] );

			// Create link back to Special:ClearPendingReviews.
			$pageLinkWikitext = '[[' . $this->getPageTitle()->getFullText() . '|' . $this->msg( 'clearpendingreviews' ) . ']]';
			$output->addHTML( "<b>" );
			$output->addWikiMsg( 'clearpendingreviews-success', $pageCount );
			$output->addHTML( "</b>" );
			$output->addHTML( '<p>' . $this->msg( 'clearpendingreviews-success-return', $pageLinkWikitext )->parse() . '</p>' );

			// Don't reload the form after clearing pages.
			return true;

		} else {
			$results = $this->doSearchQuery( $data, false );
			$table = '';
			$table .= "<table class='wikitable' style='width:100%'>";
			$table .= "<tr>";
			$table .= "<td style='vertical-align:top;'>";
			$table .= "<h3>" . wfMessage( 'clearpendingreviews-pages-cleared' )->escaped() . "</h3>";
			$table .= "<ul>";
			$impactedPages = [];
			foreach ( $results as $result ) {
				$impactedPages[] = Title::makeTitle( $result->wl_namespace, $result->wl_title );
			}

			$impactedPages = array_unique( $impactedPages );
			foreach ( $impactedPages as $page ) {
				$table .= Html::rawElement( 'li', null, Linker::link( $page ) );
			}

			$table .= "</ul>";
			$table .= "</td>";
			$table .= "<td style='vertical-align:top;'>";
			$table .= "<h3>" . wfMessage( 'clearpendingreviews-people-impacted' )->escaped() . "</h3>";
			$table .= "<ul>";
			$impactedUsers = [];
			foreach ( $results as $result ) {
				$userID = $result->wl_user;
				// Use array key to ensure uniqueness.
				$impactedUsers[$userID] = User::newFromID( $userID );
			}

			foreach ( $impactedUsers as $user ) {
				$table .= Html::rawElement( 'li', null, Linker::link( $user->getUserPage() ) );
			}

			$table .= "</ul>";
			$table .= "</td>";
			$table .= "</tr>";
			$table .= "</table>";

			$form->setSubmitText( wfMessage( 'clearform-submit' )->text() );
			$form->setSubmitName( 'clearpages' );
			$form->setSubmitDestructive();
			$form->setCancelTarget( $this->getPageTitle() );
			$form->showCancel();
			$this->getHookContainer()->run( 'PendingReviewsPreview', [ &$data, &$results ] );
			// Display preview of pages to be cleared
			$form->setPostHtml( $table );

			return false;
		}
	}
}
