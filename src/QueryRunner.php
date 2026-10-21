<?php

namespace Wikibase\Import;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\PropertyId;

class QueryRunner {

	/**
	 * @var SourceHttp
	 */
	private $http;

	/**
	 * @var string
	 */
	private $queryUrl;

	/**
	 * @var array
	 */
	private $prefixes;

	/**
	 * @param SourceHttp $http
	 * @param string $queryUrl
	 * @param array $prefixes
	 */
	public function __construct( SourceHttp $http, $queryUrl, array $prefixes ) {
		$this->http = $http;
		$this->queryUrl = $queryUrl;
		$this->prefixes = $prefixes;
	}

	/**
	 * @param PropertyId $propertyId
	 * @param EntityId $valueId
	 * @return string[]
	 */
	public function getPropertyEntityIdValueMatches( PropertyId $propertyId, EntityId $valueId ) {
		$wd = $this->prefixes['wd'] ?? 'http://www.wikidata.org/entity/';
		$wdt = $this->prefixes['wdt'] ?? 'http://www.wikidata.org/prop/direct/';
		$property = $propertyId->getSerialization();
		$value = $valueId->getSerialization();

		$sparql = "PREFIX wd: <$wd>\n"
			. "PREFIX wdt: <$wdt>\n"
			. "SELECT ?id WHERE { ?id wdt:$property wd:$value }";

		$results = $this->http->getJson( $this->queryUrl, [
			'query' => $sparql,
			'format' => 'json',
		] );

		$bindings = $results['results']['bindings'] ?? null;
		if ( !is_array( $bindings ) ) {
			throw new QueryException( 'Query execution failed.' );
		}

		return $this->parseResults( $bindings, $wd );
	}

	/**
	 * @param array $results
	 * @param string $entityPrefix
	 * @return string[]
	 */
	private function parseResults( array $results, $entityPrefix ) {
		$prefix = preg_quote( $entityPrefix, '/' );
		$pattern = '/^' . $prefix . '([A-Za-z]+\\d+)$/';
		$ids = [];

		foreach ( $results as $result ) {
			$value = $result['id']['value'] ?? '';
			if ( preg_match( $pattern, $value, $matches ) ) {
				$ids[] = $matches[1];
			}
		}

		return $ids;
	}

}
