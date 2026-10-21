<?php

namespace Wikibase\Import\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikibase\Import\ImportJob;
use Wikibase\Import\ImportRunner;
use Wikibase\Import\ImportUserResolver;
use Wikibase\Import\LoggerFactory;

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
		$this->addOption( 'user', 'Existing local account that owns the edits. Checked in dry-run too. Default is $wgWBImportUser, or WikibaseImport when that setting is empty.', false, true );
		$this->requireExtension( 'WikibaseImportExtended' );
	}

	public function execute() {
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

		$config = $this->getConfig();
		$input = $selected === 'all-properties' ? 'all-properties' : $this->getOption( $selected );
		$job = new ImportJob(
			$selected,
			$input,
			ImportUserResolver::resolveUserName(
				$this->hasOption( 'user' ) ? $this->getOption( 'user' ) : '',
				$config->get( 'WBImportUser' )
			),
			$this->hasOption( 'dry-run' ),
			$this->hasOption( 'statements' ) || (bool)$config->get( 'WBImportStatements' ),
			$this->hasOption( 'sitelinks' ) || (bool)$config->get( 'WBImportSitelinks' )
		);
		$logger = LoggerFactory::newLogger( 'wikibase-import', $this->isQuiet() );
		$result = ( new ImportRunner() )->run( $job, $logger, function ( $line ) {
			$this->output( $line );
		} );
		if ( $result->fatal !== null ) {
			$this->fatalError( $result->fatal );
		}
	}

	/**
	 * @return string[]
	 */
	private function getValidOptions() {
		return [ 'entity', 'file', 'all-properties', 'query', 'range', 'stdin' ];
	}

}

$maintClass = ImportEntities::class;
require_once RUN_MAINTENANCE_IF_MAIN;
