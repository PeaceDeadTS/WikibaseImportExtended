<?php

namespace Wikibase\Import;

/**
 * Turns dry-run and import log lines into rows for the special page.
 */
class ImportPlanParser {

	private const ID = '[A-Za-z][0-9]+';

	/**
	 * @param list<array{level:string,message:string}> $lines
	 * @return list<array<string,mixed>>
	 */
	public function parse( array $lines ) {
		$rows = [];
		foreach ( $lines as $line ) {
			$message = (string)( $line['message'] ?? '' );
			$this->apply( $rows, $message );
		}

		return array_values( $rows );
	}

	/**
	 * @param array<string,array<string,mixed>> &$rows
	 * @param string $message
	 */
	private function apply( array &$rows, $message ) {
		if ( preg_match(
			'/^DRY-RUN (' . self::ID . ') would write (\d+) statements, skip (\d+)$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['statementsWritten'] = (int)$match[2];
			$row['statementsSkipped'] = (int)$match[3];
			return;
		}

		if ( preg_match(
			'/^(?:DRY-RUN|Creating|Recreating) (\S+) (' . self::ID . ')(?: "([^"]*)")? labels=(\d+) descriptions=(\d+) '
			. 'aliases=(\d+) droppedTerms=(\d+) statements=(.*) sitelinks=(.*)$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[2] );
			$row['type'] = $match[1];
			$row['label'] = $match[3] ?? '';
			$row['labels'] = (int)$match[4];
			$row['descriptions'] = (int)$match[5];
			$row['aliases'] = (int)$match[6];
			$row['droppedTerms'] = (int)$match[7];
			$row['statements'] = $match[8];
			$row['sitelinks'] = $match[9];
			if ( $row['action'] === '' ) {
				$row['action'] = 'create';
			}
			return;
		}

		if ( preg_match( '/^(' . self::ID . ') already imported as (' . self::ID . ')$/', $message, $match ) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['action'] = 'exists';
			$row['localId'] = $match[2];
			return;
		}

		if ( preg_match(
			'/^(' . self::ID . ') mapped to deleted (' . self::ID . '), recreating that page$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['action'] = 'recreate';
			$row['localId'] = $match[2];
			return;
		}

		if ( preg_match(
			'/^Statements already imported for (' . self::ID . ') \((' . self::ID . ')\)$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['action'] = 'statements';
			$row['localId'] = $match[2];
			return;
		}

		if ( preg_match(
			'/^(' . self::ID . '): saved (\d+) statements, skipped (\d+)$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['statementsWritten'] = (int)$match[2];
			$row['statementsSkipped'] = (int)$match[3];
			return;
		}

		if ( preg_match( '/^(' . self::ID . ') created as (' . self::ID . ')$/', $message, $match ) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['action'] = 'created';
			$row['localId'] = $match[2];
			return;
		}

		if ( preg_match( '/^(' . self::ID . ') recreated as (' . self::ID . ')$/', $message, $match ) ) {
			$row =& $this->row( $rows, $match[1] );
			$row['action'] = 'recreated';
			$row['localId'] = $match[2];
			return;
		}

		if ( preg_match(
			'/^(DRY-RUN )?EXTEND (\S+) (' . self::ID . ')(?: "([^"]*)")? local=(' . self::ID . ')'
			. ' labelsAdded=(\d+) descriptionsAdded=(\d+) descriptionsReplaced=(\d+) aliasesAdded=(\d+)'
			. ' statements=(\S+) sitelinks=(.*)$/',
			$message,
			$match
		) ) {
			$row =& $this->row( $rows, $match[3] );
			$row['type'] = $match[2];
			$row['label'] = $match[4] ?? '';
			$row['localId'] = $match[5];
			$row['labels'] = (int)$match[6];
			$row['descriptions'] = (int)$match[7] + (int)$match[8];
			$row['aliases'] = (int)$match[9];
			$row['action'] = $match[1] !== '' ? 'extend' : 'extended';
			$row['sitelinks'] = $match[11];
			if ( $match[10] === 'off' ) {
				$row['statements'] = '0';
				return;
			}
			if ( preg_match( '/^add:(\d+),merge:(\d+),skip:(\d+)$/', $match[10], $counts ) ) {
				$row['statementsWritten'] = (int)$counts[1];
				$row['statementsMerged'] = (int)$counts[2];
				$row['statementsSkipped'] = (int)$counts[3];
			}
		}
	}

	/**
	 * @param array<string,array<string,mixed>> &$rows
	 * @param string $id
	 * @return array<string,mixed>
	 */
	private function &row( array &$rows, $id ) {
		if ( !isset( $rows[$id] ) ) {
			$rows[$id] = [
				'id' => $id,
				'type' => '',
				'label' => '',
				'action' => '',
				'labels' => null,
				'descriptions' => null,
				'aliases' => null,
				'droppedTerms' => null,
				'statements' => '',
				'sitelinks' => '',
				'statementsWritten' => null,
				'statementsMerged' => null,
				'statementsSkipped' => null,
				'localId' => '',
			];
		}

		return $rows[$id];
	}

}
