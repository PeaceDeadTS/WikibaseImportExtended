<?php

namespace Wikibase\Import\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Import\BufferLogger;
use Wikibase\Import\ImportJob;
use Wikibase\Import\ImportRunner;
use Wikibase\Import\ImportUserResolver;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\RequestTimeout\RequestTimeout;

/**
 * Dry-run (step=check) or import (step=import) of one source entity.
 */
class ApiWikibaseImport extends ApiBase {

	public function execute() {
		$this->checkUserRightsAny( 'wikibaseimport' );
		@set_time_limit( 300 );
		if ( class_exists( RequestTimeout::class ) ) {
			RequestTimeout::singleton()->setWallTimeLimit( 300 );
		}

		$params = $this->extractRequestParams();
		$entity = $this->normalizeEntity( (string)$params['entity'] );
		if ( $entity === '' ) {
			$this->addPayload( $this->emptyPayload(
				(string)$params['step'],
				$this->msg( 'wikibaseimport-empty-entity' )->text()
			) );
			return;
		}

		$config = $this->getConfig();
		$userName = ImportUserResolver::resolveUserName( $params['user'], $config->get( 'WBImportUser' ) );
		$job = new ImportJob(
			'entity',
			$entity,
			$userName,
			$params['step'] === 'check',
			(bool)$params['statements'],
			(bool)$params['sitelinks']
		);
		$run = ( new ImportRunner() )->run( $job, new BufferLogger(), static function ( $line ) {
		} );

		$this->addPayload( $this->present( $run, $userName ) );
	}

	/**
	 * @param string $step
	 * @param string $error
	 * @return array<string,mixed>
	 */
	private function emptyPayload( $step, $error ) {
		return [
			'ok' => false,
			'step' => $step,
			'error' => $error,
			'user' => '',
			'bot' => false,
			'statements' => false,
			'sitelinks' => false,
			'sourcecount' => 0,
			'haswarning' => false,
			'log' => [],
			'entities' => [],
		];
	}

	/**
	 * @param \Wikibase\Import\ImportRunResult $run
	 * @param string $requestedUser
	 * @return array<string,mixed>
	 */
	private function present( $run, $requestedUser ) {
		$log = [];
		foreach ( $run->lines as $line ) {
			$log[] = [
				'level' => $line['level'],
				'message' => $line['message'],
			];
		}
		ApiResult::setIndexedTagName( $log, 'line' );

		$entities = [];
		foreach ( $run->entities as $entity ) {
			$id = (string)$entity['id'];
			$localId = (string)$entity['localId'];
			$entities[] = [
				'id' => $id,
				'type' => (string)$entity['type'],
				'label' => (string)$entity['label'],
				'action' => (string)$entity['action'],
				'labels' => $entity['labels'],
				'descriptions' => $entity['descriptions'],
				'aliases' => $entity['aliases'],
				'statements' => (string)$entity['statements'],
				'sitelinks' => (string)$entity['sitelinks'],
				'statementswritten' => $entity['statementsWritten'],
				'statementsskipped' => $entity['statementsSkipped'],
				'localid' => $localId,
				'sourceurl' => $this->sourceUrl( $id ),
				'localurl' => $localId !== '' ? $this->localUrl( $localId ) : '',
			];
		}
		ApiResult::setIndexedTagName( $entities, 'entity' );

		return [
			'ok' => $run->ok,
			'step' => $run->dryRun ? 'check' : 'import',
			'error' => $run->fatal !== null ? trim( $run->fatal ) : '',
			'user' => $run->userName !== '' ? $run->userName : $requestedUser,
			'bot' => $run->bot,
			'statements' => $run->statements,
			'sitelinks' => $run->sitelinks,
			'sourcecount' => $run->sourceCount,
			'haswarning' => $run->hasWarning,
			'log' => $log,
			'entities' => $entities,
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function addPayload( array $payload ) {
		ApiResult::setIndexedTagName( $payload['log'], 'line' );
		ApiResult::setIndexedTagName( $payload['entities'], 'entity' );
		$this->getResult()->addValue( null, $this->getModuleName(), $payload );
	}

	/**
	 * @param string $id
	 * @return string
	 */
	private function normalizeEntity( $id ) {
		$id = trim( $id );
		if ( preg_match( '/^[qp]\d+$/i', $id ) ) {
			return strtoupper( $id );
		}

		return $id;
	}

	/**
	 * @param string $id
	 * @return string
	 */
	private function sourceUrl( $id ) {
		$api = (string)$this->getConfig()->get( 'WBImportSourceApi' );
		if ( !preg_match( '#^(https?://[^/]+)/w/api\.php$#', $api, $match ) ) {
			return '';
		}
		$path = $id;
		if ( strncmp( $id, 'P', 1 ) === 0 ) {
			$path = 'Property:' . $id;
		}

		return $match[1] . '/wiki/' . rawurlencode( $path );
	}

	/**
	 * @param string $serialization
	 * @return string
	 */
	private function localUrl( $serialization ) {
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $serialization );
			if ( !$id instanceof EntityId ) {
				return '';
			}
			$url = WikibaseRepo::getEntityUrlLookup()->getLinkUrl( $id );
			return $url ?? '';
		} catch ( \Throwable $ex ) {
			return '';
		}
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function getAllowedParams() {
		return [
			'step' => [
				ParamValidator::PARAM_TYPE => [ 'check', 'import' ],
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-wikibaseimport-param-step',
			],
			'entity' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-wikibaseimport-param-entity',
			],
			'user' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ApiBase::PARAM_HELP_MSG => 'apihelp-wikibaseimport-param-user',
			],
			'statements' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-wikibaseimport-param-statements',
			],
			'sitelinks' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-wikibaseimport-param-sitelinks',
			],
		];
	}

	/**
	 * @return array<string,string>
	 */
	protected function getExamplesMessages() {
		return [
			'action=wikibaseimport&step=check&entity=Q64&user=PBDBot&statements=1'
				=> 'apihelp-wikibaseimport-example',
		];
	}

	/**
	 * @return bool
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @return string
	 */
	public function needsToken() {
		return 'csrf';
	}

}
