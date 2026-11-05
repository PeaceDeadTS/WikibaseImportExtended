<?php

namespace Wikibase\Import;

use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Repo\Content\ItemContent;
use Wikibase\Repo\Content\PropertyContent;
use Wikibase\Repo\WikibaseRepo;

/**
 * Collapsed extend control on a local item or property page.
 * Statements and sitelinks stay on Special:WikibaseImport. The edit account is the default.
 */
class EntityExtendHotbox {

	/**
	 * @param OutputPage $out
	 * @param \MediaWiki\Skin\Skin $skin
	 */
	public static function onBeforePageDisplay( $out, $skin ) {
		unset( $skin );
		if ( self::localEntityId( $out ) === null ) {
			return;
		}

		$out->addModuleStyles( [
			'ext.wikibaseimport.styles',
			'ext.wikibaseimport.hotbox.styles',
		] );
		$out->addModules( 'ext.wikibaseimport.hotbox' );
	}

	/**
	 * @param OutputPage $out
	 * @param string &$html
	 */
	public static function onOutputPageBeforeHTML( $out, &$html ) {
		$localId = self::localEntityId( $out );
		if ( $localId === null ) {
			return;
		}

		$box = self::markup( $out, $localId );
		if ( preg_match(
			'/<div\b[^>]*\bclass="wikibase-entityview-main"[^>]*>/',
			$html,
			$match,
			PREG_OFFSET_CAPTURE
		) ) {
			$insertAt = $match[0][1] + strlen( $match[0][0] );
			$html = substr( $html, 0, $insertAt ) . $box . substr( $html, $insertAt );
			return;
		}

		$html = $box . $html;
	}

	/**
	 * Item or property id of the page being viewed, or null when the box stays off.
	 *
	 * @param OutputPage $out
	 * @return string|null
	 */
	private static function localEntityId( OutputPage $out ) {
		if ( !$out->getAuthority()->isAllowed( 'wikibaseimport' ) ) {
			return null;
		}
		if ( !$out->isArticle() || $out->isPrintable() ) {
			return null;
		}

		$request = $out->getRequest();
		if ( $request->getVal( 'diff' ) !== null || $request->getInt( 'oldid' ) > 0 ) {
			return null;
		}

		$title = $out->getTitle();
		if ( $title === null ) {
			return null;
		}

		$model = $title->getContentModel();
		if ( $model !== ItemContent::CONTENT_MODEL_ID && $model !== PropertyContent::CONTENT_MODEL_ID ) {
			return null;
		}

		try {
			$entityId = WikibaseRepo::getEntityIdLookup()->getEntityIdForTitle( $title );
		} catch ( \Throwable $ex ) {
			return null;
		}
		if ( !$entityId instanceof EntityId ) {
			return null;
		}

		$localId = $entityId->getSerialization();
		if ( !preg_match( '/^[QP]\d+$/', $localId ) ) {
			return null;
		}

		return $localId;
	}

	/**
	 * @param OutputPage $out
	 * @param string $localId
	 * @return string
	 */
	private static function markup( OutputPage $out, $localId ) {
		$placeholder = $localId[0] === 'P' ? 'P31' : 'Q824909';
		$title = $out->msg( 'wikibaseimport-hotbox-title' )->text();

		$toggle = Html::rawElement(
			'button',
			[
				'type' => 'button',
				'class' => 'wbimport-hotbox__toggle',
				'aria-expanded' => 'false',
				'aria-controls' => 'wbimport-hotbox-panel',
			],
			Html::element( 'span', [ 'class' => 'wbimport-hotbox__chevron', 'aria-hidden' => 'true' ] )
			. Html::element( 'span', [ 'class' => 'wbimport-hotbox__title' ], $title )
			. Html::element( 'span', [ 'class' => 'wbimport-hotbox__id' ], $localId )
		);

		$source = Html::rawElement(
			'div',
			[ 'class' => 'wbimport-hotbox__source' ],
			Html::element(
				'label',
				[
					'class' => 'wbimport-hotbox__source-label',
					'for' => 'wbimport-hotbox-entity',
				],
				$out->msg( 'wikibaseimport-entity-label' )->text()
			)
			. Html::rawElement(
				'div',
				[ 'class' => 'cdx-text-input wbimport-hotbox__input' ],
				Html::element( 'input', [
					'type' => 'text',
					'id' => 'wbimport-hotbox-entity',
					'class' => 'cdx-text-input__input',
					'placeholder' => $placeholder,
					'spellcheck' => 'false',
					'autocomplete' => 'off',
					'autocapitalize' => 'characters',
				] )
			)
			. Html::element( 'button', [
				'type' => 'submit',
				'id' => 'wbimport-hotbox-check',
				'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
			], $out->msg( 'wikibaseimport-check' )->text() )
			. Html::element( 'button', [
				'type' => 'button',
				'id' => 'wbimport-hotbox-import',
				'class' => 'cdx-button cdx-button--action-progressive',
				'hidden' => 'hidden',
			], $out->msg( 'wikibaseimport-extend-button' )->text() )
		);

		$form = Html::rawElement(
			'form',
			[
				'id' => 'wbimport-hotbox-form',
				'novalidate' => 'novalidate',
			],
			$source
			. Html::rawElement(
				'div',
				[ 'class' => 'wbimport-hotbox__options' ],
				self::checkbox(
					$out,
					'wbimport-hotbox-replace-description',
					'wikibaseimport-replace-description-label'
				)
				. self::checkbox(
					$out,
					'wbimport-hotbox-add-aliases',
					'wikibaseimport-add-aliases-label'
				)
			)
		);

		$body = Html::rawElement(
			'fieldset',
			[
				'class' => 'wbimport-hotbox__body',
				'disabled' => 'disabled',
			],
			$form
			. Html::element(
				'p',
				[ 'class' => 'wbimport-hotbox__hint' ],
				$out->msg( 'wikibaseimport-hotbox-terms-only' )->text()
			)
			. Html::rawElement(
				'div',
				[
					'id' => 'wbimport-hotbox-pending',
					'class' => 'wbimport-hotbox__pending',
					'hidden' => 'hidden',
				],
				Html::element( 'p', [
					'id' => 'wbimport-hotbox-pending-text',
					'class' => 'wbimport-hotbox__pending-text',
				], '' )
				. '<div class="cdx-progress-bar cdx-progress-bar--block" role="progressbar">'
				. '<div class="cdx-progress-bar__bar"></div></div>'
			)
			. Html::element( 'div', [
				'id' => 'wbimport-hotbox-result',
				'class' => 'wbimport-hotbox__result',
				'aria-live' => 'polite',
			] )
		);

		$panel = Html::rawElement(
			'div',
			[
				'class' => 'wbimport-hotbox__clip',
				'id' => 'wbimport-hotbox-panel',
			],
			Html::rawElement(
				'div',
				[
					'class' => 'wbimport-hotbox__panel',
					'inert' => 'inert',
				],
				$body
			)
		);

		return Html::rawElement(
			'div',
			[
				'id' => 'wbimport-hotbox',
				'class' => 'wbimport-hotbox',
				'data-local-id' => $localId,
			],
			$toggle . $panel
		);
	}

	/**
	 * @param OutputPage $out
	 * @param string $id
	 * @param string $labelMsg
	 * @return string
	 */
	private static function checkbox( OutputPage $out, $id, $labelMsg ) {
		return Html::rawElement(
			'span',
			[ 'class' => 'cdx-checkbox' ],
			Html::element( 'input', [
				'type' => 'checkbox',
				'id' => $id,
				'class' => 'cdx-checkbox__input',
				'checked' => 'checked',
			] )
			. Html::element( 'span', [ 'class' => 'cdx-checkbox__icon' ] )
			. Html::element(
				'label',
				[ 'class' => 'cdx-checkbox__label', 'for' => $id ],
				$out->msg( $labelMsg )->text()
			)
		);
	}

}
