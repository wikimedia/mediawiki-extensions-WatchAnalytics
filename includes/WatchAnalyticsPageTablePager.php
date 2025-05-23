<?php

use MediaWiki\MediawikiServices;
use MediaWiki\Title\Title;

class WatchAnalyticsPageTablePager extends WatchAnalyticsTablePager {

	protected $mQueryNamespace;
	protected $isSortable = [
		'page_ns_and_title' => true,
		'num_watches' => true,
		'num_reviewed' => true,
		'percent_pending' => true,
		'max_pending_minutes' => true,
		'avg_pending_minutes' => true,
		'watch_quality' => true,
	];

	public function __construct( $page, $conds, $filters = [] ) {
		$req = $this->getRequest();

		$this->watchQuery = new PageWatchesQuery();

		parent::__construct( $page, $conds, $filters );

		$sortField = $req->getVal( 'sort' );
		$this->mQueryNamespace = $req->getVal( 'ns' );

		if ( !isset( $sortField ) ) {
			$this->mDefaultDirection = false;
		}

		$this->mExtraSortFields = [ 'num_watches', 'num_reviewed', 'page_ns_and_title' ];
	}

	public function getQueryInfo() {
		$services = MediawikiServices::getInstance();
		$namespaces = $services->getNamespaceInfo()->getCanonicalNamespaces();

		if ( $this->mQueryNamespace !== null
			&& $this->mQueryNamespace >= 0
			&& isset( $namespaces[ $this->mQueryNamespace ] ) ) {

			$conds = [
				'p.page_namespace = ' . $this->mQueryNamespace
			];
		} else {
			$conds = [];
		}
		return $this->watchQuery->getQueryInfo( $conds );
	}

	public function formatValue( $fieldName, $value ) {
		if ( $fieldName === 'page_ns_and_title' ) {
			$pageInfo = explode( ':', $value, 2 );
			$pageNsIndex = $pageInfo[0];
			$pageTitleText = $pageInfo[1];

			$title = Title::makeTitle( $pageNsIndex, $pageTitleText );

			$titleURL = $title->getLinkURL();
			$titleNsText = $title->getNsText();
			if ( $titleNsText === '' ) {
				$titleFullText = $title->getText();
			} else {
				$titleFullText = $titleNsText . ':' . $title->getText();
			}

			$pageLink = Xml::element(
				'a',
				[ 'href' => $titleURL ],
				$titleFullText
			);

			// FIXME: page stats not currently enabled. Uncomment when enabled
			$url = SpecialPage::getTitleFor( 'PageStatistics' )->getInternalURL( [
				'page' => $title->getPrefixedText()
			] );
			$pageStatsLink = Xml::element(
				'a',
				[ 'href' => $url ],
				wfMessage( 'watchanalytics-view-page-stats' )->text()
			);

			$pageLink .= ' <small>(' . $pageStatsLink . ' | ' . WatchSuggest::getWatchLink( $title ) . ')</small>';

			return $pageLink;
		} elseif ( $fieldName === 'max_pending_minutes' || $fieldName === 'avg_pending_minutes' ) {
			return ( $value === null ) ? null : $this->watchQuery->createTimeStringFromMinutes( $value );
		} else {
			return $value;
		}
	}

	public function getFieldNames() {
		return $this->watchQuery->getFieldNames();
	}

	public function getDefaultSort() {
		return 'num_reviewed';
	}

}
