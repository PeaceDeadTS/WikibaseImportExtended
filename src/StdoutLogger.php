<?php

namespace Wikibase\Import;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Writes import progress to stdout and failures to stderr.
 */
class StdoutLogger extends AbstractLogger {

	/**
	 * @param mixed $level
	 * @param string|\Stringable $message
	 * @param array $context
	 */
	public function log( $level, $message, array $context = [] ): void {
		$line = strtoupper( (string)$level ) . ' ' . $this->interpolate( (string)$message, $context ) . "\n";
		$stream = $this->isProblem( (string)$level ) ? STDERR : STDOUT;
		fwrite( $stream, $line );
	}

	/**
	 * @param string $level
	 * @return bool
	 */
	private function isProblem( $level ) {
		return in_array( $level, [
			LogLevel::EMERGENCY,
			LogLevel::ALERT,
			LogLevel::CRITICAL,
			LogLevel::ERROR,
			LogLevel::WARNING,
		], true );
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
