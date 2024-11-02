<?php

use MediaWiki\MediaWikiServices;

class PendingReview {

	/**
	 * @var int Time of oldest change user hasn't seen
	 * @example 20141031072315
	 */
	public $notificationTimestamp;

	/**
	 * @var Title
	 */
	public $title;

	/**
	 * @var array|false
	 * @todo FIXME: document
	 */
	public $newRevisions;

	/**
	 * @var string|bool Text of deleted title in the DBKey format ("Main_Page")
	 */
	public $deletedTitle;

	/**
	 * @var int
	 */
	public $deletedNS;

	/**
	 * @var array|false
	 * @todo FIXME: document
	 */
	public $deletionLog;

	/**
	 * @var int : number of people who have reviewed this page
	 */
	public $numReviewers;

	/**
	 * @var array|false
	 * @todo FIXME: document
	 */
	public $log;

	public function __construct( $row, ?Title $title = null ) {
		$notificationTimestamp = $row['notificationtimestamp'];

		$this->notificationTimestamp = $notificationTimestamp;
		$this->numReviewers = intval( $row['num_reviewed'] );

		if ( $title ) {
			$pageID = $title->getArticleID();
			$namespace = $title->getNamespace();
			$titleDBkey = $title->getDBkey();
		} else {
			$pageID = $row['page_id'];
			$namespace = $row['namespace'];
			$titleDBkey = $row['title'];

			if ( $pageID ) {
				$title = Title::newFromID( $pageID );
			} else {
				$title = false;
			}
		}

		if ( $pageID && $title->exists() ) {

			$dbr = WatchAnalyticsUtils::getReadDB();

			$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();

			$revQueryInfo = $revisionStore->getQueryInfo();

			$revResults = $dbr->select(
				$revQueryInfo['tables'],
				$revQueryInfo['fields'],
				[
					'rev_page' => $pageID,
					'rev_timestamp >= ' . (int)$notificationTimestamp
				],
				__METHOD__,
				[ 'ORDER BY' => 'rev_timestamp ASC' ],
				$revQueryInfo['joins']
			);
			$revsPending = [];
			while ( $rev = $revResults->fetchObject() ) {
				$revsPending[] = $rev;
			}

			$logResults = $dbr->select(
				[ 'l' => 'logging' ],
				[ '*' ],
				[
					'l.log_page' => $pageID,
					'l.log_timestamp >= ' . (int)$notificationTimestamp,
					"l.log_type NOT IN ('interwiki','newusers','patrol','rights','upload')"
				],
				__METHOD__,
				[ 'ORDER BY' => 'log_timestamp ASC' ]
			);
			$logPending = [];
			while ( $log = $logResults->fetchObject() ) {
				$logPending[] = $log;
			}

			$deletedNS = false;
			$deletedTitle = false;
			$deletionLog = false;

		} else {
			$deletedNS = $namespace;
			$deletedTitle = $titleDBkey;
			$deletionLog = $this->getDeletionLog( $deletedTitle, $deletedNS, $notificationTimestamp );
			$logPending = false;
			$revsPending = false;
		}

		$this->title = $title;
		$this->newRevisions = $revsPending;
		$this->deletedTitle = $deletedTitle;
		$this->deletedNS = $deletedNS;
		$this->deletionLog = $deletionLog;
		$this->log = $logPending;
	}

	public static function getPendingReviewsList( User $user, $limit, $offset ) {
		$tables = [
			'w' => 'watchlist',
			'p' => 'page',
			'log' => 'logging',
		];

		$dbr = WatchAnalyticsUtils::getReadDB();

		$fields = [
			'p.page_id AS page_id',
			'log.log_action AS log_action',
			'w.wl_namespace AS namespace',
			'w.wl_title AS title',
			'w.wl_notificationtimestamp AS notificationtimestamp',
			"(SELECT COUNT(*) FROM {$dbr->tableName( 'watchlist' )} AS subwatch
			  WHERE
				subwatch.wl_namespace = w.wl_namespace
				AND subwatch.wl_title = w.wl_title
				AND subwatch.wl_notificationtimestamp IS NULL
			) AS num_reviewed",
		];

		$conds = [
			'w.wl_user' => $user->getId(),
			'w.wl_notificationtimestamp IS NOT NULL'
		];

		$options = [
			'ORDER BY' => 'num_reviewed ASC, w.wl_notificationtimestamp ASC',
			'OFFSET' => $offset,
			'LIMIT' => $limit,
		];

		$join_conds = [
			'p' => [
				'LEFT JOIN', 'p.page_namespace=w.wl_namespace AND p.page_title=w.wl_title'
			],
			'log' => [
				'LEFT JOIN',
				'log.log_namespace = w.wl_namespace '
				. ' AND log.log_title = w.wl_title'
				. ' AND p.page_namespace IS NULL'
				. ' AND p.page_title IS NULL'
				. ' AND log.log_action IN ("delete","move")'
			],
		];

		$watchResult = $dbr->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$join_conds
		);

		$pending = [];

		while ( $row = $watchResult->fetchRow() ) {
			$pending[] = new self( $row );
		}

		// If ApprovedRevs is installed, append any pages in need of approvals
		// to the front of the Pending Reviews list
		if ( class_exists( 'ApprovedRevs' ) ) {
			$pending = array_merge( PendingApproval::getUserPendingApprovals( $user ), $pending );
		}

		return $pending;
	}

	public static function getPendingReview( User $user, Title $title ) {
		$tables = [
			'w' => 'watchlist',
			'p' => 'page',
			'log' => 'logging',
		];

		$dbr = WatchAnalyticsUtils::getReadDB();

		$fields = [
			'p.page_id AS page_id',
			'log.log_action AS log_action',
			'w.wl_namespace AS namespace',
			'w.wl_title AS title',
			'w.wl_notificationtimestamp AS notificationtimestamp',
			"(SELECT COUNT(*) FROM {$dbr->tableName( 'watchlist' )} AS subwatch
				WHERE
				subwatch.wl_namespace = w.wl_namespace
				AND subwatch.wl_title = w.wl_title
				AND subwatch.wl_notificationtimestamp IS NULL
			) AS num_reviewed",
		];

		$conds = [
			'w.wl_user' => $user->getId(),
			'p.page_id' => $title->getArticleID(),
			'w.wl_notificationtimestamp IS NOT NULL'
		];

		$options = [];

		$join_conds = [
			'p' => [
				'LEFT JOIN', 'p.page_namespace=w.wl_namespace AND p.page_title=w.wl_title'
			],
			'log' => [
				'LEFT JOIN',
				'log.log_namespace = w.wl_namespace '
				. ' AND log.log_title = w.wl_title'
				. ' AND p.page_namespace IS NULL'
				. ' AND p.page_title IS NULL'
				. ' AND log.log_action IN ("delete","move")'
			],
		];

		$watchResult = $dbr->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$join_conds
		);

		$pending = [];

		while ( $row = $watchResult->fetchRow() ) {
			$pending[] = new self( $row );
		}

		return $pending;
	}

	/**
	 * @param string $title Page title
	 * @param int $ns Page namespace
	 * @param int $notificationTimestamp
	 * @return stdClass[]
	 */
	public function getDeletionLog( $title, int $ns, int $notificationTimestamp ) {
		$dbr = WatchAnalyticsUtils::getReadDB();

		// pages are deleted when (a) they are explicitly deleted or (b) they
		// are moved without leaving a redirect behind.
		$logResults = $dbr->select(
			[ 'l' => 'logging', 'c' => 'comment' ],
			[
				'l.log_id',
				'l.log_type',
				'l.log_action',
				'l.log_timestamp',
				'l.log_actor',
				'l.log_namespace',
				'l.log_title',
				'l.log_page',
				'l.log_comment_id',
				'l.log_params',
				'l.log_deleted',
				'c.comment_id',
				'c.comment_text AS log_comment'
			],
			[
				'l.log_title' => $title,
				'l.log_namespace' => $ns,
				"l.log_timestamp >= $notificationTimestamp",
				'l.log_type' => [ 'delete', 'move' ]
			],
			__METHOD__,
			[ 'ORDER BY' => 'l.log_timestamp ASC' ],
			[ 'c' => [ 'INNER JOIN', [ 'l.log_comment_id=c.comment_id' ] ] ]
		);

		$logDeletes = [];
		while ( $log = $logResults->fetchObject() ) {
			$logDeletes[] = $log;
		}

		return $logDeletes;
	}

	public static function getMoveTarget( $logParams ) {
		// FIXME: This was copied from LogEntry::getParameters() because
		// I couldn't find a cleaner way to do it.
		// $logParams the content of the column log_params in the logging table

		Wikimedia\AtEase\AtEase::suppressWarnings();
		$unserializedParams = unserialize( $logParams );
		Wikimedia\AtEase\AtEase::restoreWarnings();
		if ( $unserializedParams !== false ) {
			$moveLogParams = $unserializedParams;

			// for some reason this serialized array is in the form:
			// Array( "4::target" => FULLPAGENAME, "5::noredir" => 1 )
			return $moveLogParams[ '4::target' ];

		} else {
			$moveLogParams = $logParams === '' ? [] : explode( "\n", $logParams );

			return $moveLogParams[0];
		}
	}
}
