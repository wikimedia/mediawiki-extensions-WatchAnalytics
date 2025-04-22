<?php

use MediaWiki\MediaWikiServices;

class WatchAnalyticsUtils {

	/**
	 * Provides database for read access
	 *
	 * @return Database
	 */
	public static function getReadDB() {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getReplicaDatabase();
	}

	/**
	 * Provides database for write access
	 *
	 * @return Database
	 */
	public static function getWriteDB() {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getPrimaryDatabase();
	}
}
