<?php

use MediaWiki\Title\Title;

class PageScore {

	/**
	 * @var bool : used to determine if a particular page should or
	 * should not include page scores. Assume true until magic word says otherwise.
	 */
	public static $displayPageScore = true;

	/**
	 * @var Title : reference to current title
	 */
	public $mTitle;

	/**
	 * @var array : names of classes used to color page score badges
	 */
	public $cssColorClasses;

	public function __construct( Title $title ) {
		$this->mTitle = $title;
	}

	public static function noPageScore() {
		self::$displayPageScore = false;
	}

	public static function pageScoreIsEnabled() {
		return self::$displayPageScore;
	}

	/**
	 * Handles something.
	 *
	 * @return string
	 */
	public function getWatchQuality() {
		$pwq = new PageWatchesQuery();
		return round( $pwq->getPageWatchQuality( $this->mTitle ), 1 );
	}

	public function getReviewStatus() {
		return $this->getNumReviews();
	}

	public function getNumReviews() {
		$dbr = WatchAnalyticsUtils::getReadDB();

		$pageData = $dbr->selectRow(
			'watchlist',
			'COUNT(*) AS num_reviews',
			[
				'wl_notificationtimestamp IS NULL',
				'wl_namespace' => $this->mTitle->getNamespace(),
				'wl_title' => $this->mTitle->getDBkey()
			],
			__METHOD__
		);

		return $pageData->num_reviews;
	}

	public function getScoreColor( $score, $configVariable ) {
		$scoreArr = $GLOBALS[ $configVariable ];
		krsort( $scoreArr, SORT_NUMERIC );

		foreach ( $scoreArr as $scoreThreshold => $style ) {
			if ( $score >= $scoreThreshold ) {
				return $style;
			}
		}
		return 'danger';
	}

	public function getPageScoreTemplate() {
		// simple explanation of what PageScores are
		$pageScoresTooltip = wfMessage( 'watch-analytics-page-score-tooltip' )->escaped();

		// @FIXME: Replace with special page showing page stats
		// $pageScoresHelpPageLink = Title::makeTitle( NS_HELP, "Page Scores" )->getInternalURL();
		$pageScoresHelpPageLink = SpecialPage::getTitleFor( 'PageStatistics' )->getInternalURL( [
			'page' => $this->mTitle->getPrefixedText()
		] );

		// @todo FIXME: replace this with a mustache template
		$template =
			"<a title='$pageScoresTooltip' id='ext-watchanalytics-pagescores' href='$pageScoresHelpPageLink'>"
				. $this->getScrutinyBadge()
				. $this->getReviewsBadge()
			. "</a>";

		return "<script type='text/template' id='ext-watchanalytics-pagescores-template'>$template</script>";
	}

	public function getBadge( $label, $score, $color, $showLabel = false ) {
		// @todo FIXME: make the javascript apply a class to handle this, so this can just apply a class
		if ( $showLabel ) {
			$leftStyle = " style='display:inherit; border-radius: 4px 0 0 4px;'";
			$rightStyle = " style='border-radius: 0 4px 4px 0;'";
		} else {
			$leftStyle = "";
			$rightStyle = "";
		}

		return "<div class='ext-watchanalytics-criticality-$color'>
				<div class='ext-watchanalytics-pagescores-left noprint'$leftStyle>
					$label
				</div>
				<div class='ext-watchanalytics-pagescores-right noprint'$rightStyle>
					$score
				</div>
			</div>";
	}

	public function getScrutinyBadge( $showLabel = false ) {
		$scrutinyScore = $this->getWatchQuality();
		$scrutinyLabel = wfMessage( 'watch-analytics-page-score-scrutiny-label' )->escaped();
		$scrutinyColor = $this->getScoreColor( $scrutinyScore, 'egWatchAnalyticsWatchQualityColors' );

		return $this->getBadge( $scrutinyLabel, $scrutinyScore, $scrutinyColor, $showLabel );
	}

	public function getReviewsBadge( $showLabel = false ) {
		$reviewsScore = $this->getReviewStatus();
		$reviewsLabel = wfMessage( 'watch-analytics-page-score-reviews-label' )->escaped();
		$reviewsColor = $this->getScoreColor( $reviewsScore, 'egWatchAnalyticsReviewStatusColors' );

		return $this->getBadge( $reviewsLabel, $reviewsScore, $reviewsColor, $showLabel );
	}

}
