<?php

namespace Wikibase\Import;

use Deserializers\DispatchingDeserializer;
use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;

/**
 * Loads entities from a remote Wikibase wbgetentities API.
 */
class ApiEntityLookup implements EntityLookup {

	/**
	 * @var DispatchingDeserializer
	 */
	private $deserializer;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var SourceHttp
	 */
	private $http;

	/**
	 * @var string
	 */
	private $apiUrl;

	/**
	 * @param DispatchingDeserializer $deserializer
	 * @param LoggerInterface $logger
	 * @param SourceHttp $http
	 * @param string $apiUrl
	 */
	public function __construct(
		DispatchingDeserializer $deserializer,
		LoggerInterface $logger,
		SourceHttp $http,
		$apiUrl
	) {
		$this->deserializer = $deserializer;
		$this->logger = $logger;
		$this->http = $http;
		$this->apiUrl = $apiUrl;
	}

	/**
	 * @param EntityId $entityId
	 * @return EntityDocument|null
	 */
	public function getEntity( EntityId $entityId ) {
		$entities = $this->getEntities( [ $entityId->getSerialization() ] );

		foreach ( $entities as $entity ) {
			return $entity;
		}

		return null;
	}

	/**
	 * @param EntityId $entityId
	 * @return bool
	 */
	public function hasEntity( EntityId $entityId ) {
		return $this->getEntity( $entityId ) !== null;
	}

	/**
	 * @param string[] $ids
	 * @return EntityDocument[] Map of source id serialization to entity
	 */
	public function getEntities( array $ids ) {
		$ids = array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
		if ( $ids === [] ) {
			return [];
		}

		$data = $this->http->getJson( $this->apiUrl, [
			'action' => 'wbgetentities',
			'ids' => implode( '|', $ids ),
			'format' => 'json',
			'formatversion' => '1',
		] );

		$entities = [];
		$serialized = $data['entities'] ?? [];
		if ( !is_array( $serialized ) ) {
			$this->logger->error( 'Source API returned no entities object' );
			return [];
		}

		foreach ( $serialized as $entityId => $serialization ) {
			if ( !is_array( $serialization ) || isset( $serialization['missing'] ) ) {
				$this->logger->warning( "Source entity missing: $entityId" );
				continue;
			}

			if ( !$this->deserializer->isDeserializerFor( $serialization ) ) {
				$type = $serialization['type'] ?? 'unknown';
				$this->logger->warning( "Skipping $entityId: unsupported entity type $type" );
				continue;
			}

			try {
				$entities[$entityId] = $this->deserializer->deserialize( $serialization );
			} catch ( \Throwable $ex ) {
				$this->logger->error( "Failed to parse $entityId: " . $ex->getMessage() );
			}
		}

		return $entities;
	}

}
