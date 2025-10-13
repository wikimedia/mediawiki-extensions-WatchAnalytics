<?php

class WatchesQuery {

	public $sqlMaxPendingMins = 'MAX( TIMESTAMPDIFF(MINUTE, w.wl_notificationtimestamp, UTC_TIMESTAMP()) ) AS max_pending_minutes';
	public $sqlAvgPendingMins = 'AVG( TIMESTAMPDIFF(MINUTE, w.wl_notificationtimestamp, UTC_TIMESTAMP()) ) AS avg_pending_minutes';
	public $tables;
	public $fields;
	public $join_conds;
	public $conds;
	public $options;

	/**
	 * @var int : maximum number of database rows to return
	 * @todo FIXME: who/what sets this?
	 * @example 20
	 */
	public $limit;

	/**
	 * @var int : used with $limit for pagination
	 * @todo FIXME: who/what sets this?
	 * @example 100
	 */
	public $offset;

	/**
	 * @var array : property declared in child classes to bind a MW
	 * message with a SQL database column
	 * @todo FIXME: where is this used?
	 * @example array( 'dbkey' => 'message-name', 'page_ns_and_title' => 'watchanalytics-special-header-page-title' )
	 */
	protected $fieldNames;

	/**
	 * @var string|bool : defines which user group to be used to
	 * filter for page-watches
	 * @example 'sysop'
	 */
	protected $userGroupFilter = false;

	/**
	 * @var string|bool : defines which category to be used to
	 * filter for page-watches
	 * @example 'Articles_with_unsourced_statements'
	 */
	protected $categoryFilter = false;

	public function __construct() {
	}

	/**
	 * @param int $totalMinutes
	 * @return string
	 */
	public function createTimeStringFromMinutes( $totalMinutes ) {
		$remainder = $totalMinutes;

		$minutesInDay = 60 * 24;
		$minutesInHour = 60;

		$days = floor( $remainder / $minutesInDay );
		if ( $days > 0 ) {
			return wfMessage( 'days', $days )->escaped();
		}

		$remainder %= $minutesInDay;
		$hours = floor( $remainder / $minutesInHour );
		if ( $hours > 0 ) {
			return wfMessage( 'hours', $hours )->escaped();
		}

		$remainder %= $minutesInHour;
		$minutes = $remainder;
		if ( $minutes > 0 ) {
			return wfMessage( 'minutes', $minutes )->escaped();
		}

		return '';
	}

	public function getFieldNames() {
		$output = [];

		foreach ( $this->fieldNames as $dbKey => $msg ) {
			$output[$dbKey] = wfMessage( $msg )->text();
		}

		return $output;
	}

	public function getQueryInfo() {
		if ( isset( $this->limit ) ) {
			$this->options['LIMIT'] = $this->limit;
		}
		if ( isset( $this->offset ) ) {
			$this->options['OFFSET'] = $this->offset;
		}

		return [
			'tables' => $this->tables,
			'fields' => $this->fields,
			'join_conds' => $this->join_conds,
			'conds' => $this->conds ?? [],
			'options' => $this->options,
		];
	}

	public function setUserGroupFilter( $ugf ) {
		$this->userGroupFilter = $ugf;
	}

	public function setCategoryFilter( $cf ) {
		$this->categoryFilter = $cf;
	}

	public function setCategoryFilterQueryInfo() {
		$dbr = WatchAnalyticsUtils::getReadDB();
		// MW 1.45+
		$useTargetID = !$dbr->fieldExists( 'categorylinks', 'cl_to' );

		$this->tables['cat'] = 'categorylinks';
		$this->join_conds['cat'] = [ 'RIGHT JOIN', 'cat.cl_from = p.page_id' ];
		if ( $useTargetID ) {
			$this->tables['lt'] = 'linktarget';
			$this->join_conds['lt'] = [ 'RIGHT JOIN', 'cat.cl_target_id = lt.id' ];
			$this->conds[] = 'lt.title = "' . $this->categoryFilter . '"';
		} else {
			$this->conds[] = 'cat.cl_to = "' . $this->categoryFilter . '"';
		}
	}

}
