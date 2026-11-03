<?php

namespace Wikibase\Import;

/**
 * One import or dry-run request. Callers resolve statements, sitelinks and the user name
 * before constructing this, so the runner does not consult config again.
 */
class ImportJob {

	/**
	 * @var string entity, file, query, range, stdin or all-properties
	 */
	public $mode;

	/**
	 * @var mixed Value passed to the id list builder. A string for entity, file, query and range.
	 */
	public $input;

	/**
	 * @var string Existing local account, already defaulted.
	 */
	public $userName;

	/**
	 * @var bool
	 */
	public $dryRun;

	/**
	 * @var bool
	 */
	public $statements;

	/**
	 * @var bool
	 */
	public $sitelinks;

	/**
	 * Local entity to supplement. Empty means import as new.
	 *
	 * @var string
	 */
	public $extendLocalId;

	/**
	 * @var bool
	 */
	public $replaceDescription;

	/**
	 * @var bool
	 */
	public $addAliases;

	/**
	 * @param string $mode
	 * @param mixed $input
	 * @param string $userName
	 * @param bool $dryRun
	 * @param bool $statements
	 * @param bool $sitelinks
	 * @param string $extendLocalId
	 * @param bool $replaceDescription
	 * @param bool $addAliases
	 */
	public function __construct(
		$mode,
		$input,
		$userName,
		$dryRun,
		$statements,
		$sitelinks,
		$extendLocalId = '',
		$replaceDescription = false,
		$addAliases = true
	) {
		$this->mode = (string)$mode;
		$this->input = $input;
		$this->userName = (string)$userName;
		$this->dryRun = (bool)$dryRun;
		$this->statements = (bool)$statements;
		$this->sitelinks = (bool)$sitelinks;
		$this->extendLocalId = (string)$extendLocalId;
		$this->replaceDescription = (bool)$replaceDescription;
		$this->addAliases = (bool)$addAliases;
	}

}
