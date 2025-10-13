<?php

use MediaWiki\Category\Category;

class WatchAnalyticsParserFunctions {

	public static function setup( &$parser ) {
		$parser->setFunctionHook(
			'underwatched_categories',
			[
				'WatchAnalyticsParserFunctions', // class to call function from
				'renderUnderwatchedCategories' // function to call within that class
			],
			SFH_OBJECT_ARGS
		);

		return true;
	}

	public static function processArgs( $frame, $args, $defaults ) {
		$new_args = [];
		$num_args = count( $args );
		$num_defaults = count( $defaults );
		$count = ( $num_args > $num_defaults ) ? $num_args : $num_defaults;

		for ( $i = 0; $i < $count; $i++ ) {
			if ( isset( $args[$i] ) ) {
				$new_args[$i] = trim( $frame->expand( $args[$i] ) );
			} else {
				$new_args[$i] = $defaults[$i];
			}
		}
		return $new_args;
	}

	public static function renderUnderwatchedCategories( &$parser, $frame, $args ) {
		// @TODO: currently these do nothing. The namespace arg needs to be text
		// provided by the user, so this method needs to convert "Main" to zero, etc
		// $args = self::processArgs( $frame, $args, array(0) );
		// $namespace  = $args[0];

		$dbr = WatchAnalyticsUtils::getReadDB();

		// MW 1.45+
		$useTargetID = !$dbr->fieldExists( 'categorylinks', 'cl_to' );
		if ( $useTargetID ) {
			$categoriesSubquery = "SELECT group_concat(lt_title SEPARATOR ';') as subq_categories " .
				"FROM categorylinks JOIN linktarget ON cl_target_id = lt_id WHERE cl_from = p.page_id";
		} else {
			$categoriesSubquery = "SELECT group_concat(cl_to SEPARATOR ';') as subq_categories FROM categorylinks WHERE cl_from = p.page_id";
		}

		$query = "
			SELECT * FROM (
				SELECT
					p.page_namespace,
					p.page_title,
					SUM(IF(w.wl_title IS NOT NULL, 1, 0)) AS num_watches,
					SUM(IF(w.wl_title IS NOT NULL AND w.wl_notificationtimestamp IS NULL, 1, 0)) AS num_reviewed,
					SUM(IF(w.wl_title IS NOT NULL AND w.wl_notificationtimestamp IS NULL, 0, 1)) * 100 / COUNT(*) AS percent_pending,
					MAX(TIMESTAMPDIFF(MINUTE, w.wl_notificationtimestamp, UTC_TIMESTAMP())) AS max_pending_minutes,
					AVG(TIMESTAMPDIFF(MINUTE, w.wl_notificationtimestamp, UTC_TIMESTAMP())) AS avg_pending_minutes,
					($categoriesSubquery) AS categories
				FROM `watchlist` `w`
				RIGHT JOIN `page` `p` ON ((p.page_namespace=w.wl_namespace AND p.page_title=w.wl_title))
				WHERE
					p.page_namespace = 0
					AND p.page_is_redirect = 0
				GROUP BY p.page_title, p.page_namespace
				ORDER BY num_watches, num_reviewed
			) tmp
			WHERE num_watches < 2";

		// phpcs:ignore MediaWiki.Usage.DbrQueryUsage.DbrQueryFound
		$result = $dbr->query( $query );

		$output = "{| class=\"wikitable sortable\"\n";
		$output .= "! Category !! Number of Under-watched pages\n";

		$categories = [];
		while ( $row = $result->fetchObject() ) {
			if ( $row->categories == null ) {
				continue;
			}
			$pageCategories = explode( ';', $row->categories );

			foreach ( $pageCategories as $cat ) {
				if ( isset( $categories[ $cat ] ) ) {
					$categories[ $cat ]++;
				} else {
					$categories[ $cat ] = 1;
				}
			}
		}

		arsort( $categories );

		foreach ( $categories as $cat => $numUnderwatchedPages ) {

			if ( $cat === '' ) {
				$catLink = "''Uncategorized''";
			} else {
				$catTitle = Category::newFromName( $cat )->getTitle();
				$catLink = "[[:$catTitle|" . $catTitle->getText() . "]]";
			}

			$output .= "|-\n";
			$output .= "| $catLink || $numUnderwatchedPages\n";
		}

		$output .= '|}[[Category:Pages using beta WatchAnalytics features]]';

		return $output;
	}
}
