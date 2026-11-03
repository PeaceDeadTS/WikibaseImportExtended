<?php

namespace Wikibase\Import;

use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Statement\StatementListProvider;
use Wikibase\Import\Store\ImportedEntityMappingStore;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Lib\Store\LookupConstants;

class StatementsImporter {

	/**
	 * @var StatementCopier
	 */
	private $statementCopier;

	/**
	 * @var ImportedEntityMappingStore
	 */
	private $entityMappingStore;

	/**
	 * @var EntityRevisionLookup
	 */
	private $revisionLookup;

	/**
	 * @var EntityStore
	 */
	private $entityStore;

	/**
	 * @var \MediaWiki\User\User|null
	 */
	private $user;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var bool
	 */
	private $dryRun;

	/**
	 * @var int
	 */
	private $botFlag;

	/**
	 * @param StatementCopier $statementCopier
	 * @param ImportedEntityMappingStore $entityMappingStore
	 * @param EntityRevisionLookup $revisionLookup
	 * @param EntityStore $entityStore
	 * @param \MediaWiki\User\User|null $user
	 * @param LoggerInterface $logger
	 * @param bool $dryRun
	 * @param int $botFlag EDIT_FORCE_BOT or 0
	 */
	public function __construct(
		StatementCopier $statementCopier,
		ImportedEntityMappingStore $entityMappingStore,
		EntityRevisionLookup $revisionLookup,
		EntityStore $entityStore,
		$user,
		LoggerInterface $logger,
		$dryRun,
		$botFlag
	) {
		$this->statementCopier = $statementCopier;
		$this->entityMappingStore = $entityMappingStore;
		$this->revisionLookup = $revisionLookup;
		$this->entityStore = $entityStore;
		$this->user = $user;
		$this->logger = $logger;
		$this->dryRun = (bool)$dryRun;
		$this->botFlag = (int)$botFlag;
	}

	/**
	 * @param EntityDocument $entity Source entity, still carrying its original id and statements.
	 */
	public function importStatements( EntityDocument $entity ) {
		if ( !$entity instanceof StatementListProvider || $entity->getId() === null ) {
			return;
		}

		$sourceId = $entity->getId();
		$this->logger->info( 'Adding statements: ' . $sourceId->getSerialization() );

		$statements = $entity->getStatements();
		if ( $statements->isEmpty() ) {
			return;
		}

		$localId = $this->entityMappingStore->getLocalId( $sourceId );
		if ( $localId === null ) {
			$this->logger->error( $sourceId->getSerialization() . ' not found in the mapping table' );
			return;
		}

		$copied = new StatementList();
		$skipped = 0;
		foreach ( $statements as $statement ) {
			$newStatement = $this->statementCopier->copy( $statement );
			if ( $newStatement === null ) {
				$skipped++;
				continue;
			}
			$copied->addStatement( $newStatement );
		}

		if ( $this->dryRun ) {
			$this->logger->info( sprintf(
				'DRY-RUN %s would write %d statements, skip %d',
				$sourceId->getSerialization(),
				$copied->count(),
				$skipped
			) );
			return;
		}

		if ( $copied->isEmpty() ) {
			$this->logger->warning(
				'No statements could be mapped for ' . $sourceId->getSerialization()
			);
			return;
		}

		if ( $this->user === null ) {
			throw new \RuntimeException( 'Import user is required when dry-run is off' );
		}

		try {
			$this->saveStatements( $localId, $copied, $sourceId );
		} catch ( \Throwable $ex ) {
			$this->logger->error(
				'Failed to save statements for ' . $sourceId->getSerialization() . ': ' . $ex->getMessage()
			);
		}

		if ( $skipped > 0 ) {
			$this->logger->info( sprintf(
				'%s: saved %d statements, skipped %d',
				$sourceId->getSerialization(),
				$copied->count(),
				$skipped
			) );
		}
	}

	/**
	 * Add source statements onto an existing local entity. Nothing is saved here.
	 * A statement is added only when no local statement has the same property, main snak
	 * and rank. Qualifiers and references are merged onto that equivalent statement.
	 * Local statements are never removed.
	 *
	 * @param EntityDocument $source Source entity, still carrying its original statements.
	 * @param StatementListProvider $local Local entity to mutate.
	 * @return array{added:int,merged:int,skipped:int}
	 */
	public function mergeOnto( EntityDocument $source, StatementListProvider $local ) {
		$added = 0;
		$merged = 0;
		$skipped = 0;
		if ( !$source instanceof StatementListProvider || $source->getId() === null ) {
			return [
				'added' => 0,
				'merged' => 0,
				'skipped' => 0,
			];
		}

		foreach ( $source->getStatements() as $statement ) {
			$copied = $this->statementCopier->copy( $statement );
			if ( $copied === null ) {
				$skipped++;
				continue;
			}

			$existing = $this->findEquivalent( $local->getStatements(), $copied );
			if ( $existing === null ) {
				$local->getStatements()->addStatement( $copied );
				$added++;
				continue;
			}

			if ( $this->mergeQualifiersAndReferences( $existing, $copied ) ) {
				$merged++;
			}
		}

		return [
			'added' => $added,
			'merged' => $merged,
			'skipped' => $skipped,
		];
	}

	/**
	 * Same property, same main snak and same rank. Rank is part of the key because
	 * the copier keeps it on the statement.
	 *
	 * @param StatementList $statements
	 * @param Statement $candidate
	 * @return Statement|null
	 */
	private function findEquivalent( StatementList $statements, Statement $candidate ) {
		foreach ( $statements->getByPropertyId( $candidate->getPropertyId() ) as $existing ) {
			if ( $existing->getRank() !== $candidate->getRank() ) {
				continue;
			}
			if ( $existing->getMainSnak()->equals( $candidate->getMainSnak() ) ) {
				return $existing;
			}
		}

		return null;
	}

	/**
	 * @param Statement $local
	 * @param Statement $incoming
	 * @return bool True when a qualifier or reference was added.
	 */
	private function mergeQualifiersAndReferences( Statement $local, Statement $incoming ) {
		$changed = false;
		foreach ( $incoming->getQualifiers() as $snak ) {
			if ( $local->getQualifiers()->addSnak( $snak ) ) {
				$changed = true;
			}
		}
		foreach ( $incoming->getReferences() as $reference ) {
			if ( $this->hasSameReference( $local->getReferences(), $reference ) ) {
				continue;
			}
			$local->getReferences()->addReference( $reference );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * ReferenceList identity is the object hash, so compare contents.
	 *
	 * @param ReferenceList $references
	 * @param Reference $reference
	 * @return bool
	 */
	private function hasSameReference( ReferenceList $references, Reference $reference ) {
		foreach ( $references as $existing ) {
			if ( $existing->equals( $reference ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param EntityId $localId
	 * @param StatementList $statements
	 * @param EntityId $sourceId
	 */
	private function saveStatements( EntityId $localId, StatementList $statements, EntityId $sourceId ) {
		$groups = [];
		foreach ( $statements as $statement ) {
			$groups[$statement->getPropertyId()->getSerialization()][] = $statement;
		}

		try {
			$this->writeStatements( $localId, $statements, $sourceId );
			return;
		} catch ( \Throwable $ex ) {
			$this->logger->warning(
				'Saving all statements for ' . $sourceId->getSerialization()
				. ' failed (' . $ex->getMessage() . '). Retrying one property at a time.'
			);
		}

		foreach ( $groups as $propertyId => $group ) {
			$list = new StatementList( ...array_values( $group ) );
			try {
				$this->writeStatements( $localId, $list, $sourceId, true );
			} catch ( \Throwable $ex ) {
				$this->logger->error(
					"Skipped statements using $propertyId on "
					. $sourceId->getSerialization() . ': ' . $ex->getMessage()
				);
			}
		}
	}

	/**
	 * @param EntityId $localId
	 * @param StatementList $statements
	 * @param EntityId $sourceId
	 * @param bool $append When true, keep statements already stored and add these.
	 */
	private function writeStatements(
		EntityId $localId,
		StatementList $statements,
		EntityId $sourceId,
		$append = false
	) {
		$revision = $this->revisionLookup->getEntityRevision(
			$localId,
			0,
			LookupConstants::LATEST_FROM_MASTER
		);
		if ( $revision === null ) {
			throw new \RuntimeException( 'Local entity ' . $localId->getSerialization() . ' disappeared' );
		}

		$local = $revision->getEntity();
		if ( !$local instanceof StatementListProvider ) {
			throw new \RuntimeException( 'Local entity cannot store statements' );
		}

		if ( $append ) {
			foreach ( $statements as $statement ) {
				$local->getStatements()->addStatement( $statement );
			}
		} elseif ( method_exists( $local, 'setStatements' ) ) {
			$local->setStatements( $statements );
		} else {
			$local->getStatements()->clear();
			foreach ( $statements as $statement ) {
				$local->getStatements()->addStatement( $statement );
			}
		}

		$this->entityStore->saveEntity(
			$local,
			'Import statements from ' . $sourceId->getSerialization(),
			$this->user,
			EDIT_UPDATE | $this->botFlag,
			$revision->getRevisionId()
		);
	}

}
