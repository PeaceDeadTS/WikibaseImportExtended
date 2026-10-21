<?php

namespace Wikibase\Import\Store;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikimedia\Rdbms\IConnectionProvider;

class DBImportedEntityMappingStore implements ImportedEntityMappingStore {

	/**
	 * @var IConnectionProvider
	 */
	private $dbProvider;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var bool
	 */
	private $dbEnabled;

	/**
	 * @var bool
	 */
	private $dryRun;

	/**
	 * @var array<string,EntityId|null>
	 */
	private $resolved = [];

	/**
	 * @var array<string,bool>
	 */
	private $persisted = [];

	/**
	 * @var array<string,EntityId>
	 */
	private $overlay = [];

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param EntityIdParser $entityIdParser
	 * @param bool $dbEnabled False when the mapping table does not exist yet (dry-run only).
	 * @param bool $dryRun When true, add() keeps the row in memory and does not write.
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		EntityIdParser $entityIdParser,
		$dbEnabled,
		$dryRun
	) {
		$this->dbProvider = $dbProvider;
		$this->entityIdParser = $entityIdParser;
		$this->dbEnabled = (bool)$dbEnabled;
		$this->dryRun = (bool)$dryRun;
	}

	/**
	 * @param EntityId $originalId
	 * @param EntityId $localId
	 */
	public function add( EntityId $originalId, EntityId $localId ) {
		$key = $originalId->getSerialization();

		if ( $this->dryRun ) {
			$this->overlay[$key] = $localId;
			$this->resolved[$key] = $localId;
			return;
		}

		$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'wbs_entity_mapping' )
			->row( [
				'wbs_local_id' => $localId->getSerialization(),
				'wbs_original_id' => $key,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->persisted[$key] = true;
		$this->resolved[$key] = $localId;
	}

	/**
	 * @param EntityId $originalId
	 * @return EntityId|null
	 */
	public function getLocalId( EntityId $originalId ) {
		$key = $originalId->getSerialization();
		if ( array_key_exists( $key, $this->resolved ) ) {
			return $this->resolved[$key];
		}

		$fromDb = $this->fetchLocalId( $key );
		if ( $fromDb !== null ) {
			$this->persisted[$key] = true;
			$this->resolved[$key] = $fromDb;
			return $fromDb;
		}

		if ( !array_key_exists( $key, $this->persisted ) ) {
			$this->persisted[$key] = false;
		}

		if ( isset( $this->overlay[$key] ) ) {
			$this->resolved[$key] = $this->overlay[$key];
			return $this->overlay[$key];
		}

		$this->resolved[$key] = null;
		return null;
	}

	/**
	 * True when the source id already has a row in the database.
	 * Dry-run overlays are not persisted.
	 *
	 * @param EntityId $originalId
	 * @return bool
	 */
	public function isPersisted( EntityId $originalId ) {
		$key = $originalId->getSerialization();
		if ( !array_key_exists( $key, $this->persisted ) ) {
			$this->getLocalId( $originalId );
		}

		return !empty( $this->persisted[$key] );
	}

	/**
	 * @param EntityId $localId
	 * @return EntityId|null
	 */
	public function getOriginalId( EntityId $localId ) {
		if ( !$this->dbEnabled ) {
			return null;
		}

		$row = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'wbs_original_id' )
			->from( 'wbs_entity_mapping' )
			->where( [ 'wbs_local_id' => $localId->getSerialization() ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false ) {
			return null;
		}

		return $this->entityIdParser->parse( $row->wbs_original_id );
	}

	/**
	 * @param string $originalSerialization
	 * @return EntityId|null
	 */
	private function fetchLocalId( $originalSerialization ) {
		if ( !$this->dbEnabled ) {
			return null;
		}

		$row = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'wbs_local_id' )
			->from( 'wbs_entity_mapping' )
			->where( [ 'wbs_original_id' => $originalSerialization ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false ) {
			return null;
		}

		return $this->entityIdParser->parse( $row->wbs_local_id );
	}

}
