<?php

class WatchAnalyticsUpdaterHooks {

	public static function addSchemaUpdates( $updater ) {
		// NOTE: this SQL file adds tables watch_tracking_user,
		// watch_tracking_page and watch_tracking_wiki. Since no changes have
		// been made to the database schema over the life of this extension so
		// far, there's no reason to check for all the tables. Checking for the
		// existence of one is sufficient to determine if the tables need to be
		// created.

		// DB updates. The schema is defined using MediaWiki's abstract schema
		// system (sql/tables.json); the per-DBMS DDL files below are generated
		// from it with maintenance/generateSchemaSql.php and adapt automatically
		// to MySQL/MariaDB, SQLite and PostgreSQL.
		$dbType = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'watch_tracking_user',
			__DIR__ . "/../sql/$dbType/tables-generated.sql"
		);
	}

}
