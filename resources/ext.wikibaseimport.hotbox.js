( function () {
	'use strict';

	var root = document.getElementById( 'wbimport-hotbox' );
	if ( !root ) {
		return;
	}

	var localId = root.getAttribute( 'data-local-id' ) || '';
	var toggle = root.querySelector( '.wbimport-hotbox__toggle' );
	var panel = root.querySelector( '.wbimport-hotbox__panel' );
	var body = root.querySelector( '.wbimport-hotbox__body' );
	var form = document.getElementById( 'wbimport-hotbox-form' );
	var entityInput = document.getElementById( 'wbimport-hotbox-entity' );
	var replaceInput = document.getElementById( 'wbimport-hotbox-replace-description' );
	var aliasesInput = document.getElementById( 'wbimport-hotbox-add-aliases' );
	var checkButton = document.getElementById( 'wbimport-hotbox-check' );
	var importButton = document.getElementById( 'wbimport-hotbox-import' );
	var pending = document.getElementById( 'wbimport-hotbox-pending' );
	var pendingText = document.getElementById( 'wbimport-hotbox-pending-text' );
	var result = document.getElementById( 'wbimport-hotbox-result' );
	var checkedKey = '';
	var running = false;
	var reloading = false;

	function normalizeEntity( value ) {
		value = value.trim();
		var fromUrl = /(?:wiki|entity)\/(?:Property:)?([qp]\d+)\b/i.exec( value );
		if ( fromUrl ) {
			return fromUrl[ 1 ].toUpperCase();
		}
		if ( /^[qp]\d+$/i.test( value ) ) {
			return value.toUpperCase();
		}
		return value;
	}

	function requestKey() {
		return [
			localId,
			normalizeEntity( entityInput.value ),
			replaceInput.checked ? '1' : '0',
			aliasesInput.checked ? '1' : '0'
		].join( '\n' );
	}

	function setOpen( open ) {
		if ( open ) {
			body.disabled = false;
			panel.inert = false;
			root.classList.add( 'wbimport-hotbox--open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
			entityInput.focus();
			return;
		}
		if ( panel.contains( document.activeElement ) ) {
			toggle.focus();
		}
		root.classList.remove( 'wbimport-hotbox--open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		window.setTimeout( function () {
			if ( !root.classList.contains( 'wbimport-hotbox--open' ) ) {
				body.disabled = true;
				panel.inert = true;
			}
		}, 260 );
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
		return el( 'div', {
			class: 'cdx-message cdx-message--' + type,
			role: type === 'error' ? 'alert' : 'status'
		}, [
			el( 'span', { class: 'cdx-message__icon' } ),
			el( 'div', { class: 'cdx-message__content' }, lines.map( function ( line ) {
				return el( 'div', {}, [ line ] );
			} ) )
		] );
	}

	function serverOn( data, paramName, boolName ) {
		if ( data[ paramName ] === '1' ) {
			return true;
		}
		return data[ boolName ] === true;
	}

	function optionsMatch( data ) {
		return ( data.operation || 'new' ) === 'extend' &&
			( data.localentity || '' ) === localId &&
			( data.replacedescriptionparam || '0' ) === ( replaceInput.checked ? '1' : '0' ) &&
			( data.addaliasesparam || '0' ) === ( aliasesInput.checked ? '1' : '0' ) &&
			serverOn( data, 'statementsparam', 'statements' ) === false &&
			serverOn( data, 'sitelinksparam', 'sitelinks' ) === false;
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
		return errors.length ? errors.slice( 0, 4 ).join( '\n' ) : fallback;
	}

	function warningLines( data ) {
		return ( data.log || [] ).filter( function ( line ) {
			return line.level === 'warning' && line.message;
		} ).map( function ( line ) {
			return line.message;
		} ).slice( 0, 4 );
	}

	function deltaText( data ) {
		var lines = data.log || [];
		var i, match;
		for ( i = 0; i < lines.length; i++ ) {
			match = /^(?:DRY-RUN )?EXTEND \S+ [A-Za-z][0-9]+(?: "[^"]*")? local=[A-Za-z][0-9]+ labelsAdded=(\d+) descriptionsAdded=(\d+) descriptionsReplaced=(\d+) aliasesAdded=(\d+)/.exec( lines[ i ].message || '' );
			if ( !match ) {
				continue;
			}
			var parts = [];
			if ( match[ 1 ] !== '0' ) {
				parts.push( mw.msg( 'wikibaseimport-hotbox-labels', match[ 1 ] ) );
			}
			if ( match[ 2 ] !== '0' ) {
				parts.push( mw.msg( 'wikibaseimport-hotbox-descriptions', match[ 2 ] ) );
			}
			if ( match[ 3 ] !== '0' ) {
				parts.push( mw.msg( 'wikibaseimport-hotbox-replaced', match[ 3 ] ) );
			}
			if ( match[ 4 ] !== '0' ) {
				parts.push( mw.msg( 'wikibaseimport-hotbox-aliases', match[ 4 ] ) );
			}
			return parts.length ? parts.join( ' · ' ) : mw.msg( 'wikibaseimport-hotbox-delta-none' );
		}
		return '';
	}

	function render( data, step ) {
		result.textContent = '';
		var sourceId = normalizeEntity( entityInput.value );
		var type = 'error';
		var lines;
		var matched = !!data.ok && optionsMatch( data );
		if ( data.ok && !matched ) {
			lines = [ mw.msg( 'wikibaseimport-extend-mismatch' ) ];
		} else if ( !data.ok ) {
			lines = String( errorText(
				data,
				mw.msg( step === 'import' ? 'wikibaseimport-import-error' : 'wikibaseimport-check-error' )
			) ).split( '\n' ).filter( function ( line ) {
				return line !== '';
			} );
		} else {
			type = data.haswarning ? 'warning' : 'success';
			lines = [ mw.msg(
				step === 'import' ? 'wikibaseimport-hotbox-import-ok' : 'wikibaseimport-hotbox-check-ok',
				sourceId
			) ];
			var delta = deltaText( data );
			if ( delta ) {
				lines.push( delta );
			}
			var warnings = warningLines( data );
			warnings.forEach( function ( line ) {
				lines.push( line );
			} );
			if ( data.haswarning && !warnings.length ) {
				lines.push( mw.msg( 'wikibaseimport-hotbox-warnings' ) );
			}
		}
		result.appendChild( messageBox( type, lines ) );
		if ( step === 'check' && data.ok && matched ) {
			checkedKey = requestKey();
			showImport();
		} else {
			hideImport();
		}
	}

	function showError( key ) {
		result.textContent = '';
		result.appendChild( messageBox( 'error', [ mw.msg( key ) ] ) );
		hideImport();
	}

	function sourceError() {
		var sourceId = normalizeEntity( entityInput.value );
		if ( sourceId === '' ) {
			return 'wikibaseimport-empty-entity';
		}
		if ( !/^[QP]\d+$/.test( sourceId ) || !/^[QP]\d+$/.test( localId ) ) {
			return 'wikibaseimport-bad-id';
		}
		if ( localId.charAt( 0 ) !== sourceId.charAt( 0 ) ) {
			return 'wikibaseimport-id-mismatch';
		}
		return '';
	}

	function post( step ) {
		var sourceId = normalizeEntity( entityInput.value );
		if ( /^[QP]\d+$/.test( sourceId ) ) {
			entityInput.value = sourceId;
		}
		return ( new mw.Api() ).postWithToken( 'csrf', {
			action: 'wikibaseimport',
			formatversion: 2,
			step: step,
			operation: 'extend',
			entity: sourceId,
			localentity: localId,
			user: '',
			statements: '0',
			sitelinks: '0',
			replacedescription: replaceInput.checked ? '1' : '0',
			addaliases: aliasesInput.checked ? '1' : '0'
		} );
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

	toggle.addEventListener( 'click', function () {
		setOpen( !root.classList.contains( 'wbimport-hotbox--open' ) );
	} );

	root.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && root.classList.contains( 'wbimport-hotbox--open' ) ) {
			event.preventDefault();
			setOpen( false );
		}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( running ) {
			return;
		}
		var invalid = sourceError();
		if ( invalid ) {
			showError( invalid );
			return;
		}
		setBusy( true, mw.msg( 'wikibaseimport-pending-check' ) );
		post( 'check' ).then( function ( data ) {
			render( data.wikibaseimport || {}, 'check' );
		}, fail ).always( function () {
			if ( !reloading ) {
				setBusy( false );
			}
		} );
	} );

	importButton.addEventListener( 'click', function () {
		if ( running ) {
			return;
		}
		if ( requestKey() !== checkedKey ) {
			showError( 'wikibaseimport-stale' );
			return;
		}
		setBusy( true, mw.msg( 'wikibaseimport-pending-import' ) );
		post( 'import' ).then( function ( data ) {
			var payload = data.wikibaseimport || {};
			var sameForm = requestKey() === checkedKey && optionsMatch( payload );
			render( payload, 'import' );
			if ( payload.ok && !payload.haswarning && sameForm ) {
				reloading = true;
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			}
		}, fail ).always( function () {
			if ( !reloading ) {
				setBusy( false );
			}
		} );
	} );

	[ entityInput, replaceInput, aliasesInput ].forEach( function ( input ) {
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
