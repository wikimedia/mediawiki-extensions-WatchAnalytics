<?php

/**
 *
 * @file
 * @ingroup Extensions
 * @author James Montalvo
 * @license MIT
 */

use MediaWiki\Title\Title;

class PendingApproval extends PendingReview {
	public function __construct( $row, Title $title ) {
		$this->title = $title;

		$this->notificationTimestamp = $row['notificationtimestamp'];
		$this->numReviewers = intval( $row['num_reviewed'] );

		// Keep these just to be consistent with PendingReview class
		$this->deletedTitle = false;
		$this->deletedNS = false;
		$this->deletionLog = false;

		// FIXME
		// no log for now, maybe link to approval log
		// no list of revisions for now
		$this->log = [];
		$this->newRevisions = [];
	}

	/**
	 * Get an array of pages user can approve that require approvals
	 * @param User $user
	 * @return array
	 */
	public static function getUserPendingApprovals( User $user ) {
		$dbr = WatchAnalyticsUtils::getReadDB();

		$queryInfo = ApprovedRevs::getQueryInfoPageApprovals( 'notlatest' );
		$latestNotApproved = $dbr->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);
		$pagesUserCanApprove = [];

		while ( $page = $latestNotApproved->fetchRow() ) {

			// $page with keys id, rev_id, latest_id
			$title = Title::newFromID( $page['id'] );

			if ( ApprovedRevs::userCanApprove( $user, $title ) ) {

				// FIXME: May want to get these in there so PendingReviews can
				// show the list of revs in the approval.
				// 'approved_rev_id' => $page['rev_id']
				// 'latest_rev_id' => $page['latest_id']
				$pagesUserCanApprove[] = new self(
					[
						'notificationtimestamp' => null,
						'num_reviewed' => 0, // if page has pending approval, zero people have approved
					],
					$title
				);

			}

		}

		return $pagesUserCanApprove;
	}

}
