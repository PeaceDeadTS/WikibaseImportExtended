<?php

namespace Wikibase\Import\Special;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\Import\ImportUserResolver;

/**
 * Check, then import, one source entity. Check is the same dry-run as the CLI.
 */
class SpecialWikibaseImport extends SpecialPage {

	public function __construct() {
		parent::__construct( 'WikibaseImport', 'wikibaseimport' );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikibase';
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();

		$out = $this->getOutput();
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->addModuleStyles( 'ext.wikibaseimport.styles' );
		$out->addModules( 'ext.wikibaseimport' );
		$out->addHTML( $this->buildForm() );
	}

	/**
	 * @return string
	 */
	private function buildForm() {
		$config = $this->getConfig();
		$user = ImportUserResolver::configuredName( $config->get( 'WBImportUser' ) );

		$html = Html::element(
			'p',
			[ 'class' => 'wbimport-intro' ],
			$this->msg( 'wikibaseimport-summary' )->text()
		);
		$html .= Html::rawElement(
			'noscript',
			[],
			Html::element( 'p', [], $this->msg( 'wikibaseimport-noscript' )->text() )
		);

		$html .= Html::openElement( 'form', [
			'id' => 'wbimport-form',
			'class' => 'wbimport-form',
			'novalidate' => 'novalidate',
		] );
		$html .= $this->textField(
			'wbimport-entity',
			'wikibaseimport-entity-label',
			'wikibaseimport-entity-description',
			'',
			'Q824909'
		);
		$html .= $this->textField(
			'wbimport-user',
			'wikibaseimport-user-label',
			'wikibaseimport-user-description',
			$user,
			$user
		);
		$html .= Html::openElement( 'div', [ 'class' => 'wbimport-options' ] );
		$html .= $this->checkbox(
			'wbimport-statements',
			'wikibaseimport-statements-label',
			'wikibaseimport-statements-description',
			(bool)$config->get( 'WBImportStatements' )
		);
		$html .= $this->checkbox(
			'wbimport-sitelinks',
			'wikibaseimport-sitelinks-label',
			'wikibaseimport-sitelinks-description',
			(bool)$config->get( 'WBImportSitelinks' )
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::rawElement(
			'div',
			[ 'class' => 'wbimport-actions' ],
			Html::element( 'button', [
				'type' => 'submit',
				'id' => 'wbimport-check',
				'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
			], $this->msg( 'wikibaseimport-check' )->text() )
			. Html::element( 'button', [
				'type' => 'button',
				'id' => 'wbimport-import',
				'class' => 'cdx-button cdx-button--action-progressive',
				'hidden' => 'hidden',
			], $this->msg( 'wikibaseimport-import' )->text() )
		);
		$html .= Html::closeElement( 'form' );

		$html .= Html::rawElement(
			'div',
			[
				'id' => 'wbimport-pending',
				'class' => 'wbimport-pending',
				'hidden' => 'hidden',
			],
			Html::element( 'p', [ 'id' => 'wbimport-pending-text', 'class' => 'wbimport-pending-text' ], '' )
			. '<div class="cdx-progress-bar cdx-progress-bar--block" role="progressbar">'
			. '<div class="cdx-progress-bar__bar"></div></div>'
		);
		$html .= Html::element( 'div', [
			'id' => 'wbimport-result',
			'class' => 'wbimport-result',
			'aria-live' => 'polite',
		] );

		return $html;
	}

	/**
	 * @param string $id
	 * @param string $labelMsg
	 * @param string $descriptionMsg
	 * @param string $value
	 * @param string $placeholder
	 * @return string
	 */
	private function textField( $id, $labelMsg, $descriptionMsg, $value, $placeholder ) {
		return Html::rawElement(
			'div',
			[ 'class' => 'cdx-field' ],
			Html::rawElement(
				'div',
				[ 'class' => 'cdx-label' ],
				Html::rawElement(
					'label',
					[ 'class' => 'cdx-label__label', 'for' => $id ],
					Html::element(
						'span',
						[ 'class' => 'cdx-label__label__text' ],
						$this->msg( $labelMsg )->text()
					)
				)
				. Html::element(
					'span',
					[ 'class' => 'cdx-label__description' ],
					$this->msg( $descriptionMsg )->text()
				)
			)
			. Html::rawElement(
				'div',
				[ 'class' => 'cdx-text-input' ],
				Html::element( 'input', [
					'type' => 'text',
					'id' => $id,
					'class' => 'cdx-text-input__input',
					'value' => $value,
					'placeholder' => $placeholder,
					'spellcheck' => 'false',
					'autocomplete' => 'off',
				] )
			)
		);
	}

	/**
	 * @param string $id
	 * @param string $labelMsg
	 * @param string $descriptionMsg
	 * @param bool $checked
	 * @return string
	 */
	private function checkbox( $id, $labelMsg, $descriptionMsg, $checked ) {
		$attrs = [
			'type' => 'checkbox',
			'id' => $id,
			'class' => 'cdx-checkbox__input',
		];
		if ( $checked ) {
			$attrs['checked'] = 'checked';
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'wbimport-option' ],
			Html::rawElement(
				'span',
				[ 'class' => 'cdx-checkbox' ],
				Html::element( 'input', $attrs )
				. Html::element( 'span', [ 'class' => 'cdx-checkbox__icon' ] )
				. Html::element(
					'label',
					[ 'class' => 'cdx-checkbox__label', 'for' => $id ],
					$this->msg( $labelMsg )->text()
				)
			)
			. Html::rawElement(
				'div',
				[ 'class' => 'cdx-label wbimport-option__description' ],
				Html::element(
					'span',
					[ 'class' => 'cdx-label__description' ],
					$this->msg( $descriptionMsg )->text()
				)
			)
		);
	}

}
