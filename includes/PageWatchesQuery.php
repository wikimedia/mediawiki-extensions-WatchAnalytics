<?php

use MediaWiki\Title\Title;

class PageWatchesQuery extends WatchesQuery {

	public $sqlNsAndTitle = 'CONCAT(p.page_namespace, ":", p.page_title) AS page_ns_and_title';
	public $sqlNumWatches = 'SUM( IF(w.wl_title IS NOT NULL, 1, 0) ) AS num_watches';
	public $sqlNumReviewed = 'SUM( IF(w.wl_title IS NOT NULL AND w.wl_notificationtimestamp IS NULL, 1, 0) ) AS num_reviewed';
	public $sqlPercentPending =
		'SUM( IF(w.wl_title IS NOT NULL AND w.wl_notificationtimestamp IS NULL, 0, 1) ) * 100 / COUNT(*) AS percent_pending';
	public $sqlWatchQuality = 'SUM( user_watch_scores.engagement_score ) AS watch_quality';

	protected $fieldNames = [
		'page_ns_and_title'       => 'watchanalytics-special-header-page-title',
		'num_watches'             => 'watchanalytics-special-header-watches',
		'num_reviewed'            => 'watchanalytics-special-header-reviewed-watches',
		'percent_pending'         => 'watchanalytics-special-header-pending-percent',
		'max_pending_minutes'     => 'watchanalytics-special-header-pending-maxtime',
		'avg_pending_minutes'     => 'watchanalytics-special-header-pending-averagetime',
		'watch_quality'           => 'watchanalytics-special-header-watch-quality',
	];

	public function getQueryInfo( $conds = null ) {
		$this->fields = [
			$this->sqlNsAndTitle,
			$this->sqlNumWatches,
			$this->sqlNumReviewed,
			$this->sqlPercentPending,
			$this->sqlMaxPendingMins,
			$this->sqlAvgPendingMins,
		];

		$this->conds = $conds ?? [ 'p.page_namespace IS NOT NULL' ];

		$this->tables = [ 'w' => 'watchlist' ];

		$this->join_conds = [];

		// optionally join the 'user_groups' table to filter by user group
		if ( $this->userGroupFilter ) {
			$this->tables['ug'] = 'user_groups';
			$this->join_conds['ug'] = [
				'RIGHT JOIN', "w.wl_user = ug.ug_user AND ug.ug_group = \"{$this->userGroupFilter}\""
			];
		}

		// JOIN 'page' table
		$this->tables['p'] = 'page';
		$this->join_conds['p'] = [
			'RIGHT JOIN', 'p.page_namespace=w.wl_namespace AND p.page_title=w.wl_title'
		];

		// optionally join the 'categorylinks' table to filter by page category
		if ( $this->categoryFilter ) {
			$this->setCategoryFilterQueryInfo();
		}

		$this->options = [
			// 'GROUP BY' => 'w.wl_title, w.wl_namespace'
			'GROUP BY' => 'p.page_title, p.page_namespace',
		];

		return parent::getQueryInfo();
	}

	public function getPageWatchesAndViews( $pages ) {
		$dbr = WatchAnalyticsUtils::getReadDB();

		$pagesList = $dbr->makeList( $pages );
		if ( $pagesList == null ) {
			return [];
		}
		$queryInfo = $this->getQueryInfo( 'p.page_id IN (' . $pagesList . ')' );
		$queryInfo['options'][ 'ORDER BY' ] = 'num_watches ASC';

		$cols = [
			'MAX(p.page_id) AS page_id',
			$this->sqlNumWatches, // 'SUM( IF(w.wl_title IS NOT NULL, 1, 0) ) AS num_watches'
		];

		global $egWatchAnalyticsPageCounter;
		if ( $egWatchAnalyticsPageCounter ) {
			$queryInfo['tables']['counter'] = $egWatchAnalyticsPageCounter['table'];
			$countCol = $egWatchAnalyticsPageCounter['column'];
			$countPageIdJoinCol = $egWatchAnalyticsPageCounter['join_column'];

			$cols[] = "counter.$countCol AS num_views";
			$queryInfo['join_conds']['counter'] = [
				'LEFT JOIN', "p.page_id = counter.$countPageIdJoinCol"
			];
		}

		$pageWatchStats = $dbr->select(
			$queryInfo['tables'],
			$cols,
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);

		$return = [];
		while ( $row = $pageWatchStats->fetchObject() ) {
			if ( !isset( $row->num_views ) ) {
				$row->num_views = 1;
			}
			$return[] = $row;
		}

		return $return;
	}

	public function getPageWatchers( $titleKey, $ns = NS_MAIN ) {
		$dbr = WatchAnalyticsUtils::getReadDB();

		$pageWatchStats = $dbr->select(
			[ 'w' => 'watchlist' ],
			[ 'wl_user', 'wl_notificationtimestamp' ],
			[
				'w.wl_namespace' => $ns,
				'w.wl_title' => $titleKey,
			],
			__METHOD__
		);

		$return = [];
		while ( $row = $pageWatchStats->fetchObject() ) {
			$return[] = $row;
		}

		return $return;
	}

	private function createUserWatchScoresTempTable() {
		$dbw = WatchAnalyticsUtils::getWriteDB();

		$sql = <<<END
			CREATE TEMPORARY TABLE user_watch_scores
			AS SELECT
				wl_user AS user_name,
				(
					ROUND( IFNULL(
						EXP(
							-0.01 * SUM(
								IF(wl_notificationtimestamp IS NULL, 0, 1)
							)
						)
						*
						EXP(
							-0.01 * FLOOR(
								AVG(
									TIMESTAMPDIFF( DAY, wl_notificationtimestamp, UTC_TIMESTAMP() )
								)
							)
						),
					1), 3)
				) AS engagement_score

			FROM watchlist
			GROUP BY wl_user

END;

		$dbw->query( $sql, __METHOD__ );
	}

	public function getPageWatchQuality( Title $title ) {
		$dbr = WatchAnalyticsUtils::getReadDB();

		$this->createUserWatchScoresTempTable();

		$queryInfo = $this->getQueryInfo( [
			'p.page_namespace' => $title->getNamespace(),
			'p.page_title' => $title->getDBkey(),
		] );

		// add user watch scores join
		$queryInfo['tables']['user_watch_scores'] = 'user_watch_scores';
		$queryInfo['join_conds']['user_watch_scores'] = [
			'LEFT JOIN', 'user_watch_scores.user_name = w.wl_user'
		];

		$pageData = $dbr->selectRow(
			$queryInfo['tables'],
			[
				$this->sqlWatchQuality
			],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);

		// $row = $pageData->fetchObject();
		if ( $pageData && $pageData->watch_quality ) {
			return $pageData->watch_quality;
		} else {
			return 0;
		}
	}

}
