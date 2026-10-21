<?php

namespace Wikibase\Import;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

/**
 * Default edit owner and the same account checks the maintenance script prints.
 */
class ImportUserResolver {

	/**
	 * Configured name, or WikibaseImport when the setting is empty.
	 *
	 * @param mixed $configured $wgWBImportUser
	 * @return string
	 */
	public static function configuredName( $configured ) {
		$name = trim( (string)$configured );
		return $name !== '' ? $name : 'WikibaseImport';
	}

	/**
	 * An explicit name wins. An empty request uses {@see configuredName()}.
	 *
	 * @param mixed $requested
	 * @param mixed $configured
	 * @return string
	 */
	public static function resolveUserName( $requested, $configured ) {
		$requested = trim( (string)$requested );
		if ( $requested !== '' ) {
			return $requested;
		}

		return self::configuredName( $configured );
	}

	/**
	 * @param string $name
	 * @return User
	 * @throws ImportException
	 */
	public function requireUser( $name ) {
		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $name );
		if ( !( $user instanceof User ) ) {
			throw new ImportException( "User name '$name' is not valid.\n" );
		}

		$user->load();
		if ( !$user->isRegistered() ) {
			throw new ImportException(
				"User '$name' does not exist.\n"
				. "Create it, then add it to the bot or sysop group (property-create is required):\n"
				. "php maintenance/createAndPromote.php " . $name . " 'choose-a-password'\n"
			);
		}

		return $user;
	}

	/**
	 * @param User $user
	 * @throws ImportException
	 */
	public function assertCanImport( User $user ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$missing = [];
		foreach ( [ 'edit', 'createpage', 'property-create' ] as $right ) {
			if ( !$permissionManager->userHasRight( $user, $right ) ) {
				$missing[] = $right;
			}
		}

		if ( $missing !== [] ) {
			throw new ImportException(
				"User '" . $user->getName() . "' lacks: " . implode( ', ', $missing ) . ".\n"
				. "On this wiki property-create is granted to the bot, sysop and bureaucrat groups.\n"
			);
		}
	}

}
