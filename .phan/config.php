<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'],
	[
		// Suppress issue types that currently exist in the codebase.
		'PhanParamTooFewInPHPDoc',
		'PhanUndeclaredMethod'
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
