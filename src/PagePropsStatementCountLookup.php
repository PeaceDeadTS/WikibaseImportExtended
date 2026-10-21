<?php

namespace Wikibase\Import;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikimedia\Rdbms\IConnectionProvider;

class PagePropsStatementCountLookup implements StatementsCountLookup {

	/**
	 * @var IConnectionProvider
	 */
	private $dbProvider;

	/**
	 * @var EntityNamespaceLookup
	 */
	private $namespaceLookup;

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param EntityNamespaceLookup $namespaceLookup
	 */
	public function __construct( IConnectionProvider $dbProvider, EntityNamespaceLookup $namespaceLookup ) {
		$this->dbProvider = $dbProvider;
		$this->namespaceLookup = $namespaceLookup;
	}

	/**
	 * @param EntityId $entityId
	 * @return int
	 */
	public function getStatementCount( EntityId $entityId ) {
		$namespace = $this->namespaceLookup->getEntityNamespace( $entityId->getEntityType() );
		if ( $namespace === null ) {
			return 0;
		}

		$row = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'pp_value' )
			->from( 'page' )
			->join( 'page_props', null, 'pp_page = page_id' )
			->where( [
				'page_namespace' => $namespace,
				'page_title' => $entityId->getSerialization(),
				'pp_propname' => 'wb-claims',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false || $row->pp_value === null ) {
			return 0;
		}

		return (int)$row->pp_value;
	}

	/**
	 * @param EntityId $entityId
	 * @return bool
	 */
	public function hasStatements( EntityId $entityId ) {
		return $this->getStatementCount( $entityId ) > 0;
	}

}
