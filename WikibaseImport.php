<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'WikibaseImportExtended', __DIR__ . '/extension.json' );
	wfWarn(
		'Deprecated PHP entry point used for WikibaseImportExtended. ' .
		'Please use wfLoadExtension( \'WikibaseImportExtended\' ) instead.'
	);
} else {
	die( 'WikibaseImportExtended requires MediaWiki 1.44+.' );
}
