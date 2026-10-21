<?php

namespace Wikibase\Import;

use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;

/**
 * JSON HTTP client for the source Wikibase and its SPARQL endpoint.
 */
class SourceHttp {

	/**
	 * @var HttpRequestFactory
	 */
	private $http;

	/**
	 * @var string
	 */
	private $userAgent;

	/**
	 * @param HttpRequestFactory $http
	 * @param string $userAgent
	 */
	public function __construct( HttpRequestFactory $http, $userAgent ) {
		$this->http = $http;
		$this->userAgent = $userAgent;
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @return array
	 */
	public function getJson( $url, array $params = [] ) {
		if ( $params !== [] ) {
			$url = wfAppendQuery( $url, $params );
		}

		$request = $this->http->create( $url, [
			'method' => 'GET',
			'timeout' => 120,
			'userAgent' => $this->userAgent,
		], __METHOD__ );
		$request->setHeader( 'Accept', 'application/json' );

		$status = $request->execute();
		$body = (string)$request->getContent();
		if ( !$status->isOK() ) {
			throw new RuntimeException(
				'HTTP ' . $request->getStatus() . ' for ' . $url . ': ' . substr( $body, 0, 500 )
			);
		}

		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new RuntimeException( 'Response was not JSON: ' . substr( $body, 0, 500 ) );
		}

		if ( isset( $data['error'] ) ) {
			$info = $data['error']['info'] ?? json_encode( $data['error'] );
			throw new RuntimeException( 'Source API error: ' . $info );
		}

		return $data;
	}

}
