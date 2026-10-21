<?php

namespace Wikibase\Import;

use MediaWiki\Installer\DatabaseUpdater;

/**
 * Extension schema hooks.
 */
class Hooks {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'wbs_entity_mapping',
			__DIR__ . '/../sql/entity_mapping.sql'
		);
	}

}
