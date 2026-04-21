<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class WatchAnalyticsUtils {

	/**
	 * Provides database for read access
	 *
	 * @return IReadableDatabase
	 */
	public static function getReadDB() {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getReplicaDatabase();
	}

	/**
	 * Provides database for write access
	 *
	 * @return IDatabase
	 */
	public static function getWriteDB() {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getPrimaryDatabase();
	}
}
