<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'],
	[
		// Suppress issue types that currently exist in the codebase.
		// This means that Phan initially won't do much, but it allows for
		// checks to be incrementally fixed and enabled without massive changes.'
		'PhanImpossibleCondition',
		'PhanParamTooFewInPHPDoc',
		'PhanTypeMismatchArgumentNullable',
		'PhanUndeclaredMethod',
		'PhanUndeclaredTypeProperty',
		'MediaWikiNoIssetIfDefined',
		'MediaWikiNoEmptyIfDefined',
		'UnusedPluginSuppression'
	]
);

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/ApprovedRevs',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/ApprovedRevs',
	]
);

return $cfg;
