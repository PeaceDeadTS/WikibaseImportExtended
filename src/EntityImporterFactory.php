<?php

namespace Wikibase\Import;

use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\Import\Store\DBImportedEntityMappingStore;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Repo\Store\Store;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\Rdbms\IConnectionProvider;

class EntityImporterFactory {

	/**
	 * @var EntityStore
	 */
	private $entityStore;

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var IConnectionProvider
	 */
	private $dbProvider;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

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
	 * @var User|null
	 */
	private $user;

	/**
	 * @var bool
	 */
	private $dryRun;

	/**
	 * @var bool
	 */
	private $importSitelinks;

	/**
	 * @var bool
	 */
	private $mappingTableReady;

	/**
	 * @var int
	 */
	private $botFlag;

	/**
	 * @var EntityImporter|null
	 */
	private $entityImporter = null;

	/**
	 * @param EntityStore $entityStore
	 * @param Store $store
	 * @param IConnectionProvider $dbProvider
	 * @param EntityIdParser $entityIdParser
	 * @param LoggerInterface $logger
	 * @param SourceHttp $http
	 * @param string $apiUrl
	 * @param User|null $user
	 * @param bool $dryRun
	 * @param bool $importSitelinks
	 * @param bool $mappingTableReady
	 * @param int $botFlag
	 */
	public function __construct(
		EntityStore $entityStore,
		Store $store,
		IConnectionProvider $dbProvider,
		EntityIdParser $entityIdParser,
		LoggerInterface $logger,
		SourceHttp $http,
		$apiUrl,
		$user,
		$dryRun,
		$importSitelinks,
		$mappingTableReady,
		$botFlag
	) {
		$this->entityStore = $entityStore;
		$this->store = $store;
		$this->dbProvider = $dbProvider;
		$this->entityIdParser = $entityIdParser;
		$this->logger = $logger;
		$this->http = $http;
		$this->apiUrl = $apiUrl;
		$this->user = $user;
		$this->dryRun = (bool)$dryRun;
		$this->importSitelinks = (bool)$importSitelinks;
		$this->mappingTableReady = (bool)$mappingTableReady;
		$this->botFlag = (int)$botFlag;
	}

	/**
	 * @return EntityImporter
	 */
	public function newEntityImporter() {
		if ( $this->entityImporter === null ) {
			$mappingStore = $this->newMappingStore();
			$copier = new StatementCopier( $mappingStore, $this->logger );

			$this->entityImporter = new EntityImporter(
				new StatementsImporter(
					$copier,
					$mappingStore,
					$this->store->getEntityRevisionLookup( Store::LOOKUP_CACHING_DISABLED ),
					$this->entityStore,
					$this->user,
					$this->logger,
					$this->dryRun,
					$this->botFlag
				),
				new BadgeItemUpdater( $mappingStore ),
				new ApiEntityLookup(
					WikibaseRepo::getBaseDataModelDeserializerFactory()->newEntityDeserializer(),
					$this->logger,
					$this->http,
					$this->apiUrl
				),
				$this->entityStore,
				$mappingStore,
				new PagePropsStatementCountLookup(
					$this->dbProvider,
					WikibaseRepo::getEntityNamespaceLookup()
				),
				$this->newTermSanitizer(),
				WikibaseRepo::getDataTypeFactory(),
				$this->logger,
				$this->user,
				$this->dryRun,
				$this->importSitelinks,
				$this->botFlag
			);
		}

		return $this->entityImporter;
	}

	/**
	 * @return DBImportedEntityMappingStore
	 */
	private function newMappingStore() {
		return new DBImportedEntityMappingStore(
			$this->dbProvider,
			$this->entityIdParser,
			$this->mappingTableReady,
			$this->dryRun
		);
	}

	/**
	 * @return TermSanitizer
	 */
	private function newTermSanitizer() {
		$limits = WikibaseRepo::getSettings()->getSetting( 'string-limits' );
		$maxLength = $limits['multilang']['length'] ?? 250;

		return new TermSanitizer( WikibaseRepo::getTermsLanguages(), (int)$maxLength );
	}

}
