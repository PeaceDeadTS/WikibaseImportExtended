<?php

namespace Wikibase\Import;

use Psr\Log\AbstractLogger;

/**
 * Collects logger lines for the web UI. The text matches {@see StdoutLogger}.
 */
class BufferLogger extends AbstractLogger {

	/**
	 * @var ImportRunResult|null
	 */
	private $result;

	/**
	 * Attached by ImportRunner so log lines land on the same result as the header lines.
	 *
	 * @param ImportRunResult $result
	 */
	public function bind( ImportRunResult $result ) {
		$this->result = $result;
	}

	/**
	 * @param mixed $level
	 * @param string|\Stringable $message
	 * @param array $context
	 */
	public function log( $level, $message, array $context = [] ): void {
		if ( $this->result === null ) {
			return;
		}
		$this->result->addLog( (string)$level, $this->interpolate( (string)$message, $context ) );
	}

	/**
	 * @param string $message
	 * @param array $context
	 * @return string
	 */
	private function interpolate( $message, array $context ) {
		$replace = [];
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || $value === null ) {
				$replace['{' . $key . '}'] = (string)$value;
			}
		}

		return strtr( $message, $replace );
	}

}
