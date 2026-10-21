<?php

namespace Wikibase\Import;

use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikibase\Import\EntityId\EntityIdListBuilderFactory;
use Wikibase\Repo\WikibaseRepo;

/**
 * Shared import path for the maintenance script and Special:WikibaseImport.
 * Header lines are the same strings the CLI prints, in the same order.
 */
class ImportRunner {

	/**
	 * @var ImportRunResult
	 */
	private $result;

	/**
	 * @var callable
	 */
	private $output;

	/**
	 * @param ImportJob $job
	 * @param LoggerInterface $logger Progress lines. The CLI passes StdoutLogger; the web UI passes BufferLogger.
	 * @param callable $output function( string $line ): void. $line includes the trailing newline.
	 * @return ImportRunResult
	 */
	public function run( ImportJob $job, LoggerInterface $logger, callable $output ) {
		$this->result = new ImportRunResult();
		if ( $logger instanceof BufferLogger ) {
			$logger->bind( $this->result );
		}
		$this->result->dryRun = $job->dryRun;
		$this->result->statements = $job->statements;
		$this->result->sitelinks = $job->sitelinks;
		$this->output = $output;

		$config = MediaWikiServices::getInstance()->getMainConfig();
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		$mappingReady = $db->tableExists( 'wbs_entity_mapping', __METHOD__ );

		if ( !$mappingReady && !$job->dryRun ) {
			$this->result->fatal = "Table wbs_entity_mapping does not exist. From the MediaWiki root run:\n"
				. "php maintenance/update.php --quick\n";
			return $this->finish();
		}

		if ( $job->dryRun ) {
			$this->emit( "Dry-run: no entities or mapping rows will be written.\n" );
			if ( !$mappingReady ) {
				$this->emit( "Mapping table is missing. Already-imported checks are skipped until update.php has run.\n" );
			}
		}

		$resolver = new ImportUserResolver();
		try {
			$user = $resolver->requireUser( $job->userName );
			$resolver->assertCanImport( $user );
		} catch ( ImportException $ex ) {
			$this->result->fatal = $ex->getMessage();
			return $this->finish();
		}

		$this->result->userName = $user->getName();
		$botFlag = 0;
		if ( $services->getPermissionManager()->userHasRight( $user, 'bot' ) ) {
			$botFlag = EDIT_FORCE_BOT;
		}
		$this->result->bot = $botFlag !== 0;
		$botNote = $botFlag ? ' (bot)' : '';
		if ( $job->dryRun ) {
			$this->emit( 'Would import as ' . $user->getName() . $botNote . "\n" );
		} else {
			$this->emit( 'Importing as ' . $user->getName() . $botNote . "\n" );
		}
		if ( $job->statements ) {
			$this->emit( "Statements will be copied for the requested entities.\n" );
		} else {
			$this->emit( "Statements are not imported. Pass --statements to copy them.\n" );
		}

		$http = new SourceHttp( $services->getHttpRequestFactory(), (string)$config->get( 'WBImportUserAgent' ) );

		try {
			$ids = ( new EntityIdListBuilderFactory(
				WikibaseRepo::getEntityIdParser(),
				new PropertyIdLister( $http, (int)$config->get( 'WBImportSourcePropertyNamespace' ) ),
				new QueryRunner( $http, (string)$config->get( 'WBImportQueryUrl' ), $config->get( 'WBImportQueryPrefixes' ) ),
				(string)$config->get( 'WBImportSourceApi' )
			) )->newEntityIdListBuilder( $job->mode )->getEntityIds( $job->input );
		} catch ( \Throwable $ex ) {
			$this->result->fatal = $ex->getMessage() . "\n";
			return $this->finish();
		}

		$ids = array_values( array_filter( array_map( 'strval', $ids ), static function ( $id ) {
			return trim( $id ) !== '';
		} ) );

		if ( $ids === [] ) {
			$this->result->fatal = "No entity ids to import.\n";
			return $this->finish();
		}

		$this->result->sourceCount = count( $ids );
		$this->emit( 'Source ids: ' . count( $ids ) . "\n" );
		if ( !$job->sitelinks ) {
			$this->emit( "Sitelinks from the source wiki are dropped. Pass --sitelinks to keep them.\n" );
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
			$job->dryRun,
			$job->sitelinks,
			$mappingReady,
			$botFlag
		) )->newEntityImporter();

		try {
			$importer->importEntities( $ids, $job->statements );
		} catch ( \Throwable $ex ) {
			$logger->error( $ex->getMessage() );
			$this->result->fatal = $ex->getMessage() . "\n";
			return $this->finish();
		}

		$logger->info( $job->dryRun ? 'Dry-run finished' : 'Done' );
		return $this->finish();
	}

	/**
	 * @param string $line Includes the trailing newline, matching Maintenance::output().
	 */
	private function emit( $line ) {
		$this->result->addOutput( rtrim( $line, "\n" ) );
		( $this->output )( $line );
	}

	/**
	 * @return ImportRunResult
	 */
	private function finish() {
		$this->result->ok = $this->result->fatal === null && !$this->result->hasError;
		$this->result->entities = ( new ImportPlanParser() )->parse( $this->result->lines );
		return $this->result;
	}

}
