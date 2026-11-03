<?php

namespace Wikibase\Import\Store;

use Wikibase\DataModel\Entity\EntityId;

interface ImportedEntityMappingStore {

	/**
	 * @param EntityId $originalId
	 * @param EntityId $localId
	 */
	public function add( EntityId $originalId, EntityId $localId );

	/**
	 * Remember a source-to-local id for this process without writing a row.
	 * Extend mode uses this so the named local id wins over the mapping table.
	 *
	 * @param EntityId $originalId
	 * @param EntityId $localId
	 */
	public function stage( EntityId $originalId, EntityId $localId );

	/**
	 * @param EntityId $originalId
	 *
	 * @return EntityId|null
	 */
	public function getLocalId( EntityId $originalId );

	/**
	 * True when this source id has a row in the database.
	 * In-memory dry-run mappings are not persisted.
	 *
	 * @param EntityId $originalId
	 * @return bool
	 */
	public function isPersisted( EntityId $originalId );

	/**
	 * @param EntityId $localId
	 *
	 * @return EntityId|null
	 */
	public function getOriginalId( EntityId $localId );

}
