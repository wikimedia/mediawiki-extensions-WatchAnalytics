<?php

/**
 * @author Naresh Kumar
 */

use MediaWiki\MediaWikiServices;

class WatchAnalyticsUtils {

	/**
	 * Provides database for read access
	 *
	 * @return Database
	 */
	public static function getReadDB() {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		if ( method_exists( $lbFactory, 'getReplicaDatabase' ) ) {
			// MW 1.40+
			return $lbFactory->getReplicaDatabase();
		} else {
			return $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_REPLICA );
		}
	}

	/**
	 * Provides database for write access
	 *
	 * @return Database
	 */
	public static function getWriteDB() {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		if ( method_exists( $lbFactory, 'getPrimaryDatabase' ) ) {
			// MW 1.40+
			return $lbFactory->getPrimaryDatabase();
		} else {
			return $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );
		}
	}
}
