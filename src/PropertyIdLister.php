<?php

namespace Wikibase\Import;

/**
 * Lists property ids from the source wiki via allpages.
 */
class PropertyIdLister {

	/**
	 * @var SourceHttp
	 */
	private $http;

	/**
	 * @var int
	 */
	private $namespace;

	/**
	 * @var array|null
	 */
	private $continuation = null;

	/**
	 * @var string[]
	 */
	private $properties = [];

	/**
	 * @param SourceHttp $http
	 * @param int $namespace Source property namespace. Wikidata uses 120.
	 */
	public function __construct( SourceHttp $http, $namespace ) {
		$this->http = $http;
		$this->namespace = (int)$namespace;
	}

	/**
	 * @param string $apiUrl
	 * @return string[]
	 */
	public function fetch( $apiUrl ) {
		$this->continuation = null;
		$this->properties = [];

		do {
			$response = $this->doRequest( $apiUrl );
			$this->extractContinuation( $response );
			$this->extractProperties( $response );
		} while ( $this->continuation !== null );

		return $this->properties;
	}

	/**
	 * @param string $apiUrl
	 * @return array
	 */
	private function doRequest( $apiUrl ) {
		$params = [
			'action' => 'query',
			'list' => 'allpages',
			'apnamespace' => $this->namespace,
			'aplimit' => 'max',
			'format' => 'json',
		];

		if ( is_array( $this->continuation ) ) {
			foreach ( $this->continuation as $key => $value ) {
				$params[$key] = $value;
			}
		}

		return $this->http->getJson( $apiUrl, $params );
	}

	/**
	 * @param array $response
	 */
	private function extractContinuation( array $response ) {
		if ( !isset( $response['continue'] ) || !is_array( $response['continue'] ) ) {
			$this->continuation = null;
			return;
		}

		$this->continuation = $response['continue'];
	}

	/**
	 * @param array $response
	 */
	private function extractProperties( array $response ) {
		if ( !isset( $response['query']['allpages'] ) || !is_array( $response['query']['allpages'] ) ) {
			throw new \RuntimeException( 'query param not found in result' );
		}

		foreach ( $response['query']['allpages'] as $page ) {
			$title = $page['title'] ?? '';
			if ( preg_match( '/(P\d+)$/', $title, $matches ) ) {
				$this->properties[] = $matches[1];
			}
		}
	}

}
