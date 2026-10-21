<?php

namespace Wikibase\Import;

use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Import\Store\ImportedEntityMappingStore;

class StatementCopier {

	/**
	 * @var ImportedEntityMappingStore
	 */
	private $entityMappingStore;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var array<string,true>
	 */
	private $missing = [];

	/**
	 * @param ImportedEntityMappingStore $entityMappingStore
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ImportedEntityMappingStore $entityMappingStore,
		LoggerInterface $logger
	) {
		$this->entityMappingStore = $entityMappingStore;
		$this->logger = $logger;
	}

	/**
	 * @param Statement $statement
	 * @return Statement|null Null when the main snak cannot be mapped onto this wiki.
	 */
	public function copy( Statement $statement ) {
		$mainSnak = $this->copySnak( $statement->getMainSnak() );
		if ( $mainSnak === null ) {
			return null;
		}

		$newStatement = new Statement(
			$mainSnak,
			$this->copyQualifiers( $statement->getQualifiers() ),
			$this->copyReferences( $statement->getReferences() )
		);
		$newStatement->setRank( $statement->getRank() );

		return $newStatement;
	}

	/**
	 * @param Snak $snak
	 * @return Snak|null
	 */
	private function copySnak( Snak $snak ) {
		$oldPropertyId = $snak->getPropertyId();
		$newPropertyId = $this->entityMappingStore->getLocalId( $oldPropertyId );
		if ( !$newPropertyId instanceof PropertyId ) {
			$this->warnMissing( $oldPropertyId->getSerialization() );
			return null;
		}

		switch ( $snak->getType() ) {
			case 'somevalue':
				return new PropertySomeValueSnak( $newPropertyId );
			case 'novalue':
				return new PropertyNoValueSnak( $newPropertyId );
			default:
				if ( !$snak instanceof PropertyValueSnak ) {
					return null;
				}

				$value = $snak->getDataValue();
				if ( $value instanceof \DataValues\UnDeserializableValue ) {
					return null;
				}

				if ( $value instanceof EntityIdValue ) {
					$localId = $this->entityMappingStore->getLocalId( $value->getEntityId() );
					if ( $localId === null ) {
						$this->warnMissing( $value->getEntityId()->getSerialization() );
						return null;
					}
					$value = new EntityIdValue( $localId );
				}

				return new PropertyValueSnak( $newPropertyId, $value );
		}
	}

	/**
	 * @param SnakList $qualifiers
	 * @return SnakList
	 */
	private function copyQualifiers( SnakList $qualifiers ) {
		$newQualifiers = new SnakList();
		foreach ( $qualifiers as $qualifier ) {
			$copied = $this->copySnak( $qualifier );
			if ( $copied !== null ) {
				$newQualifiers->addSnak( $copied );
			}
		}

		return $newQualifiers;
	}

	/**
	 * @param ReferenceList $references
	 * @return ReferenceList
	 */
	private function copyReferences( ReferenceList $references ) {
		$newReferences = new ReferenceList();

		foreach ( $references as $reference ) {
			$snaks = [];
			foreach ( $reference->getSnaks() as $referenceSnak ) {
				$copied = $this->copySnak( $referenceSnak );
				if ( $copied !== null ) {
					$snaks[] = $copied;
				}
			}

			if ( $snaks !== [] ) {
				$newReferences->addReference( new Reference( $snaks ) );
			}
		}

		return $newReferences;
	}

	/**
	 * @param string $serialization
	 */
	private function warnMissing( $serialization ) {
		if ( isset( $this->missing[$serialization] ) ) {
			return;
		}

		$this->missing[$serialization] = true;
		$this->logger->warning(
			"No local entity for $serialization; statements that need it are skipped"
		);
	}

}
