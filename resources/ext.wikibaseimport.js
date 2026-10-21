( function () {
	'use strict';

	var form = document.getElementById( 'wbimport-form' );
	if ( !form ) {
		return;
	}

	var entityInput = document.getElementById( 'wbimport-entity' );
	var userInput = document.getElementById( 'wbimport-user' );
	var statementsInput = document.getElementById( 'wbimport-statements' );
	var sitelinksInput = document.getElementById( 'wbimport-sitelinks' );
	var checkButton = document.getElementById( 'wbimport-check' );
	var importButton = document.getElementById( 'wbimport-import' );
	var pending = document.getElementById( 'wbimport-pending' );
	var pendingText = document.getElementById( 'wbimport-pending-text' );
	var result = document.getElementById( 'wbimport-result' );
	var checkedKey = '';
	var running = false;

	function normalizeEntity( value ) {
		value = value.trim();
		if ( /^[qp]\d+$/i.test( value ) ) {
			return value.toUpperCase();
		}
		return value;
	}

	function requestKey() {
		return [
			normalizeEntity( entityInput.value ),
			userInput.value.trim(),
			statementsInput.checked ? '1' : '0',
			sitelinksInput.checked ? '1' : '0'
		].join( '\n' );
	}

	function hideImport() {
		importButton.hidden = true;
		checkButton.classList.add( 'cdx-button--weight-primary' );
		checkedKey = '';
	}

	function showImport() {
		importButton.hidden = false;
		importButton.classList.add( 'cdx-button--weight-primary' );
		checkButton.classList.remove( 'cdx-button--weight-primary' );
	}

	function setBusy( busy, message ) {
		running = busy;
		checkButton.disabled = busy;
		importButton.disabled = busy;
		pending.hidden = !busy;
		pendingText.textContent = busy ? message : '';
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( name ) {
			if ( attrs[ name ] !== null && attrs[ name ] !== undefined ) {
				node.setAttribute( name, attrs[ name ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined || child === '' ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function messageBox( type, lines ) {
		var box = el( 'div', {
			class: 'cdx-message cdx-message--' + type,
			role: type === 'error' ? 'alert' : 'status'
		}, [
			el( 'span', { class: 'cdx-message__icon' } ),
			el( 'div', { class: 'cdx-message__content' }, lines.map( function ( line ) {
				return el( 'div', {}, [ line ] );
			} ) )
		] );
		return box;
	}

	function serverOn( data, paramName, boolName ) {
		if ( data[ paramName ] === '1' ) {
			return true;
		}
		return data[ boolName ] === true;
	}

	function flagsMatch( data ) {
		return serverOn( data, 'statementsparam', 'statements' ) === statementsInput.checked &&
			serverOn( data, 'sitelinksparam', 'sitelinks' ) === sitelinksInput.checked;
	}

	function onOff( on ) {
		return mw.msg( on ? 'wikibaseimport-on' : 'wikibaseimport-off' );
	}

	function userText( data ) {
		var name = data.user || '';
		if ( data.bot ) {
			name += mw.msg( 'wikibaseimport-bot-flag' );
		}
		return name;
	}

	function errorText( data, fallback ) {
		if ( data.error ) {
			return data.error;
		}
		var errors = ( data.log || [] ).filter( function ( line ) {
			return line.level === 'error' || line.level === 'critical' ||
				line.level === 'alert' || line.level === 'emergency';
		} ).map( function ( line ) {
			return line.message;
		} );
		return errors.length ? errors.join( '\n' ) : fallback;
	}

	function link( href, text ) {
		return el( 'a', { href: href }, [ text ] );
	}

	function termsText( entity ) {
		if ( entity.labels === null || entity.labels === undefined ) {
			return '';
		}
		return entity.labels + ' / ' + entity.descriptions + ' / ' + entity.aliases;
	}

	function statementsText( entity ) {
		if ( entity.statementswritten !== null && entity.statementswritten !== undefined ) {
			return mw.msg(
				'wikibaseimport-statements-plan',
				entity.statementswritten,
				entity.statementsskipped
			);
		}
		return entity.statements || '';
	}

	function actionText( action ) {
		var key = {
			create: 'wikibaseimport-action-create',
			exists: 'wikibaseimport-action-exists',
			created: 'wikibaseimport-action-created',
			recreate: 'wikibaseimport-action-recreate',
			recreated: 'wikibaseimport-action-recreated',
			statements: 'wikibaseimport-action-statements'
		}[ action ];
		return key ? mw.msg( key ) : action;
	}

	function buildTable( entities ) {
		var rows = entities.map( function ( entity ) {
			var source = entity.sourceurl ? link( entity.sourceurl, entity.id ) : entity.id;
			var local = '';
			if ( entity.localid ) {
				local = entity.localurl ? link( entity.localurl, entity.localid ) : entity.localid;
			}
			return el( 'tr', {}, [
				el( 'td', {}, [ source ] ),
				el( 'td', {}, [ entity.label || '' ] ),
				el( 'td', {}, [ actionText( entity.action ) ] ),
				el( 'td', {}, [ termsText( entity ) ] ),
				el( 'td', {}, [ statementsText( entity ) ] ),
				el( 'td', {}, [ entity.sitelinks || '' ] ),
				el( 'td', {}, [ local ] )
			] );
		} );
		return el( 'div', { class: 'cdx-table' }, [
			el( 'div', { class: 'cdx-table__table-wrapper' }, [
				el( 'table', { class: 'cdx-table__table' }, [
					el( 'thead', {}, [
						el( 'tr', {}, [
							'wikibaseimport-col-id',
							'wikibaseimport-col-label',
							'wikibaseimport-col-action',
							'wikibaseimport-col-terms',
							'wikibaseimport-col-statements',
							'wikibaseimport-col-sitelinks',
							'wikibaseimport-col-local'
						].map( function ( key ) {
							return el( 'th', {}, [ mw.msg( key ) ] );
						} ) )
					] ),
					el( 'tbody', {}, rows )
				] )
			] )
		] );
	}

	function buildLog( lines ) {
		var body = el( 'div', { class: 'wbimport-log', role: 'log' }, lines.map( function ( line ) {
			var text = line.level === 'output' ? line.message : String( line.level ).toUpperCase() + ' ' + line.message;
			var cls = 'wbimport-log__line';
			if ( line.level === 'error' || line.level === 'critical' || line.level === 'alert' || line.level === 'emergency' ) {
				cls += ' wbimport-log__line--error';
			} else if ( line.level === 'warning' ) {
				cls += ' wbimport-log__line--warning';
			}
			return el( 'div', { class: cls }, [ text ] );
		} ) );
		return el( 'div', { class: 'cdx-field' }, [
			el( 'div', { class: 'cdx-label' }, [
				el( 'span', { class: 'cdx-label__label' }, [
					el( 'span', { class: 'cdx-label__label__text' }, [ mw.msg( 'wikibaseimport-log' ) ] )
				] )
			] ),
			body
		] );
	}

	function render( data, step ) {
		result.textContent = '';
		var type = 'error';
		var summary;
		var mismatch = step === 'check' && !!data.ok && !flagsMatch( data );
		if ( mismatch ) {
			type = 'error';
			summary = mw.msg(
				'wikibaseimport-mode-mismatch',
				onOff( serverOn( data, 'statementsparam', 'statements' ) ),
				onOff( statementsInput.checked ),
				onOff( serverOn( data, 'sitelinksparam', 'sitelinks' ) ),
				onOff( sitelinksInput.checked )
			);
		} else if ( data.ok && data.haswarning ) {
			type = 'warning';
			summary = mw.msg(
				step === 'import' ? 'wikibaseimport-import-warn' : 'wikibaseimport-check-warn',
				data.sourcecount,
				userText( data )
			);
		} else if ( data.ok ) {
			type = 'success';
			summary = mw.msg(
				step === 'import' ? 'wikibaseimport-import-ok' : 'wikibaseimport-check-ok',
				data.sourcecount,
				userText( data )
			);
		} else {
			summary = errorText(
				data,
				mw.msg( step === 'import' ? 'wikibaseimport-import-error' : 'wikibaseimport-check-error' )
			);
		}
		var lines = String( summary ).split( '\n' ).filter( function ( line ) {
			return line !== '';
		} );
		if ( !mismatch && ( data.ok || data.sourcecount ) ) {
			var copyStatements = serverOn( data, 'statementsparam', 'statements' );
			lines.push( mw.msg( copyStatements ? 'wikibaseimport-mode-statements' : 'wikibaseimport-mode-terms' ) );
			var related = ( data.entities ? data.entities.length : 0 ) - ( data.sourcecount || 0 );
			if ( copyStatements && related > 0 ) {
				lines.push( mw.msg( 'wikibaseimport-related-count', related ) );
			}
		}
		result.appendChild( messageBox( type, lines ) );
		if ( data.entities && data.entities.length ) {
			result.appendChild( buildTable( data.entities ) );
		}
		if ( data.log && data.log.length ) {
			result.appendChild( buildLog( data.log ) );
		}
		if ( step === 'check' && data.ok && !mismatch ) {
			checkedKey = requestKey();
			showImport();
		} else {
			hideImport();
		}
	}

	function fail( code, data ) {
		var text = mw.msg( 'wikibaseimport-request-failed' );
		if ( data && data.error && data.error.info ) {
			text = data.error.info;
		}
		result.textContent = '';
		result.appendChild( messageBox( 'error', [ text ] ) );
		hideImport();
	}

	function post( step ) {
		var api = new mw.Api();
		return api.postWithToken( 'csrf', {
			action: 'wikibaseimport',
			formatversion: 2,
			step: step,
			entity: normalizeEntity( entityInput.value ),
			user: userInput.value.trim(),
			statements: statementsInput.checked ? '1' : '0',
			sitelinks: sitelinksInput.checked ? '1' : '0'
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( running ) {
			return;
		}
		if ( normalizeEntity( entityInput.value ) === '' ) {
			result.textContent = '';
			result.appendChild( messageBox( 'error', [ mw.msg( 'wikibaseimport-empty-entity' ) ] ) );
			hideImport();
			return;
		}
		setBusy( true, mw.msg( 'wikibaseimport-pending-check' ) );
		post( 'check' ).then( function ( data ) {
			render( data.wikibaseimport || {}, 'check' );
		}, fail ).always( function () {
			setBusy( false );
		} );
	} );

	importButton.addEventListener( 'click', function () {
		if ( running ) {
			return;
		}
		if ( requestKey() !== checkedKey ) {
			result.textContent = '';
			result.appendChild( messageBox( 'error', [ mw.msg( 'wikibaseimport-stale' ) ] ) );
			hideImport();
			return;
		}
		setBusy( true, mw.msg( 'wikibaseimport-pending-import' ) );
		post( 'import' ).then( function ( data ) {
			render( data.wikibaseimport || {}, 'import' );
		}, fail ).always( function () {
			setBusy( false );
		} );
	} );

	[ entityInput, userInput, statementsInput, sitelinksInput ].forEach( function ( input ) {
		input.addEventListener( 'input', function () {
			if ( checkedKey && requestKey() !== checkedKey ) {
				hideImport();
			}
		} );
		input.addEventListener( 'change', function () {
			if ( checkedKey && requestKey() !== checkedKey ) {
				hideImport();
			}
		} );
	} );
}() );
