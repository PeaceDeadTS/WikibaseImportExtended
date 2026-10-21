<?php

namespace Wikibase\Import;

use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Term\AliasGroup;
use Wikibase\DataModel\Term\AliasGroupList;
use Wikibase\DataModel\Term\FingerprintProvider;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Lib\ContentLanguages;

/**
 * Drops terms the local Wikibase would reject, so one unknown language
 * does not fail the whole entity.
 */
class TermSanitizer {

	/**
	 * @var ContentLanguages
	 */
	private $languages;

	/**
	 * @var int
	 */
	private $maxLength;

	/**
	 * @param ContentLanguages $languages
	 * @param int $maxLength
	 */
	public function __construct( ContentLanguages $languages, $maxLength ) {
		$this->languages = $languages;
		$this->maxLength = (int)$maxLength;
	}

	/**
	 * @param EntityDocument $entity
	 * @return int Number of terms removed
	 */
	public function sanitize( EntityDocument $entity ) {
		if ( !$entity instanceof FingerprintProvider ) {
			return 0;
		}

		$fingerprint = $entity->getFingerprint();
		$dropped = 0;
		$dropped += $this->filterTermList( $fingerprint->getLabels() );
		$dropped += $this->filterTermList( $fingerprint->getDescriptions() );
		$dropped += $this->dropDescriptionsEqualToLabels( $fingerprint->getLabels(), $fingerprint->getDescriptions() );
		$dropped += $this->filterAliases( $fingerprint );

		return $dropped;
	}

	/**
	 * @param TermList $terms
	 * @return int
	 */
	private function filterTermList( TermList $terms ) {
		$remove = [];
		foreach ( $terms->toTextArray() as $language => $text ) {
			if ( !$this->keepText( $language, $text ) ) {
				$remove[] = $language;
			}
		}

		foreach ( $remove as $language ) {
			$terms->removeByLanguage( $language );
		}

		return count( $remove );
	}

	/**
	 * @param TermList $labels
	 * @param TermList $descriptions
	 * @return int
	 */
	private function dropDescriptionsEqualToLabels( TermList $labels, TermList $descriptions ) {
		$dropped = 0;
		foreach ( $labels->toTextArray() as $language => $label ) {
			if (
				$descriptions->hasTermForLanguage( $language )
				&& $descriptions->getByLanguage( $language )->getText() === $label
			) {
				$descriptions->removeByLanguage( $language );
				$dropped++;
			}
		}

		return $dropped;
	}

	/**
	 * @param \Wikibase\DataModel\Term\Fingerprint $fingerprint
	 * @return int
	 */
	private function filterAliases( $fingerprint ) {
		$kept = [];
		$dropped = 0;

		foreach ( $fingerprint->getAliasGroups() as $group ) {
			if ( !$this->languages->hasLanguage( $group->getLanguageCode() ) ) {
				$dropped += count( $group );
				continue;
			}

			$aliases = [];
			foreach ( $group->getAliases() as $alias ) {
				if ( $this->keepText( $group->getLanguageCode(), $alias ) ) {
					$aliases[] = $alias;
				} else {
					$dropped++;
				}
			}

			if ( $aliases !== [] ) {
				$kept[] = new AliasGroup( $group->getLanguageCode(), $aliases );
			}
		}

		$fingerprint->setAliasGroups( new AliasGroupList( $kept ) );

		return $dropped;
	}

	/**
	 * @param string $language
	 * @param string $text
	 * @return bool
	 */
	private function keepText( $language, $text ) {
		if ( !$this->languages->hasLanguage( $language ) ) {
			return false;
		}

		$text = trim( $text );
		if ( $text === '' ) {
			return false;
		}

		return mb_strlen( $text ) <= $this->maxLength;
	}

}
