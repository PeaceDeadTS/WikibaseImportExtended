<?php

namespace Wikibase\Import;

use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\UnresolvedEntityRedirectException;
use Wikibase\DataModel\SiteLinkList;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Statement\StatementListProvider;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\FingerprintProvider;
use Wikibase\Import\Store\ImportedEntityMappingStore;
use Wikibase\Lib\DataTypeFactory;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Lib\Store\LookupConstants;

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
	 * @var EntityRevisionLookup
	 */
	private $revisionLookup;

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
	 * Source id of the entity being extended. While set, that id is not created again.
	 *
	 * @var string|null
	 */
	private $extendRootId = null;

	/**
	 * @param StatementsImporter $statementsImporter
	 * @param BadgeItemUpdater $badgeItemUpdater
	 * @param ApiEntityLookup $apiEntityLookup
	 * @param EntityStore $entityStore
	 * @param EntityRevisionLookup $revisionLookup
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
		EntityRevisionLookup $revisionLookup,
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
		$this->revisionLookup = $revisionLookup;
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
	 * Supplement an existing local entity with data from a source id.
	 * The local id is the one the caller named. The mapping table is not used to pick it.
	 *
	 * @param string $sourceSerialization Wikidata (or other source) id.
	 * @param string $localSerialization Existing local id.
	 * @param bool $importStatements
	 * @param bool $replaceDescription
	 * @param bool $addAliases
	 */
	public function extendEntity(
		$sourceSerialization,
		$localSerialization,
		$importStatements,
		$replaceDescription,
		$addAliases
	) {
		$sourceId = $this->parseEntityId( $sourceSerialization );
		$localId = $this->parseEntityId( $localSerialization );
		if ( $sourceId->getEntityType() !== $localId->getEntityType() ) {
			throw new ImportException( wfMessage( 'wikibaseimport-id-mismatch' )->text() );
		}

		try {
			$revision = $this->revisionLookup->getEntityRevision(
				$localId,
				0,
				LookupConstants::LATEST_FROM_MASTER
			);
		} catch ( UnresolvedEntityRedirectException $ex ) {
			throw new ImportException(
				wfMessage( 'wikibaseimport-local-redirect', $localId->getSerialization() )->text()
			);
		}
		if ( $revision === null ) {
			throw new ImportException(
				wfMessage( 'wikibaseimport-local-missing', $localId->getSerialization() )->text()
			);
		}

		$found = $this->apiEntityLookup->getEntities( [ $sourceId->getSerialization() ] );
		$source = $found[$sourceId->getSerialization()] ?? null;
		if ( !$source instanceof EntityDocument ) {
			throw new ImportException(
				wfMessage( 'wikibaseimport-source-missing', $sourceId->getSerialization() )->text()
			);
		}
		if ( $source->getType() !== $revision->getEntity()->getType() ) {
			throw new ImportException( wfMessage( 'wikibaseimport-id-mismatch' )->text() );
		}

		if (
			$source instanceof Property
			&& $revision->getEntity() instanceof Property
			&& $source->getDataTypeId() !== $revision->getEntity()->getDataTypeId()
		) {
			$this->logger->warning(
				'Datatype of ' . $localId->getSerialization()
				. ' differs from ' . $sourceId->getSerialization() . ' and is left unchanged'
			);
		}

		$local = $revision->getEntity();
		$mapped = $this->entityMappingStore->getLocalId( $sourceId );
		$persisted = $this->entityMappingStore->isPersisted( $sourceId );
		if ( $persisted && $mapped !== null && !$mapped->equals( $localId ) ) {
			$this->logger->warning(
				'Mapping for ' . $sourceId->getSerialization()
				. ' already points at ' . $mapped->getSerialization()
				. ' and was left unchanged. This extend updates ' . $localId->getSerialization() . '.'
			);
		}
		// Statement copying resolves the source id to the entity the user named.
		$this->entityMappingStore->stage( $sourceId, $localId );

		$this->extendRootId = $sourceId->getSerialization();
		try {
			$this->applyExtend(
				$source,
				$local,
				$revision->getRevisionId(),
				(bool)$importStatements,
				(bool)$replaceDescription,
				(bool)$addAliases,
				$persisted
			);
		} finally {
			$this->extendRootId = null;
		}
	}

	/**
	 * @param EntityDocument $source
	 * @param EntityDocument $local
	 * @param int $baseRevId
	 * @param bool $importStatements
	 * @param bool $replaceDescription
	 * @param bool $addAliases
	 * @param bool $mappingPersisted
	 */
	private function applyExtend(
		EntityDocument $source,
		EntityDocument $local,
		$baseRevId,
		$importStatements,
		$replaceDescription,
		$addAliases,
		$mappingPersisted
	) {
		$sourceId = $source->getId();
		$localId = $local->getId();
		if ( $sourceId === null || $localId === null ) {
			throw new ImportException( wfMessage( 'wikibaseimport-bad-id' )->text() );
		}

		$sourceSitelinks = $source instanceof Item ? $source->getSiteLinkList()->count() : 0;
		$sitelinkBackup = $local instanceof Item ? new SiteLinkList( $local->getSiteLinkList() ) : null;

		if ( $this->importSitelinks && $source instanceof Item ) {
			$this->importBadgeItems( [ $source ] );
		}

		$statementsAdded = 0;
		$statementsMerged = 0;
		$statementsSkipped = 0;
		if ( $importStatements && $source instanceof StatementListProvider ) {
			$referenced = $this->withoutExtendRoot( $this->getReferencedEntities( $source ) );
			if ( $referenced !== [] ) {
				$this->importEntities( $referenced, false );
			}
			if ( $local instanceof StatementListProvider ) {
				$counts = $this->statementsImporter->mergeOnto( $source, $local );
				$statementsAdded = $counts['added'];
				$statementsMerged = $counts['merged'];
				$statementsSkipped = $counts['skipped'];
			}
		}

		$labelsAdded = 0;
		$descriptionsAdded = 0;
		$descriptionsReplaced = 0;
		$aliasesAdded = 0;
		if ( $source instanceof FingerprintProvider && $local instanceof FingerprintProvider ) {
			$termCounts = $this->mergeTerms(
				$local->getFingerprint(),
				$source->getFingerprint(),
				$replaceDescription,
				$addAliases
			);
			$labelsAdded = $termCounts['labelsAdded'];
			$descriptionsAdded = $termCounts['descriptionsAdded'];
			$descriptionsReplaced = $termCounts['descriptionsReplaced'];
			$aliasesAdded = $termCounts['aliasesAdded'];
		}

		$sitelinksAdded = 0;
		if ( $this->importSitelinks && $source instanceof Item && $local instanceof Item ) {
			$sitelinksAdded = $this->mergeSitelinks( $local, $source );
		}

		$changed = $labelsAdded + $descriptionsAdded + $descriptionsReplaced
			+ $aliasesAdded + $statementsAdded + $statementsMerged + $sitelinksAdded > 0;
		$statementsToken = $importStatements
			? sprintf( 'add:%d,merge:%d,skip:%d', $statementsAdded, $statementsMerged, $statementsSkipped )
			: 'off';
		$sitelinksToken = $this->importSitelinks
			? (string)$sitelinksAdded
			: '0 (dropped ' . $sourceSitelinks . ')';

		if ( $this->dryRun ) {
			$this->logger->info( 'DRY-RUN ' . $this->extendLine(
				$source,
				$local,
				$labelsAdded,
				$descriptionsAdded,
				$descriptionsReplaced,
				$aliasesAdded,
				$statementsToken,
				$sitelinksToken
			) );
			return;
		}

		if ( $this->importUser === null ) {
			throw new \RuntimeException( 'Import user is required when dry-run is off' );
		}

		if ( $changed ) {
			$summary = 'Extend ' . $localId->getSerialization() . ' from ' . $sourceId->getSerialization();
			try {
				$this->entityStore->saveEntity(
					$local,
					$summary,
					$this->importUser,
					EDIT_UPDATE | $this->botFlag,
					$baseRevId
				);
			} catch ( \Throwable $ex ) {
				if ( !( $local instanceof Item ) || $sitelinksAdded === 0 || $sitelinkBackup === null ) {
					$this->logger->error(
						'Failed to extend ' . $localId->getSerialization() . ': ' . $ex->getMessage()
					);
					return;
				}

				$this->logger->warning(
					'Saving sitelinks onto ' . $localId->getSerialization()
					. ' failed (' . $ex->getMessage() . '). Retrying without new sitelinks.'
				);
				$local->setSiteLinkList( $sitelinkBackup );
				$sitelinksToken = '0 (dropped ' . $sourceSitelinks . ')';
				try {
					$this->entityStore->saveEntity(
						$local,
						$summary,
						$this->importUser,
						EDIT_UPDATE | $this->botFlag,
						$baseRevId
					);
				} catch ( \Throwable $retryEx ) {
					$this->logger->error(
						'Failed to extend ' . $localId->getSerialization() . ': ' . $retryEx->getMessage()
					);
					return;
				}
			}
		}

		if ( !$mappingPersisted ) {
			try {
				$this->entityMappingStore->add( $sourceId, $localId );
			} catch ( \Throwable $ex ) {
				$this->logger->warning(
					'Could not store mapping for ' . $sourceId->getSerialization() . ': ' . $ex->getMessage()
				);
			}
		}

		$this->logger->info( $this->extendLine(
			$source,
			$local,
			$labelsAdded,
			$descriptionsAdded,
			$descriptionsReplaced,
			$aliasesAdded,
			$statementsToken,
			$sitelinksToken
		) );
	}

	/**
	 * @param string $serialization
	 * @return EntityId
	 */
	private function parseEntityId( $serialization ) {
		$serialization = strtoupper( trim( (string)$serialization ) );
		try {
			if ( preg_match( '/^Q\d+$/', $serialization ) ) {
				return new ItemId( $serialization );
			}
			if ( preg_match( '/^P\d+$/', $serialization ) ) {
				return new PropertyId( $serialization );
			}
		} catch ( \Throwable $ex ) {
			throw new ImportException( wfMessage( 'wikibaseimport-bad-id' )->text() );
		}

		throw new ImportException( wfMessage( 'wikibaseimport-bad-id' )->text() );
	}

	/**
	 * Labels are filled in only for languages the local entity does not have.
	 * Descriptions are per language: missing ones are added, existing ones are
	 * replaced only when requested, and languages absent from the source stay.
	 * Aliases are appended by exact string match and are never deleted.
	 *
	 * @param Fingerprint $local
	 * @param Fingerprint $source
	 * @param bool $replaceDescription
	 * @param bool $addAliases
	 * @return array{labelsAdded:int,descriptionsAdded:int,descriptionsReplaced:int,aliasesAdded:int}
	 */
	private function mergeTerms( Fingerprint $local, Fingerprint $source, $replaceDescription, $addAliases ) {
		$labelsAdded = 0;
		foreach ( $source->getLabels() as $term ) {
			$language = $term->getLanguageCode();
			if ( $local->hasLabel( $language ) ) {
				continue;
			}
			$text = trim( $term->getText() );
			if ( !$this->termSanitizer->acceptTerm( $language, $text ) ) {
				continue;
			}
			// Wikibase rejects a label equal to the description in the same language.
			if ( $local->hasDescription( $language ) && $local->getDescription( $language )->getText() === $text ) {
				continue;
			}
			$local->setLabel( $language, $text );
			$labelsAdded++;
		}

		$descriptionsAdded = 0;
		$descriptionsReplaced = 0;
		foreach ( $source->getDescriptions() as $term ) {
			$language = $term->getLanguageCode();
			$text = trim( $term->getText() );
			if ( !$this->termSanitizer->acceptTerm( $language, $text ) ) {
				continue;
			}
			if ( $local->hasLabel( $language ) && $local->getLabel( $language )->getText() === $text ) {
				continue;
			}
			$has = $local->hasDescription( $language );
			if ( $has && !$replaceDescription ) {
				continue;
			}
			if ( $has && $local->getDescription( $language )->getText() === $text ) {
				continue;
			}
			$local->setDescription( $language, $text );
			if ( $has ) {
				$descriptionsReplaced++;
			} else {
				$descriptionsAdded++;
			}
		}

		$aliasesAdded = 0;
		if ( $addAliases ) {
			foreach ( $source->getAliasGroups() as $group ) {
				$language = $group->getLanguageCode();
				$existing = $local->hasAliasGroup( $language )
					? $local->getAliasGroup( $language )->getAliases()
					: [];
				$label = $local->hasLabel( $language ) ? $local->getLabel( $language )->getText() : '';
				$merged = $existing;
				foreach ( $group->getAliases() as $alias ) {
					if ( !$this->termSanitizer->acceptTerm( $language, $alias ) ) {
						continue;
					}
					if ( $label !== '' && $alias === $label ) {
						continue;
					}
					$merged[] = $alias;
				}
				if ( $merged === [] ) {
					continue;
				}
				$local->setAliasGroup( $language, $merged );
				$stored = $local->getAliasGroup( $language )->getAliases();
				if ( $label !== '' ) {
					$kept = [];
					foreach ( $stored as $alias ) {
						// Drop a newly trimmed alias that collides with the label.
						// Aliases that were already stored are left in place.
						if ( $alias === $label && !in_array( $alias, $existing, true ) ) {
							continue;
						}
						$kept[] = $alias;
					}
					if ( $kept !== $stored ) {
						if ( $kept === [] ) {
							$local->removeAliasGroup( $language );
							continue;
						}
						$local->setAliasGroup( $language, $kept );
						$stored = $kept;
					}
				}
				$aliasesAdded += max( 0, count( $stored ) - count( $existing ) );
			}
		}

		return [
			'labelsAdded' => $labelsAdded,
			'descriptionsAdded' => $descriptionsAdded,
			'descriptionsReplaced' => $descriptionsReplaced,
			'aliasesAdded' => $aliasesAdded,
		];
	}

	/**
	 * Add sitelinks whose site id is not already on the local item.
	 *
	 * @param Item $local
	 * @param Item $source
	 * @return int
	 */
	private function mergeSitelinks( Item $local, Item $source ) {
		$added = 0;
		$sourceLinks = $this->badgeItemUpdater->replaceBadges( $source->getSiteLinkList() );
		foreach ( $sourceLinks as $siteLink ) {
			if ( $local->getSiteLinkList()->hasLinkWithSiteId( $siteLink->getSiteId() ) ) {
				continue;
			}
			$local->getSiteLinkList()->addSiteLink( $siteLink );
			$added++;
		}

		return $added;
	}

	/**
	 * @param EntityDocument $source
	 * @param EntityDocument $local
	 * @param int $labelsAdded
	 * @param int $descriptionsAdded
	 * @param int $descriptionsReplaced
	 * @param int $aliasesAdded
	 * @param string $statementsToken
	 * @param string $sitelinksToken
	 * @return string
	 */
	private function extendLine(
		EntityDocument $source,
		EntityDocument $local,
		$labelsAdded,
		$descriptionsAdded,
		$descriptionsReplaced,
		$aliasesAdded,
		$statementsToken,
		$sitelinksToken
	) {
		$sourceId = $source->getId();
		$localId = $local->getId();
		$label = str_replace( '"', '', $this->pickLabel( $local ) );
		$quoted = $label === '' ? '' : ' "' . $label . '"';

		return sprintf(
			'EXTEND %s %s%s local=%s labelsAdded=%d descriptionsAdded=%d descriptionsReplaced=%d aliasesAdded=%d statements=%s sitelinks=%s',
			$source->getType(),
			$sourceId ? $sourceId->getSerialization() : '',
			$quoted,
			$localId ? $localId->getSerialization() : '',
			$labelsAdded,
			$descriptionsAdded,
			$descriptionsReplaced,
			$aliasesAdded,
			$statementsToken,
			$sitelinksToken
		);
	}

	/**
	 * @param string[] $ids
	 * @return string[]
	 */
	private function withoutExtendRoot( array $ids ) {
		if ( $this->extendRootId === null ) {
			return $ids;
		}
		$root = $this->extendRootId;

		return array_values( array_filter( $ids, static function ( $id ) use ( $root ) {
			return (string)$id !== $root;
		} ) );
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
		if ( $this->extendRootId !== null && $serialization === $this->extendRootId ) {
			// The user named the local id. Do not create a second entity for this source.
			return;
		}
		$existing = $this->entityMappingStore->getLocalId( $sourceId );
		$recreateId = null;
		if ( $existing !== null && $this->entityMappingStore->isPersisted( $sourceId ) ) {
			$pageExists = $this->localPageExists( $existing );
			if ( $pageExists === null ) {
				return;
			}
			if ( $pageExists ) {
				$this->logger->info( "$serialization already imported as " . $existing->getSerialization() );
				return;
			}
			// The mapping row still points here, but the page was deleted.
			// Keep this id. A new id would leave the row, and undelete would make a second entity.
			$recreateId = $existing;
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

		if ( $recreateId !== null ) {
			$this->logger->info(
				$serialization . ' mapped to deleted ' . $recreateId->getSerialization() . ', recreating that page'
			);
		}

		if ( $this->dryRun ) {
			$this->logger->info( 'DRY-RUN ' . $summary );
			if ( $recreateId === null ) {
				// Identity mapping lets statement copying count links without writing.
				$this->entityMappingStore->add( $sourceId, $sourceId );
			}
			return;
		}

		if ( $this->importUser === null ) {
			throw new \RuntimeException( 'Import user is required when dry-run is off' );
		}

		if ( $recreateId !== null ) {
			$entity->setId( $recreateId );
			$this->logger->info( 'Recreating ' . $summary );
		} else {
			$entity->setId( null );
			$this->logger->info( 'Creating ' . $summary );
		}

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

		if ( $recreateId !== null ) {
			if ( !$localId->equals( $recreateId ) ) {
				$this->logger->error(
					"Saved $serialization as " . $localId->getSerialization()
					. ' but the mapping points at ' . $recreateId->getSerialization()
				);
				return;
			}
			// The mapping row already exists. Inserting it again would hit the unique key.
			$this->logger->info( "$serialization recreated as " . $localId->getSerialization() );
			return;
		}

		$this->logger->info( "$serialization created as " . $localId->getSerialization() );
		$this->entityMappingStore->add( $sourceId, $localId );
	}

	/**
	 * True when the local page is still there, including a redirect.
	 * Null when the lookup failed: the caller must not create a second entity.
	 *
	 * @param EntityId $id
	 * @return bool|null
	 */
	private function localPageExists( EntityId $id ) {
		try {
			return $this->revisionLookup->getLatestRevisionId(
				$id,
				LookupConstants::LATEST_FROM_MASTER
			)->onConcreteRevision( static function () {
				return true;
			} )->onRedirect( static function () {
				return true;
			} )->onNonexistentEntity( static function () {
				return false;
			} )->map();
		} catch ( \Throwable $ex ) {
			$this->logger->error(
				'Could not check whether ' . $id->getSerialization() . ' still exists: ' . $ex->getMessage()
			);
			return null;
		}
	}

	/**
	 * @param EntityDocument $entity
	 * @param string $sourceId
	 * @return \Wikibase\Lib\Store\EntityRevision
	 */
	private function saveNewEntity( EntityDocument $entity, $sourceId ) {
		$localId = $entity->getId();
		if ( $localId === null ) {
			$summary = 'Import entity ' . $sourceId;
		} else {
			// New page at the old id. Undelete would put the archived statements back.
			$summary = 'Recreate deleted entity ' . $sourceId . ' as ' . $localId->getSerialization();
		}
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
			$this->importEntities( $this->withoutExtendRoot( $badgeItems ), false );
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
