<?php

namespace Wikibase\Import\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Wikibase\Import\Console\ImportOptions;
use Wikibase\Import\EntityId\EntityIdListBuilderFactory;
use Wikibase\Import\EntityImporterFactory;
use Wikibase\Import\LoggerFactory;
use Wikibase\Import\PropertyIdLister;
use Wikibase\Import\QueryRunner;
use Wikibase\Import\SourceHttp;
use Wikibase\Repo\WikibaseRepo;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ImportEntities extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Import entities from another Wikibase (Wikidata by default). Labels, descriptions and aliases are copied. Statements are copied only with --statements.'
		);
		$this->addOption( 'file', 'File with one source entity id per line', false, true );
		$this->addOption( 'entity', 'Source entity id to import, for example Q64', false, true );
		$this->addOption( 'query', 'Items with a property:entity value, for example P31:Q515', false, true );
		$this->addOption( 'range', 'Inclusive item id range, for example Q1:Q20', false, true );
		$this->addOption( 'stdin', 'Read source entity ids from standard input, one per line' );
		$this->addOption( 'all-properties', 'Import every property from the source wiki' );
		$this->addOption( 'dry-run', 'Fetch and print the plan, including the user that would own the edits. Does not create entities or mapping rows.' );
		$this->addOption( 'statements', 'Also copy statements of the requested entities. Without this flag only labels, descriptions and aliases are imported.' );
		$this->addOption( 'sitelinks', 'Keep source sitelinks. They are dropped unless this flag or $wgWBImportSitelinks is set.' );
		$this->addOption( 'user', 'Existing local account that owns the edits. Checked in dry-run too. Default is $wgWBImportUser.', false, true );
		$this->requireExtension( 'WikibaseImportExtended' );
	}

	public function execute() {
		$dryRun = $this->hasOption( 'dry-run' );
		$logger = LoggerFactory::newLogger( 'wikibase-import', $this->isQuiet() );
		$config = $this->getConfig();
		$services = MediaWikiServices::getInstance();

		$selected = null;
		foreach ( $this->getValidOptions() as $option ) {
			if ( $this->hasOption( $option ) ) {
				$selected = $option;
				break;
			}
		}

		if ( $selected === null ) {
			$this->maybeHelp( true );
		}

		$importSitelinks = $this->hasOption( 'sitelinks' ) || (bool)$config->get( 'WBImportSitelinks' );
		$importStatements = $this->hasOption( 'statements' ) || (bool)$config->get( 'WBImportStatements' );
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$mappingReady = $db->tableExists( 'wbs_entity_mapping', __METHOD__ );

		if ( !$mappingReady && !$dryRun ) {
			$this->fatalError(
				"Table wbs_entity_mapping does not exist. From the MediaWiki root run:\n"
				. "php maintenance/update.php --quick\n"
			);
		}

		if ( $dryRun ) {
			$this->output( "Dry-run: no entities or mapping rows will be written.\n" );
			if ( !$mappingReady ) {
				$this->output( "Mapping table is missing. Already-imported checks are skipped until update.php has run.\n" );
			}
		}

		$user = $this->resolveUser( (string)$this->getOption( 'user', $config->get( 'WBImportUser' ) ) );
		$this->assertUserCanImport( $user );
		$botFlag = 0;
		if ( $services->getPermissionManager()->userHasRight( $user, 'bot' ) ) {
			$botFlag = EDIT_FORCE_BOT;
		}
		$botNote = $botFlag ? ' (bot)' : '';
		if ( $dryRun ) {
			$this->output( 'Would import as ' . $user->getName() . $botNote . "\n" );
		} else {
			$this->output( 'Importing as ' . $user->getName() . $botNote . "\n" );
		}
		if ( $importStatements ) {
			$this->output( "Statements will be copied for the requested entities.\n" );
		} else {
			$this->output( "Statements are not imported. Pass --statements to copy them.\n" );
		}

		$http = new SourceHttp( $services->getHttpRequestFactory(), (string)$config->get( 'WBImportUserAgent' ) );
		$options = [];
		foreach ( $this->getValidOptions() as $optionName ) {
			$options[$optionName] = $this->getOption( $optionName );
		}
		$importOptions = new ImportOptions( $options );
		$input = $selected === 'all-properties' ? 'all-properties' : $importOptions->getOption( $selected );

		try {
			$ids = ( new EntityIdListBuilderFactory(
				WikibaseRepo::getEntityIdParser(),
				new PropertyIdLister( $http, (int)$config->get( 'WBImportSourcePropertyNamespace' ) ),
				new QueryRunner( $http, (string)$config->get( 'WBImportQueryUrl' ), $config->get( 'WBImportQueryPrefixes' ) ),
				(string)$config->get( 'WBImportSourceApi' )
			) )->newEntityIdListBuilder( $selected )->getEntityIds( $input );
		} catch ( \Throwable $ex ) {
			$this->fatalError( $ex->getMessage() . "\n" );
		}

		$ids = array_values( array_filter( array_map( 'strval', $ids ), static function ( $id ) {
			return trim( $id ) !== '';
		} ) );

		if ( $ids === [] ) {
			$this->fatalError( "No entity ids to import.\n" );
		}

		$this->output( 'Source ids: ' . count( $ids ) . "\n" );
		if ( !$importSitelinks ) {
			$this->output( "Sitelinks from the source wiki are dropped. Pass --sitelinks to keep them.\n" );
		}

		$importer = ( new EntityImporterFactory(
			WikibaseRepo::getStore()->getEntityStore(),
			WikibaseRepo::getStore(),
			$services->getConnectionProvider(),
			WikibaseRepo::getEntityIdParser(),
			$logger,
			$http,
			(string)$config->get( 'WBImportSourceApi' ),
			$user,
			$dryRun,
			$importSitelinks,
			$mappingReady,
			$botFlag
		) )->newEntityImporter();

		try {
			$importer->importEntities( $ids, $importStatements );
		} catch ( \Throwable $ex ) {
			$logger->error( $ex->getMessage() );
			$this->fatalError( $ex->getMessage() . "\n" );
		}

		$logger->info( $dryRun ? 'Dry-run finished' : 'Done' );
	}

	/**
	 * @return string[]
	 */
	private function getValidOptions() {
		return [ 'entity', 'file', 'all-properties', 'query', 'range', 'stdin' ];
	}

	/**
	 * @param string $name
	 * @return User
	 */
	private function resolveUser( $name ) {
		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $name );
		if ( !( $user instanceof User ) ) {
			$this->fatalError( "User name '$name' is not valid.\n" );
		}

		$user->load();
		if ( !$user->isRegistered() ) {
			$this->fatalError(
				"User '$name' does not exist.\n"
				. "Create it, then add it to the bot or sysop group (property-create is required):\n"
				. "php maintenance/createAndPromote.php " . $name . " 'choose-a-password'\n"
			);
		}

		return $user;
	}

	/**
	 * @param User $user
	 */
	private function assertUserCanImport( User $user ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$missing = [];
		foreach ( [ 'edit', 'createpage', 'property-create' ] as $right ) {
			if ( !$permissionManager->userHasRight( $user, $right ) ) {
				$missing[] = $right;
			}
		}

		if ( $missing !== [] ) {
			$this->fatalError(
				"User '" . $user->getName() . "' lacks: " . implode( ', ', $missing ) . ".\n"
				. "On this wiki property-create is granted to the bot, sysop and bureaucrat groups.\n"
			);
		}
	}

}

$maintClass = ImportEntities::class;
require_once RUN_MAINTENANCE_IF_MAIN;
