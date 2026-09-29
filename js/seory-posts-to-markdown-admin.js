( function () {
	'use strict';

	var settings = window.seorycoWpmd || {};
	var i18n = settings.i18n || {};

	var button = document.getElementById( 'seoryco-wpmd-convert' );
	var form = document.getElementById( 'seoryco-wpmd-form' );
	var progressWrap = document.getElementById( 'seoryco-wpmd-progress' );
	var progressBar = progressWrap ? progressWrap.querySelector( '.seoryco-wpmd-progress-bar' ) : null;
	var progressText = progressWrap ? progressWrap.querySelector( '.seoryco-wpmd-progress-text' ) : null;
	var errorBox = document.getElementById( 'seoryco-wpmd-error' );
	var noticeBox = document.getElementById( 'seoryco-wpmd-notice' );
	var resultBox = document.getElementById( 'seoryco-wpmd-result' );

	if ( ! button || ! form ) {
		return;
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( template ).replace( /%(\d+)\$s|%s/g, function ( match, position ) {
			if ( position ) {
				return String( args[ parseInt( position, 10 ) - 1 ] );
			}
			return String( args[ index++ ] );
		} );
	}

	function setBoxText( box, message ) {
		if ( box ) {
			box.querySelector( 'p' ).textContent = message || '';
			box.hidden = ! message;
		}
	}

	function setProgress( processed, total ) {
		if ( ! progressWrap ) {
			return;
		}
		progressWrap.hidden = false;
		var percent = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
		if ( progressBar ) {
			progressBar.style.width = percent + '%';
		}
		if ( progressText ) {
			progressText.textContent = sprintf( i18n.progress || '%1$s / %2$s', processed, total ) + ' (' + percent + '%)';
		}
	}

	function setBusy( busy ) {
		button.disabled = busy;
		button.textContent = busy ? ( i18n.converting || 'Converting…' ) : ( i18n.convert || 'Convert and download ZIP' );
	}

	function request( action, extra ) {
		var data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', settings.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			var value = extra[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					data.append( key + '[]', item );
				} );
			} else {
				data.append( key, value );
			}
		} );

		return window
			.fetch( settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || payload.success !== true ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : ( i18n.failed || 'Export failed.' );
					throw new Error( message );
				}
				return payload.data;
			} );
	}

	function collectOptions() {
		var options = {
			post_types: [],
			content_mode: 'raw',
			date_from: '',
			date_to: '',
			date_folders: 'none'
		};

		form.querySelectorAll( 'input[name="post_types[]"]:checked' ).forEach( function ( input ) {
			options.post_types.push( input.value );
		} );

		var mode = form.querySelector( 'input[name="content_mode"]:checked' );
		if ( mode ) {
			options.content_mode = mode.value;
		}

		[ 'include_drafts', 'post_folders', 'prefix_date', 'include_index' ].forEach( function ( name ) {
			var input = form.querySelector( 'input[name="' + name + '"]' );
			if ( input && input.checked ) {
				options[ name ] = '1';
			}
		} );

		form.querySelectorAll( 'select.seoryco-wpmd-tax-select' ).forEach( function ( sel ) {
			if ( ! sel.disabled && sel.value !== '0' ) {
				options[ sel.name ] = sel.value;
			}
		} );

		options.date_folders = form.querySelector( 'select[name="date_folders"]' ).value;
		options.date_from = form.querySelector( 'input[name="date_from"]' ).value;
		options.date_to = form.querySelector( 'input[name="date_to"]' ).value;

		return options;
	}

	// Grey out a type's taxonomy filters while the type itself is unchecked.
	form.querySelectorAll( '.seoryco-wpmd-type' ).forEach( function ( block ) {
		var box = block.querySelector( 'input[name="post_types[]"]' );
		if ( ! box ) {
			return;
		}
		var toggle = function () {
			block.classList.toggle( 'is-disabled', ! box.checked );
			block.querySelectorAll( 'select' ).forEach( function ( sel ) {
				sel.disabled = ! box.checked;
			} );
		};
		box.addEventListener( 'change', toggle );
	} );

	function showResult( summary ) {
		if ( ! resultBox ) {
			return;
		}

		document.getElementById( 'seoryco-wpmd-stat-converted' ).textContent = String( summary.converted );
		document.getElementById( 'seoryco-wpmd-stat-skipped' ).textContent = String( summary.skipped );
		document.getElementById( 'seoryco-wpmd-stat-drafts' ).textContent = String( summary.drafts );

		var typesBox = document.getElementById( 'seoryco-wpmd-types' );
		typesBox.textContent = Object.keys( summary.types || {} )
			.map( function ( type ) {
				return type + ': ' + summary.types[ type ];
			} )
			.join( '   ' );

		var list = document.getElementById( 'seoryco-wpmd-paths' );
		list.textContent = '';
		( summary.paths || [] ).forEach( function ( path ) {
			var item = document.createElement( 'li' );
			item.textContent = path;
			list.appendChild( item );
		} );
		if ( summary.pathTotal > ( summary.paths || [] ).length ) {
			var more = document.createElement( 'li' );
			more.textContent = sprintf( i18n.andMore || '…and %s more', summary.pathTotal - summary.paths.length );
			list.appendChild( more );
		}

		resultBox.hidden = false;
	}

	function step( job, total ) {
		return request( 'seoryco_wpmd_step', { job: job } ).then( function ( data ) {
			setProgress( data.processed, data.total || total );
			if ( data.done ) {
				return data;
			}
			return step( job, total );
		} );
	}

	button.addEventListener( 'click', function () {
		setBoxText( errorBox, '' );
		setBoxText( noticeBox, '' );
		if ( resultBox ) {
			resultBox.hidden = true;
		}

		var options = collectOptions();
		if ( options.post_types.length === 0 ) {
			setBoxText( errorBox, i18n.noPostTypes || 'Select at least one post type.' );
			return;
		}

		setBusy( true );
		setProgress( 0, 1 );

		request( 'seoryco_wpmd_start', options )
			.then( function ( data ) {
				setProgress( 0, data.total );
				return step( data.job, data.total );
			} )
			.then( function ( data ) {
				showResult( data.summary || {} );
				setBoxText( noticeBox, i18n.downloaded || 'ZIP download started.' );
				window.location.assign( data.downloadUrl );
			} )
			.catch( function ( error ) {
				setBoxText( errorBox, error && error.message ? error.message : ( i18n.failed || 'Export failed.' ) );
			} )
			.then( function () {
				setBusy( false );
				if ( progressWrap ) {
					progressWrap.hidden = true;
				}
			} );
	} );
} )();
