<?php

namespace Wikibase\Import;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Schema, group grants and the sidebar link.
 */
class Hooks {

	/**
	 * Grant wikibaseimport to the groups in $wgWBImportGroups.
	 * The list is read here, after LocalSettings has been applied, so it can be changed
	 * in configuration before or after wfLoadExtension().
	 *
	 * @param array $credits
	 * @param SettingsBuilder $settings
	 */
	public static function onRegistration( array $credits, SettingsBuilder $settings ) {
		unset( $credits );
		global $wgWBImportGroups;
		$groups = is_array( $wgWBImportGroups ?? null ) ? $wgWBImportGroups : [ 'sysop', 'bureaucrat' ];
		$grant = [];
		foreach ( $groups as $group ) {
			if ( !is_string( $group ) || $group === '' || $group === '*' ) {
				continue;
			}
			$grant[$group] = [ 'wikibaseimport' => true ];
		}
		if ( $grant !== [] ) {
			$settings->putConfigValue( MainConfigNames::GroupPermissions, $grant );
		}
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'wbs_entity_mapping',
			__DIR__ . '/../sql/entity_mapping.sql'
		);
	}

	/**
	 * Link in the left navigation for accounts that hold wikibaseimport.
	 *
	 * @param \MediaWiki\Skin\Skin $skin
	 * @param array &$sidebar
	 */
	public static function onSidebarBeforeOutput( $skin, &$sidebar ) {
		if ( !$skin->getAuthority()->isAllowed( 'wikibaseimport' ) ) {
			return;
		}

		$title = SpecialPage::getTitleFor( 'WikibaseImport' );
		if ( !isset( $sidebar['navigation'] ) || !is_array( $sidebar['navigation'] ) ) {
			$sidebar['navigation'] = [];
		}
		foreach ( $sidebar['navigation'] as $existing ) {
			if ( ( $existing['id'] ?? '' ) === 'n-wikibaseimport' ) {
				return;
			}
		}

		$current = $skin->getTitle();
		$sidebar['navigation'][] = [
			'text' => $skin->msg( 'wikibaseimport-sidebar' )->text(),
			'href' => $title->getLocalURL(),
			'id' => 'n-wikibaseimport',
			'icon' => 'download',
			'active' => $current && $title->equals( $current ),
		];
	}

}
