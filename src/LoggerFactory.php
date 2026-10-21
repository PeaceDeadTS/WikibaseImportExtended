<?php

namespace Wikibase\Import;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LoggerFactory {

	/**
	 * @param string $loggerName
	 * @param bool $quietMode
	 * @return LoggerInterface
	 */
	public static function newLogger( $loggerName, $quietMode ) {
		if ( !is_string( $loggerName ) ) {
			throw new InvalidArgumentException( '$loggerName must be a string' );
		}

		if ( !is_bool( $quietMode ) ) {
			throw new InvalidArgumentException( '$quietMode must be boolean' );
		}

		if ( $quietMode ) {
			return new NullLogger();
		}

		return new StdoutLogger();
	}

}
