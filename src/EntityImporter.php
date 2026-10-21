<?php

namespace Wikibase\Import;

use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\SiteLinkList;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Statement\StatementListProvider;
use Wikibase\DataModel\Term\FingerprintProvider;
use Wikibase\Import\Store\ImportedEntityMappingStore;
use Wikibase\Lib\DataTypeFactory;
use Wikibase\Lib\Store\EntityStore;

class EntityImporter {

	private const BATCH_SIZE = 50;

	/**
	 * @var StatementsImporter
	 */
	private $statementsImporter;

	/**
	 * @var BadgeItemUpdater
	 */
	private $badgeItemUpdater;

	/**
	 * @var ApiEntityLookup
	 */
	private $apiEntityLookup;

	/**
	 * @var EntityStore
	 */
	private $entityStore;

	/**
	 * @var ImportedEntityMappingStore
	 */
	private $entityMappingStore;

	/**
	 * @var StatementsCountLookup
	 */
	private $statementsCountLookup;

	/**
	 * @var TermSanitizer
	 */
	private $termSanitizer;

	/**
	 * @var DataTypeFactory
	 */
	private $dataTypeFactory;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var User|null
	 */
	private $importUser;

	/**
	 * @var bool
	 */
	private $dryRun;

	/**
	 * @var bool
	 */
	private $importSitelinks;

	/**
	 * @var int
	 */
	private $botFlag;

	/**
	 * @var array<string,true>
	 */
	private $seenFull = [];

	/**
	 * @var array<string,true>
	 */
	private $seenStub = [];

	/**
	 * @param StatementsImporter $statementsImporter
	 * @param BadgeItemUpdater $badgeItemUpdater
	 * @param ApiEntityLookup $apiEntityLookup
	 * @param EntityStore $entityStore
	 * @param ImportedEntityMappingStore $entityMappingStore
	 * @param StatementsCountLookup $statementsCountLookup
	 * @param TermSanitizer $termSanitizer
	 * @param DataTypeFactory $dataTypeFactory
	 * @param LoggerInterface $logger
	 * @param User|null $importUser Required unless $dryRun is true.
	 * @param bool $dryRun
	 * @param bool $importSitelinks
	 * @param int $botFlag
	 */
	public function __construct(
		StatementsImporter $statementsImporter,
		BadgeItemUpdater $badgeItemUpdater,
		ApiEntityLookup $apiEntityLookup,
		EntityStore $entityStore,
		ImportedEntityMappingStore $entityMappingStore,
		StatementsCountLookup $statementsCountLookup,
		TermSanitizer $termSanitizer,
		DataTypeFactory $dataTypeFactory,
		LoggerInterface $logger,
		$importUser,
		$dryRun,
		$importSitelinks,
		$botFlag
	) {
		$this->statementsImporter = $statementsImporter;
		$this->badgeItemUpdater = $badgeItemUpdater;
		$this->apiEntityLookup = $apiEntityLookup;
		$this->entityStore = $entityStore;
		$this->entityMappingStore = $entityMappingStore;
		$this->statementsCountLookup = $statementsCountLookup;
		$this->termSanitizer = $termSanitizer;
		$this->dataTypeFactory = $dataTypeFactory;
		$this->logger = $logger;
		$this->importUser = $importUser;
		$this->dryRun = (bool)$dryRun;
		$this->importSitelinks = (bool)$importSitelinks;
		$this->botFlag = (int)$botFlag;
	}

	/**
	 * @param string[] $ids
	 * @param bool $importStatements When true, copy statements of these ids after their references exist as stubs.
	 */
	public function importEntities( array $ids, $importStatements = false ) {
		$pending = [];
		foreach ( $ids as $id ) {
			$id = trim( (string)$id );
			if ( $id === '' ) {
				continue;
			}
			if ( $importStatements ) {
				if ( isset( $this->seenFull[$id] ) ) {
					continue;
				}
				$this->seenFull[$id] = true;
			} elseif ( isset( $this->seenFull[$id] ) || isset( $this->seenStub[$id] ) ) {
				continue;
			} else {
				$this->seenStub[$id] = true;
			}
			$pending[] = $id;
		}

		if ( $pending === [] ) {
			return;
		}

		$stashedEntities = [];
		foreach ( array_chunk( $pending, self::BATCH_SIZE ) as $batch ) {
			$this->logger->debug( 'Importing batch: ' . implode( ', ', $batch ) );
			$entities = $this->apiEntityLookup->getEntities( $batch );
			if ( $entities === [] ) {
				$this->logger->error( 'Failed to retrieve entities for batch: ' . implode( ', ', $batch ) );
				continue;
			}

			if ( $this->importSitelinks ) {
				$this->importBadgeItems( $entities );
			}

			foreach ( $entities as $entity ) {
				if ( $importStatements ) {
					$stashedEntities[] = $entity->copy();
				}
				$this->importStub( $entity, $importStatements );
			}
		}

		if ( $importStatements !== true ) {
			return;
		}

		foreach ( $stashedEntities as $entity ) {
			$referenced = $this->getReferencedEntities( $entity );
			if ( $referenced !== [] ) {
				$this->importEntities( $referenced, false );
			}

			$sourceId = $entity->getId();
			if ( $sourceId === null ) {
				continue;
			}

			$localId = $this->entityMappingStore->getLocalId( $sourceId );
			if ( $localId === null ) {
				$this->logger->error( 'No local id for ' . $sourceId->getSerialization() . ', statements skipped' );
				continue;
			}

			if (
				$this->entityMappingStore->isPersisted( $sourceId )
				&& $this->statementsCountLookup->hasStatements( $localId )
			) {
				$this->logger->info(
					'Statements already imported for ' . $sourceId->getSerialization()
					. ' (' . $localId->getSerialization() . ')'
				);
				continue;
			}

			$this->statementsImporter->importStatements( $entity );
		}
	}

	/**
	 * @param EntityDocument $entity
	 * @param bool $importStatements
	 */
	private function importStub( EntityDocument $entity, $importStatements ) {
		$sourceId = $entity->getId();
		if ( $sourceId === null ) {
			$this->logger->error( 'Source entity has no id' );
			return;
		}

		$serialization = $sourceId->getSerialization();
		$existing = $this->entityMappingStore->getLocalId( $sourceId );
		if ( $existing !== null && $this->entityMappingStore->isPersisted( $sourceId ) ) {
			$this->logger->info( "$serialization already imported as " . $existing->getSerialization() );
			return;
		}

		if ( $entity instanceof Property && !$this->dataTypeEnabled( $entity ) ) {
			return;
		}

		$statementCount = $entity instanceof StatementListProvider ? $entity->getStatements()->count() : 0;
		$droppedTerms = $this->termSanitizer->sanitize( $entity );
		$sitelinkCount = $entity instanceof Item ? $entity->getSiteLinkList()->count() : 0;

		if ( $entity instanceof StatementListProvider && method_exists( $entity, 'setStatements' ) ) {
			$entity->setStatements( new StatementList() );
		}

		if ( $entity instanceof Item ) {
			if ( $this->importSitelinks ) {
				$entity->setSiteLinkList( $this->badgeItemUpdater->replaceBadges( $entity->getSiteLinkList() ) );
			} else {
				$entity->setSiteLinkList( new SiteLinkList() );
			}
		}

		$label = $this->pickLabel( $entity );
		$summary = $this->describe(
			$entity,
			$serialization,
			$label,
			$droppedTerms,
			$sitelinkCount,
			$statementCount,
			$importStatements
		);

		if ( $this->dryRun ) {
			$this->logger->info( 'DRY-RUN ' . $summary );
			// Identity mapping lets statement copying count links without writing.
			$this->entityMappingStore->add( $sourceId, $sourceId );
			return;
		}

		if ( $this->importUser === null ) {
			throw new \RuntimeException( 'Import user is required when dry-run is off' );
		}

		$entity->setId( null );
		$this->logger->info( 'Creating ' . $summary );

		try {
			$revision = $this->saveNewEntity( $entity, $serialization );
		} catch ( \Throwable $ex ) {
			$this->logger->error( "Failed to add $serialization: " . $ex->getMessage() );
			return;
		}

		$localId = $revision->getEntity()->getId();
		if ( $localId === null ) {
			$this->logger->error( "Saved $serialization but the local id is empty" );
			return;
		}

		$this->logger->info( "$serialization created as " . $localId->getSerialization() );
		$this->entityMappingStore->add( $sourceId, $localId );
	}

	/**
	 * @param EntityDocument $entity
	 * @param string $sourceId
	 * @return \Wikibase\Lib\Store\EntityRevision
	 */
	private function saveNewEntity( EntityDocument $entity, $sourceId ) {
		$summary = 'Import entity ' . $sourceId;
		try {
			return $this->entityStore->saveEntity(
				$entity,
				$summary,
				$this->importUser,
				EDIT_NEW | $this->botFlag
			);
		} catch ( \Throwable $ex ) {
			if ( !( $entity instanceof Item ) || $entity->getSiteLinkList()->isEmpty() ) {
				throw $ex;
			}

			$this->logger->warning(
				"Saving $sourceId with sitelinks failed (" . $ex->getMessage() . '). Retrying without sitelinks.'
			);
			$entity->setSiteLinkList( new SiteLinkList() );

			return $this->entityStore->saveEntity(
				$entity,
				$summary,
				$this->importUser,
				EDIT_NEW | $this->botFlag
			);
		}
	}

	/**
	 * @param Property $property
	 * @return bool
	 */
	private function dataTypeEnabled( Property $property ) {
		$typeId = $property->getDataTypeId();
		if ( in_array( $typeId, $this->dataTypeFactory->getTypeIds(), true ) ) {
			return true;
		}

		$id = $property->getId() ? $property->getId()->getSerialization() : 'unknown';
		$this->logger->error( "Skip $id: datatype $typeId is not enabled on this wiki" );
		return false;
	}

	/**
	 * @param EntityDocument[] $entities
	 */
	private function importBadgeItems( array $entities ) {
		$badgeItems = [];
		foreach ( $entities as $entity ) {
			if ( !$entity instanceof Item ) {
				continue;
			}
			foreach ( $entity->getSiteLinkList()->toArray() as $siteLink ) {
				foreach ( $siteLink->getBadges() as $badge ) {
					$badgeItems[] = $badge->getSerialization();
				}
			}
		}

		if ( $badgeItems !== [] ) {
			$this->importEntities( $badgeItems, false );
		}
	}

	/**
	 * @param EntityDocument $entity
	 * @return string[]
	 */
	private function getReferencedEntities( EntityDocument $entity ) {
		if ( !$entity instanceof StatementListProvider ) {
			return [];
		}

		$ids = [];
		foreach ( $entity->getStatements()->getAllSnaks() as $snak ) {
			$ids[] = $snak->getPropertyId()->getSerialization();
			if ( $snak instanceof PropertyValueSnak ) {
				$value = $snak->getDataValue();
				if ( $value instanceof EntityIdValue ) {
					$ids[] = $value->getEntityId()->getSerialization();
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param EntityDocument $entity
	 * @return string
	 */
	private function pickLabel( EntityDocument $entity ) {
		if ( !$entity instanceof FingerprintProvider ) {
			return '';
		}

		$labels = $entity->getFingerprint()->getLabels();
		foreach ( [ 'en', 'ru', 'mul' ] as $language ) {
			if ( $labels->hasTermForLanguage( $language ) ) {
				return $this->oneLine( $labels->getByLanguage( $language )->getText() );
			}
		}

		foreach ( $labels as $term ) {
			return $this->oneLine( $term->getText() );
		}

		return '';
	}

	/**
	 * @param EntityDocument $entity
	 * @param string $serialization
	 * @param string $label
	 * @param int $droppedTerms
	 * @param int $sitelinkCount
	 * @param int $statementCount
	 * @param bool $importStatements
	 * @return string
	 */
	private function describe(
		EntityDocument $entity,
		$serialization,
		$label,
		$droppedTerms,
		$sitelinkCount,
		$statementCount,
		$importStatements
	) {
		$labels = 0;
		$descriptions = 0;
		$aliases = 0;
		if ( $entity instanceof FingerprintProvider ) {
			$fingerprint = $entity->getFingerprint();
			$labels = $fingerprint->getLabels()->count();
			$descriptions = $fingerprint->getDescriptions()->count();
			foreach ( $fingerprint->getAliasGroups() as $group ) {
				$aliases += count( $group );
			}
		}

		if ( $this->importSitelinks ) {
			$sitelinks = (string)$sitelinkCount;
		} else {
			$sitelinks = '0 (dropped ' . $sitelinkCount . ')';
		}

		$quoted = $label === '' ? '' : ' "' . $label . '"';
		if ( $importStatements ) {
			$statements = (string)$statementCount;
		} elseif ( $statementCount > 0 ) {
			$statements = '0 (source ' . $statementCount . ' not imported)';
		} else {
			$statements = '0';
		}

		return sprintf(
			'%s %s%s labels=%d descriptions=%d aliases=%d droppedTerms=%d statements=%s sitelinks=%s',
			$entity->getType(),
			$serialization,
			$quoted,
			$labels,
			$descriptions,
			$aliases,
			$droppedTerms,
			$statements,
			$sitelinks
		);
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private function oneLine( $text ) {
		return str_replace( [ "\r", "\n" ], ' ', $text );
	}

}
