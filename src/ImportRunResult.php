<?php

namespace Wikibase\Import;

/**
 * Outcome of {@see ImportRunner::run()}. Header lines and logger lines stay in CLI order.
 */
class ImportRunResult {

	/**
	 * @var bool True when nothing fatal happened and the logger recorded no error.
	 */
	public $ok = false;

	/**
	 * @var string|null Maintenance fatal text, including the trailing newline. Null when the run finished.
	 */
	public $fatal = null;

	/**
	 * @var bool
	 */
	public $hasError = false;

	/**
	 * @var bool
	 */
	public $hasWarning = false;

	/**
	 * @var bool
	 */
	public $dryRun = false;

	/**
	 * @var bool
	 */
	public $statements = false;

	/**
	 * @var bool
	 */
	public $sitelinks = false;

	/**
	 * @var string Resolved account, empty if the run failed before the user check.
	 */
	public $userName = '';

	/**
	 * @var bool
	 */
	public $bot = false;

	/**
	 * @var int
	 */
	public $sourceCount = 0;

	/**
	 * @var list<array{level:string,message:string}>
	 */
	public $lines = [];

	/**
	 * @var list<array<string,mixed>>
	 */
	public $entities = [];

	/**
	 * @param string $message Header line without a trailing newline.
	 */
	public function addOutput( $message ) {
		$this->lines[] = [
			'level' => 'output',
			'message' => (string)$message,
		];
	}

	/**
	 * @param string $level PSR-3 level name
	 * @param string $message
	 */
	public function addLog( $level, $message ) {
		$level = (string)$level;
		$this->lines[] = [
			'level' => $level,
			'message' => (string)$message,
		];
		if ( in_array( $level, [ 'emergency', 'alert', 'critical', 'error' ], true ) ) {
			$this->hasError = true;
		}
		if ( $level === 'warning' ) {
			$this->hasWarning = true;
		}
	}

}
