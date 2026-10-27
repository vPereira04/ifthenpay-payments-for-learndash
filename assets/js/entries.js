/* global iftpLdEntries, iftpLdEntriesStore, jQuery */
// Payments: the in-place AJAX actions (bulk, new payment, emails, columns) and the UI around them.
// Sort, filters and pagination stay plain GET navigations. Also drives the separate entry page.
( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof iftpLdEntries ) {
		return;
	}

	var cfg = iftpLdEntries;
	var i18n = cfg.i18n;

	// Pagination is a full reload, so the selection lives in sessionStorage to carry it across pages.
	var SELECTION_KEY = 'iftpLdEntriesSelectedRefs';
	var STATUS_KEY = 'iftpLdEntriesSelectedStatus';
	var NAV_LOADING_KEY = 'iftpLdEntriesNavLoading';
	var selected = new Set( readJson( SELECTION_KEY, [] ) );
	var selectedStatus = readJson( STATUS_KEY, {} );
	var loadingSince = 0;
	var settleTimer = 0;
	var toastTimer = 0;
	// From entries-restore.js (in <head>); without it the spinner just settles at once.
	var afterSpinLap =
		window.iftpLdAfterSpinLap ||
		function ( scope, done ) {
			done();
			return 0;
		};

	$( function () {
		var list = document.getElementById( 'iftp-ld-entries' );
		var entry = document.getElementById( 'iftp-ld-entry' );
		var root = list || entry;

		if ( ! root ) {
			return;
		}

		bindCopy( root );
		bindSendEmail( root );
		bindRowDelete( root, !! entry );

		if ( entry ) {
			bindEntryMark( entry );
			return;
		}

		bindDetailsToggle( list );
		bindSelection( list );
		bindBulkActions( list );
		bindNewPayment( list );
		bindEmailEditor( list );
		bindColumnsMenu( list );
		bindExportMenu( list );
		bindFilterPanel( list );
		bindPerPage( list );
		bindScrollTop( list );
		// Last, so it sees defaultPrevented from every other click/submit handler.
		bindNavLoading( list );
		restoreSelectionState( list );

		if ( cfg.flash ) {
			showToast( cfg.flash );
			dropParam( 'iftp_ld_deleted' );
		}
	} );

	// ---------------------------------------------------------------- helpers

	function readJson( key, fallback ) {
		try {
			var value = JSON.parse( window.sessionStorage.getItem( key ) || 'null' );

			return null === value ? fallback : value;
		} catch ( e ) {
			return fallback;
		}
	}

	function writeJson( key, value ) {
		try {
			window.sessionStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {
			// No storage: the selection just won't survive a page turn.
		}
	}

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );

		return String( text )
			.replace( /%(\d)\$s/g, function ( match, n ) {
				return undefined === args[ n - 1 ] ? '' : args[ n - 1 ];
			} )
			.replace( '%s', undefined === args[ 0 ] ? '' : args[ 0 ] );
	}

	// Counts in the site's thousands separator, like number_format_i18n().
	function count( value ) {
		var sep = cfg.numberFormat ? cfg.numberFormat.thousands : ',';

		return String( parseInt( value, 10 ) || 0 ).replace( /\B(?=(\d{3})+(?!\d))/g, function () {
			return sep;
		} );
	}

	function rowData( el ) {
		var holder = el ? el.closest( '[data-iftp-row]' ) : null;

		try {
			return holder ? JSON.parse( decodeURIComponent( holder.getAttribute( 'data-iftp-row' ) ) ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function findRowData( wrap, ref ) {
		var found = null;

		wrap.querySelectorAll( '[data-iftp-row]' ).forEach( function ( row ) {
			if ( row.getAttribute( 'data-iftp-ref' ) === ref ) {
				found = rowData( row );
			}
		} );

		return found;
	}

	function post( action, data ) {
		var body = new URLSearchParams();

		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );

		Object.keys( data ).forEach( function ( key ) {
			if ( Array.isArray( data[ key ] ) ) {
				data[ key ].forEach( function ( value ) {
					body.append( key + '[]', value );
				} );
			} else {
				body.append( key, null === data[ key ] || undefined === data[ key ] ? '' : data[ key ] );
			}
		} );

		return window.fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	function dropParam( name ) {
		var url = new URL( window.location.href );

		url.searchParams.delete( name );
		window.history.replaceState( window.history.state, '', url.href );
	}

	function submitForm( form ) {
		if ( 'function' === typeof form.requestSubmit ) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	// prefix '' or 'view_', matching EntriesPage::view_state_from_request().
	function viewParams( prefix ) {
		var f = cfg.currentFilters;
		var params = { view_status: f.status };

		[ 's', 'product_id', 'date_from', 'date_to', 'pay_method', 'amount_min', 'amount_max', 'orderby', 'order', 'per_page', 'paged' ].forEach( function ( key ) {
			params[ prefix + key ] = f[ key ];
		} );

		return params;
	}

	// ---------------------------------------------------------------- toast and loader

	// A sticky toast stays until the next one replaces it (bulk progress).
	function showToast( message, isError, sticky ) {
		var toast = document.querySelector( '[data-iftp-toast]' );

		if ( ! toast || ! message ) {
			return;
		}

		toast.querySelector( '[data-iftp-toast-message]' ).textContent = message;
		toast.classList.toggle( 'iftp-ld-toast--error', !! isError );
		toast.setAttribute( 'role', isError ? 'alert' : 'status' );
		toast.hidden = false;

		window.clearTimeout( toastTimer );

		if ( ! sticky ) {
			toastTimer = window.setTimeout( function () {
				toast.hidden = true;
			}, 4000 );
		}
	}

	// CSS turns the logo mark into the spinner while data-iftp-loading is set; 'nav' shows it without the delay.
	function setLoading( wrap, loading, kind ) {
		var body = wrap.querySelector( '[data-iftp-body]' );

		function finish() {
			wrap.removeAttribute( 'data-iftp-loading' );

			if ( body ) {
				body.setAttribute( 'aria-busy', 'false' );
			}
		}

		window.clearTimeout( settleTimer );

		if ( loading ) {
			loadingSince = Date.now();
			wrap.removeAttribute( 'data-iftp-settle' );
			wrap.setAttribute( 'data-iftp-loading', kind || 'ajax' );

			if ( body ) {
				body.setAttribute( 'aria-busy', 'true' );
			}

			return;
		}

		// Over before the spinner ever showed (the 150ms delay): nothing to settle back from.
		if ( Date.now() - loadingSince < 150 ) {
			wrap.setAttribute( 'data-iftp-settle', 'none' );
			finish();
			return;
		}

		settleTimer = afterSpinLap( wrap, finish );
	}

	function bindNavLoading( wrap ) {
		var currentPage = new URL( window.location.href ).searchParams.get( 'page' );

		function startNav() {
			setLoading( wrap, true, 'nav' );

			try {
				window.sessionStorage.setItem( NAV_LOADING_KEY, '1' );
			} catch ( e ) {
				// The next page just starts its spinner from the disc.
			}
		}

		function isView( url, page ) {
			return url.origin === window.location.origin && url.pathname === window.location.pathname && page === currentPage && ! url.searchParams.has( 'entry_id' );
		}

		wrap.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href]' );

			if ( ! link || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			if ( ( link.target && '_self' !== link.target ) || '#' === link.getAttribute( 'href' ).charAt( 0 ) ) {
				return;
			}

			var url = new URL( link.href, window.location.href );

			if ( isView( url, url.searchParams.get( 'page' ) ) && ! wrap.hasAttribute( 'data-iftp-bulk-busy' ) ) {
				startNav();
			}
		} );

		wrap.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			var page = form.querySelector( '[name="page"]' );

			if ( ! event.defaultPrevented && 'get' === form.method && page && isView( new URL( form.action, window.location.href ), page.value ) ) {
				startNav();
			}
		} );

		// Back/forward cache brings the page back mid-load.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				setLoading( wrap, false );
			}
		} );
	}

	// ---------------------------------------------------------------- dialogs (jQuery UI, LearnDash's admin modal)

	function rootSelector() {
		return document.getElementById( 'iftp-ld-entries' ) ? '#iftp-ld-entries' : '#iftp-ld-entry';
	}

	function dialogWidth( max, ratio ) {
		return Math.min( max, Math.round( window.innerWidth * ratio ) );
	}

	function makeDialog( $el, name, options ) {
		$el.removeAttr( 'hidden' );

		return $el.dialog(
			$.extend(
				{
					autoOpen: false,
					modal: true,
					draggable: false,
					resizable: false,
					appendTo: rootSelector(),
					dialogClass: 'iftp-ld-dialog iftp-ld-scope iftp-ld-dialog--' + name,
					closeText: i18n.cancel,
				},
				options
			)
		);
	}

	// One shared confirm dialog, filled per call. Cancel gets focus for destructive actions.
	function confirmAction( title, message, note, confirmLabel, onConfirm, danger ) {
		var $dialog = $( '#iftp-ld-confirm-dialog' );

		if ( ! $dialog.length ) {
			onConfirm();
			return;
		}

		if ( ! $dialog.hasClass( 'ui-dialog-content' ) ) {
			makeDialog( $dialog, 'confirm', { width: Math.max( 320, Math.round( window.innerWidth * 0.4 ) ) } );
		}

		$dialog.find( '[data-iftp-confirm-message]' ).text( message );
		$dialog.find( '[data-iftp-confirm-note]' ).text( note || '' ).prop( 'hidden', ! note );
		$dialog.dialog( 'option', {
			title: title,
			buttons: [
				{
					text: i18n.cancel,
					class: 'button button-secondary',
					click: function () {
						$dialog.dialog( 'close' );
					},
				},
				{
					text: confirmLabel,
					class: 'button button-primary',
					click: function () {
						$dialog.dialog( 'close' );
						onConfirm();
					},
				},
			],
		} );
		$dialog.dialog( 'open' );
		$dialog.parent().find( '.ui-dialog-buttonpane button' ).eq( danger ? 0 : 1 ).trigger( 'focus' );
	}

	// ---------------------------------------------------------------- copy

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return legacyCopy( text );
			} );
		}

		return legacyCopy( text );
	}

	// Plain-http admins have no Clipboard API, so execCommand it is.
	function legacyCopy( text ) {
		var area = document.createElement( 'textarea' );
		var active = document.activeElement;
		var ok = false;

		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.left = '-9999px';
		document.body.appendChild( area );
		area.select();

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		area.remove();

		if ( active && active.focus ) {
			active.focus();
		}

		return ok ? Promise.resolve() : Promise.reject( new Error( 'copy' ) );
	}

	// Delegated: LearnDash's copy-text script only binds buttons present at load, and rows get swapped in.
	function bindCopy( root ) {
		root.addEventListener( 'click', function ( event ) {
			var ldBtn = event.target.closest( '.learndash-copy-text' );
			var linkBtn = event.target.closest( '[data-iftp-copy-link]' );
			var btn = ldBtn || linkBtn;

			if ( ! btn ) {
				return;
			}

			event.preventDefault();

			copyText( ldBtn ? ldBtn.getAttribute( 'data-text' ) : linkBtn.getAttribute( 'data-iftp-copy-link' ) )
				.then( function () {
					if ( ldBtn && ldBtn.getAttribute( 'data-tooltip-success' ) ) {
						ldBtn.setAttribute( 'data-tooltip', ldBtn.getAttribute( 'data-tooltip-success' ) );
					}

					showToast( ldBtn ? i18n.copied : i18n.linkCopied );
				} )
				.catch( function () {
					showToast( i18n.copyError, true );
				} );
		} );

		[ 'mouseover', 'focusin' ].forEach( function ( type ) {
			root.addEventListener( type, function ( event ) {
				var btn = event.target.closest ? event.target.closest( '.learndash-copy-text' ) : null;

				if ( btn && btn.getAttribute( 'data-tooltip-default' ) ) {
					btn.setAttribute( 'data-tooltip', btn.getAttribute( 'data-tooltip-default' ) );
				}
			} );
		} );
	}

	// ---------------------------------------------------------------- details row

	// Empty shells; the first open fetches the panel and the row keeps it. A re-render swaps the rows.
	function bindDetailsToggle( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '[data-iftp-details-toggle]' );
			var retry = event.target.closest( '[data-iftp-retry]' );

			if ( retry ) {
				var retryRow = retry.closest( '[data-iftp-details-ref]' );

				retryRow.removeAttribute( 'data-iftp-state' );
				loadDetails( retryRow );
				return;
			}

			if ( ! toggle ) {
				return;
			}

			var details = document.getElementById( toggle.getAttribute( 'aria-controls' ) );

			if ( ! details ) {
				return;
			}

			var open = details.hidden;
			var dataRow = details.previousElementSibling;

			details.hidden = ! open;

			if ( dataRow ) {
				dataRow.classList.toggle( 'iftp-ld-table__row--expanded', open );
				dataRow.querySelectorAll( '[data-iftp-details-toggle]' ).forEach( function ( btn ) {
					btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

					if ( btn.classList.contains( 'button-link' ) ) {
						btn.textContent = open ? i18n.hide : i18n.view;
					}
				} );
			}

			if ( open ) {
				loadDetails( details );
			}
		} );
	}

	function loadDetails( details ) {
		var cell = details.firstElementChild;

		if ( ! cell || details.hasAttribute( 'data-iftp-state' ) ) {
			return;
		}

		details.setAttribute( 'data-iftp-state', 'loading' );
		cell.innerHTML = '<div class="iftp-ld-details" aria-busy="true"><span class="iftp-ld-skeleton-line"></span><span class="iftp-ld-skeleton-line"></span><span class="iftp-ld-skeleton-line"></span><span class="screen-reader-text">' + escapeHtml( i18n.loading ) + '</span></div>';

		post( 'iftp_ld_entry_details', { ref: details.getAttribute( 'data-iftp-details-ref' ) } )
			.then( function ( response ) {
				if ( ! response || ! response.success || 'string' !== typeof response.data.html ) {
					return Promise.reject( response && response.data && response.data.message );
				}

				cell.innerHTML = response.data.html;
				details.setAttribute( 'data-iftp-state', 'loaded' );
			} )
			.catch( function ( message ) {
				details.setAttribute( 'data-iftp-state', 'error' );
				cell.innerHTML = '<div class="iftp-ld-details" role="alert"><p>' + escapeHtml( 'string' === typeof message && message ? message : i18n.detailsError ) + ' <button type="button" class="button-link" data-iftp-retry>' + escapeHtml( i18n.tryAgain ) + '</button></p></div>';
			} );
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );

		div.textContent = String( text );

		return div.innerHTML;
	}

	// ---------------------------------------------------------------- view swap

	// Every mutation answers with a re-rendered view for the current URL, swapped in here.
	function applyView( wrap, data ) {
		var body = wrap.querySelector( '[data-iftp-body]' );
		var pagination = wrap.querySelector( '[data-iftp-pagination]' );
		var total = wrap.querySelector( '[data-iftp-total-count]' );
		var summary = wrap.querySelector( '[data-iftp-summary]' );

		if ( body && 'string' === typeof data.rowsHtml ) {
			body.innerHTML = data.rowsHtml;
			// Only first paint gets the reveal; after an action the mark settles out of the loader.
			body.querySelectorAll( '.iftp-ld-empty__icon' ).forEach( function ( icon ) {
				icon.classList.add( 'iftp-ld-mark--no-entrance' );
			} );
			// A column save may still be in flight, so the server's cells can be in the old order.
			applyColumnOrder( wrap, columnOrder( wrap ) );
		}

		if ( pagination && 'string' === typeof data.paginationHtml ) {
			pagination.innerHTML = data.paginationHtml;
		}

		if ( summary && 'string' === typeof data.summaryHtml ) {
			summary.innerHTML = data.summaryHtml;
		}

		if ( total && 'string' === typeof data.totalLabel ) {
			total.textContent = data.totalLabel;
			total.setAttribute( 'data-iftp-total', data.total );
		}

		if ( 'number' === typeof data.paged ) {
			syncPaged( data.paged );
		}

		restoreSelectionState( wrap );
	}

	// Emptying the last page makes the server fall back a page; the URL and later posts follow.
	function syncPaged( paged ) {
		var url = new URL( window.location.href );

		cfg.currentFilters.paged = paged;

		if ( paged > 1 ) {
			url.searchParams.set( 'paged', paged );
		} else {
			url.searchParams.delete( 'paged' );
		}

		if ( url.href !== window.location.href ) {
			window.history.replaceState( window.history.state, '', url.href );
		}
	}

	// ---------------------------------------------------------------- selection

	function checkboxes( wrap ) {
		return Array.prototype.slice.call( wrap.querySelectorAll( '[data-iftp-row-check]' ) );
	}

	function persistSelection() {
		var statuses = {};

		selected.forEach( function ( ref ) {
			if ( selectedStatus[ ref ] ) {
				statuses[ ref ] = selectedStatus[ ref ];
			}
		} );

		selectedStatus = statuses;
		writeJson( SELECTION_KEY, Array.from( selected ) );
		writeJson( STATUS_KEY, selectedStatus );
	}

	function selectBox( box ) {
		selected.add( box.value );
		selectedStatus[ box.value ] = box.getAttribute( 'data-iftp-status' ) || '';
	}

	function removeFromSelection( refs ) {
		refs.forEach( function ( ref ) {
			selected.delete( ref );
		} );
		persistSelection();
	}

	// Fresh rows start unchecked; their statuses are the freshest I have, so they win.
	function restoreSelectionState( wrap ) {
		var boxes = checkboxes( wrap );
		var all = wrap.querySelector( '[data-iftp-select-all]' );

		boxes.forEach( function ( box ) {
			box.checked = selected.has( box.value );

			if ( box.checked ) {
				selectBox( box );
			}

			box.closest( 'tr' ).classList.toggle( 'iftp-ld-table__row--selected', box.checked );
		} );

		persistSelection();

		if ( all ) {
			all.checked =
				boxes.length > 0 &&
				boxes.every( function ( box ) {
					return box.checked;
				} );
		}

		refreshSelectionUi( wrap );
	}

	function clearSelection( wrap ) {
		selected.clear();
		persistSelection();
		restoreSelectionState( wrap );
	}

	function bindSelection( wrap ) {
		wrap.addEventListener( 'change', function ( event ) {
			var target = event.target;

			if ( target.matches( '[data-iftp-select-all]' ) ) {
				checkboxes( wrap ).forEach( function ( box ) {
					if ( target.checked ) {
						selectBox( box );
					} else {
						selected.delete( box.value );
					}
				} );
			} else if ( target.matches( '[data-iftp-row-check]' ) ) {
				if ( target.checked ) {
					selectBox( target );
				} else {
					selected.delete( target.value );
				}
			} else {
				return;
			}

			persistSelection();
			restoreSelectionState( wrap );
		} );

		wrap.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-iftp-selection-cancel]' ) ) {
				return;
			}

			clearSelection( wrap );

			var all = wrap.querySelector( '[data-iftp-select-all]' );

			if ( all ) {
				all.focus();
			}
		} );
	}

	function refreshSelectionUi( wrap ) {
		var size = selected.size;
		var has = size > 0;
		var bar = wrap.querySelector( '[data-iftp-bulk]' );
		var range = wrap.querySelector( '[data-iftp-total-count]' );
		var summary = wrap.querySelector( '[data-iftp-selection-summary]' );
		var text = wrap.querySelector( '[data-iftp-selection-text]' );
		var countEl = wrap.querySelector( '[data-iftp-bulk-count]' );
		var total = range ? parseInt( range.getAttribute( 'data-iftp-total' ) || '0', 10 ) : 0;
		var refs = Array.from( selected );

		wrap.toggleAttribute( 'data-iftp-has-selection', has );

		if ( bar ) {
			bar.hidden = ! has;
		}

		if ( countEl ) {
			countEl.textContent = format( i18n.bulkSelectedCount, count( size ) );
		}

		if ( range ) {
			range.hidden = has;
		}

		if ( summary ) {
			summary.hidden = ! has;
		}

		if ( text ) {
			text.textContent = format( i18n.selectedOfTotal, count( size ), count( total ) );
		}

		setOption(
			wrap.querySelector( '[data-iftp-bulk-email]' ),
			refs.length > 0 &&
				refs.every( function ( ref ) {
					return 'pending' === selectedStatus[ ref ];
				} ),
			true
		);
		setOption( wrap.querySelector( '[data-iftp-bulk-delete]' ), deletable( refs ).length > 0, false );
	}

	// Safari ignores hidden on <option>, so disabled keeps it unpickable there.
	function setOption( option, available, hide ) {
		if ( ! option ) {
			return;
		}

		option.disabled = ! available;

		if ( hide ) {
			option.hidden = ! available;
		}

		if ( ! available && option.selected ) {
			option.parentNode.value = '';
		}
	}

	// Paid payments are history: the server won't delete them, so I don't send them.
	function deletable( refs ) {
		return refs.filter( function ( ref ) {
			return 'paid' !== selectedStatus[ ref ];
		} );
	}

	// ---------------------------------------------------------------- bulk runner

	function warnBeforeLeave( event ) {
		event.preventDefault();
		event.returnValue = i18n.bulkLeaveWarning;

		return event.returnValue;
	}

	function runLocked( wrap, run ) {
		wrap.setAttribute( 'data-iftp-bulk-busy', '' );
		wrap.querySelectorAll( '[data-iftp-bulk-apply], [data-iftp-bulk-select], [data-iftp-selection-cancel]' ).forEach( function ( el ) {
			el.disabled = true;
		} );
		window.addEventListener( 'beforeunload', warnBeforeLeave );

		return run().then( function ( ok ) {
			wrap.removeAttribute( 'data-iftp-bulk-busy' );
			wrap.querySelectorAll( '[data-iftp-bulk-apply], [data-iftp-bulk-select], [data-iftp-selection-cancel]' ).forEach( function ( el ) {
				el.disabled = false;
			} );
			window.removeEventListener( 'beforeunload', warnBeforeLeave );

			return ok;
		} );
	}

	// Posts refs a chunk at a time, re-rendering after each. Stops on an error, leaving the rest selected.
	function runBulk( wrap, action, refs, extra, text ) {
		var size = Math.max( 1, parseInt( cfg.bulkChunkSize, 10 ) || 250 );
		var chunks = [];
		var done = 0;
		var notRecorded = 0;

		for ( var i = 0; i < refs.length; i += size ) {
			chunks.push( refs.slice( i, i + size ) );
		}

		function progress() {
			if ( chunks.length > 1 ) {
				showToast( format( text.progress, count( done ), count( refs.length ) ), false, true );
			}
		}

		function fail( message ) {
			showToast( done > 0 ? format( i18n.bulkPartialError, count( done ), count( refs.length ) ) : message || i18n.saveError, true );

			return false;
		}

		setLoading( wrap, true );
		progress();

		return chunks
			.reduce( function ( chain, chunk ) {
				return chain.then( function ( ok ) {
					if ( ! ok ) {
						return false;
					}

					return post( action, $.extend( { refs: JSON.stringify( chunk ) }, extra, viewParams( '' ) ) ).then( function ( response ) {
						if ( ! response || ! response.success ) {
							return fail( response && response.data && response.data.message );
						}

						done += chunk.length;
						notRecorded += Array.isArray( response.data.notRecorded ) ? response.data.notRecorded.length : 0;
						removeFromSelection( chunk );
						applyView( wrap, response.data );
						progress();

						return true;
					} );
				} );
			}, Promise.resolve( true ) )
			.then(
				function ( ok ) {
					if ( ok ) {
						showToast( format( text.done, count( done ) ) + ( notRecorded ? ' ' + format( i18n.notRecorded, count( notRecorded ) ) : '' ), notRecorded > 0 );
					}

					return ok;
				},
				function () {
					return fail();
				}
			)
			.then( function ( ok ) {
				setLoading( wrap, false );

				return ok;
			} );
	}

	// Its own runner: a request may hand back fewer refs than sent (time budget), and the outcome is a
	// per-ref tally. Failed refs stay selected, so Apply again retries just those.
	function sendEmails( wrap, refs ) {
		var size = Math.max( 1, parseInt( cfg.emailChunkSize, 10 ) || 10 );
		var queue = refs.slice();
		var tally = { sent: 0, skipped: 0, failed: 0 };
		var lastError = '';

		function handled() {
			return tally.sent + tally.skipped + tally.failed;
		}

		function progress() {
			showToast( format( i18n.bulkEmailing, count( handled() ), count( refs.length ) ), false, true );
		}

		function next() {
			if ( ! queue.length ) {
				return Promise.resolve();
			}

			var chunk = queue.slice( 0, size );
			// Only the last chunk asks for the re-rendered table.
			var params = { refs: JSON.stringify( chunk ), render: chunk.length === queue.length ? '1' : '' };

			return post( 'iftp_ld_send_payment_emails', $.extend( params, viewParams( '' ) ) ).then( function ( response ) {
				var data = response && response.data;

				if ( ! response || ! response.success || ! Array.isArray( data.sent ) ) {
					return Promise.reject( data && data.message );
				}

				var done = new Set( data.sent.concat( data.skipped, data.failed ) );

				// Nothing handled would loop forever.
				if ( ! done.size ) {
					return Promise.reject();
				}

				queue = queue.filter( function ( ref ) {
					return ! done.has( ref );
				} );
				tally.sent += data.sent.length;
				tally.skipped += data.skipped.length;
				tally.failed += data.failed.length;
				lastError = data.message || lastError;
				removeFromSelection( data.sent.concat( data.skipped ) );

				if ( 'string' === typeof data.rowsHtml ) {
					applyView( wrap, data );
				} else {
					restoreSelectionState( wrap );
				}

				progress();

				return next();
			} );
		}

		setLoading( wrap, true );
		progress();

		return next()
			.then(
				function () {
					showToast( format( i18n.bulkEmailDone, count( tally.sent ), count( tally.skipped ), count( tally.failed ) ) + ( tally.failed && lastError ? ' ' + lastError : '' ), tally.failed > 0 );

					return 0 === tally.failed;
				},
				function ( message ) {
					showToast( handled() ? format( i18n.bulkPartialError, count( handled() ), count( refs.length ) ) : message || i18n.sendError, true );

					return false;
				}
			)
			.then( function ( ok ) {
				setLoading( wrap, false );

				return ok;
			} );
	}

	// Confirm text for "Mark as": a single checkout row names its student and course.
	function markConfirm( wrap, refs, status, onConfirm ) {
		var label = ( cfg.statusLabels[ status ] || status ).toLowerCase();
		var button = format( i18n.markButton, label );

		if ( 'paid' !== status ) {
			confirmAction( format( i18n.markTitle, count( refs.length ), label ), i18n.markMessage, '', button, onConfirm, false );
			return;
		}

		var one = 1 === refs.length ? findRowData( wrap, refs[ 0 ] ) : null;

		if ( one ) {
			confirmAction(
				format( i18n.markPaidOneTitle, one.ref ),
				one.checkout ? format( i18n.markPaidOneMessage, one.student, one.product ) : i18n.markPaidAdHoc,
				'',
				button,
				onConfirm,
				false
			);
			return;
		}

		confirmAction( format( i18n.markPaidTitle, count( refs.length ) ), i18n.markPaidMessage, '', button, onConfirm, false );
	}

	function bindBulkActions( wrap ) {
		var apply = wrap.querySelector( '[data-iftp-bulk-apply]' );
		var select = wrap.querySelector( '[data-iftp-bulk-select]' );

		if ( ! apply || ! select ) {
			return;
		}

		apply.addEventListener( 'click', function () {
			var value = select.value;
			var refs = Array.from( selected );

			if ( 'export-csv' === value || 'export-json' === value ) {
				if ( ! refs.length ) {
					showToast( i18n.exportNothing, true );
					return;
				}

				exportRefs( refs, value.slice( 7 ) );
				select.value = '';
				return;
			}

			if ( ! value || ! refs.length ) {
				return;
			}

			if ( 'delete' === value ) {
				var targets = deletable( refs );
				var kept = refs.length - targets.length;

				if ( ! targets.length ) {
					return;
				}

				confirmAction(
					format( i18n.bulkDeleteTitle, count( targets.length ) ),
					i18n.bulkDeleteMessage,
					kept ? format( i18n.deleteKeepsPaid, count( kept ) ) : '',
					i18n.delete,
					function () {
						select.value = '';
						runLocked( wrap, function () {
							return runBulk( wrap, 'iftp_ld_delete_entries', targets, {}, { progress: i18n.bulkDeleting, done: i18n.deleted } );
						} );
					},
					true
				);
				return;
			}

			if ( 'email' === value ) {
				confirmAction( i18n.bulkEmailTitle, format( i18n.bulkEmailMessage, count( refs.length ) ), '', i18n.sendEmails, function () {
					select.value = '';
					runLocked( wrap, function () {
						return sendEmails( wrap, refs );
					} );
				} );
				return;
			}

			markConfirm( wrap, refs, value, function () {
				select.value = '';
				runLocked( wrap, function () {
					return runBulk( wrap, 'iftp_ld_update_status', refs, { status: value }, { progress: i18n.bulkUpdating, done: i18n.updated } );
				} );
			} );
		} );
	}

	// A throwaway form post, so the browser handles the download. Refs go as one JSON field (max_input_vars).
	function exportRefs( refs, formatName ) {
		var form = document.createElement( 'form' );
		var f = cfg.currentFilters;
		var url = new URL( cfg.exportUrl, window.location.href );

		url.searchParams.set( 'action', 'iftp_ld_export' );
		form.method = 'post';
		form.action = url.toString();
		form.hidden = true;

		[
			[ '_wpnonce', cfg.exportNonce ],
			[ 'format', formatName ],
			[ 'orderby', f.orderby || '' ],
			[ 'order', f.order || '' ],
			[ 'refs', JSON.stringify( refs ) ],
		].forEach( function ( pair ) {
			var input = document.createElement( 'input' );

			input.type = 'hidden';
			input.name = pair[ 0 ];
			input.value = pair[ 1 ];
			form.appendChild( input );
		} );

		document.body.appendChild( form );
		form.submit();
		form.remove();
	}

	// ---------------------------------------------------------------- row delete, email, entry page

	function bindRowDelete( root, onEntryPage ) {
		root.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-row-delete]' );

			if ( ! btn ) {
				return;
			}

			var ref = btn.getAttribute( 'data-iftp-row-delete' );

			confirmAction(
				i18n.deleteTitle,
				format( i18n.deleteMessage, ref ),
				'',
				i18n.delete,
				function () {
					if ( onEntryPage ) {
						post( 'iftp_ld_delete_entries', $.extend( { refs: JSON.stringify( [ ref ] ) }, viewParams( '' ) ) )
							.then( function ( response ) {
								if ( ! response || ! response.success || ! response.data.deleted ) {
									return Promise.reject( response && response.data && response.data.message );
								}

								var url = new URL( cfg.listUrl, window.location.href );

								url.searchParams.set( 'iftp_ld_deleted', '1' );
								window.location.assign( url.href );
							} )
							.catch( function ( message ) {
								showToast( message || i18n.saveError, true );
							} );
						return;
					}

					runBulk( root, 'iftp_ld_delete_entries', [ ref ], {}, { progress: i18n.bulkDeleting, done: i18n.deleted } );
				},
				true
			);
		} );
	}

	function sendOne( ref, to, btn, after ) {
		var idle = btn.textContent;

		btn.disabled = true;
		btn.textContent = i18n.sending;

		return post( 'iftp_ld_send_payment_emails', $.extend( { refs: JSON.stringify( [ ref ] ) }, viewParams( '' ) ) )
			.then( function ( response ) {
				var data = response && response.data;

				if ( response && response.success && Array.isArray( data.sent ) && data.sent.length ) {
					showToast( format( i18n.emailSent, to ) );

					if ( after ) {
						after();
					}

					return;
				}

				showToast( ( data && data.message ) || i18n.sendError, true );
			} )
			.catch( function () {
				showToast( i18n.sendError, true );
			} )
			.then( function () {
				btn.disabled = false;
				btn.textContent = idle;
			} );
	}

	function bindSendEmail( root ) {
		root.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-send-email]' );

			if ( ! btn || btn.disabled ) {
				return;
			}

			var to = btn.getAttribute( 'data-iftp-send-to' );

			confirmAction( i18n.sendEmailTitle, format( i18n.sendEmailMessage, to ), '', i18n.sendEmail, function () {
				sendOne( btn.getAttribute( 'data-iftp-send-email' ), to, btn, null );
			} );
		} );
	}

	function bindEntryMark( entry ) {
		var select = entry.querySelector( '[data-iftp-entry-mark]' );
		var apply = entry.querySelector( '[data-iftp-entry-mark-apply]' );
		var data = rowData( entry );

		if ( ! select || ! apply || ! data ) {
			return;
		}

		apply.addEventListener( 'click', function () {
			var status = select.value;
			var label = ( cfg.statusLabels[ status ] || status ).toLowerCase();
			var message = 'paid' === status ? ( data.checkout ? format( i18n.markPaidOneMessage, data.student, data.product ) : i18n.markPaidAdHoc ) : i18n.markMessage;
			var title = 'paid' === status ? format( i18n.markPaidOneTitle, data.ref ) : format( i18n.markTitle, count( 1 ), label );

			if ( status === data.status ) {
				return;
			}

			confirmAction( title, message, '', format( i18n.markButton, label ), function () {
				apply.disabled = true;
				post( 'iftp_ld_update_status', $.extend( { refs: JSON.stringify( [ data.ref ] ), status: status }, viewParams( '' ) ) )
					.then( function ( response ) {
						if ( ! response || ! response.success ) {
							return Promise.reject( response && response.data && response.data.message );
						}

						window.location.reload();
					} )
					.catch( function ( message ) {
						apply.disabled = false;
						showToast( message || i18n.saveError, true );
					} );
			} );
		} );
	}

	// ---------------------------------------------------------------- new payment

	function bindNewPayment( wrap ) {
		var $dialog = $( '#iftp-ld-new-payment-dialog' );
		var form = $dialog.find( '[data-iftp-new-payment-form]' )[ 0 ];

		if ( ! form ) {
			return;
		}

		var errorBox = form.querySelector( '[data-iftp-new-payment-error]' );
		var status = form.querySelector( '[data-iftp-np-status]' );
		var product = form.querySelector( '[data-iftp-np-product]' );
		var sendRow = form.querySelector( '[data-iftp-np-send-row]' );
		var trigger = null;

		function fieldError( name, message ) {
			var input = form.elements[ name ];
			var box = document.getElementById( input.id + '-error' );

			input.setAttribute( 'aria-invalid', message ? 'true' : 'false' );

			if ( box ) {
				box.textContent = message || '';
				box.hidden = ! message;
			}
		}

		function showError( message ) {
			errorBox.querySelector( '[data-iftp-error-text]' ).textContent = message;
			errorBox.hidden = ! message;
		}

		function syncSend() {
			sendRow.hidden = 'pending' !== status.value;
		}

		function submit() {
			var amount = parseFloat( String( form.elements.amount.value ).replace( ',', '.' ) );
			var email = form.elements.customer_email.value.trim();
			var send = ! sendRow.hidden && form.elements.send_email.checked;
			var invalid = null;

			fieldError( 'amount', '' );
			fieldError( 'customer_email', '' );
			showError( '' );

			if ( ! ( amount >= 0.01 ) ) {
				fieldError( 'amount', i18n.amountRequired );
				invalid = invalid || form.elements.amount;
			}

			if ( email && ! form.elements.customer_email.checkValidity() ) {
				fieldError( 'customer_email', i18n.emailInvalid );
				invalid = invalid || form.elements.customer_email;
			} else if ( send && ! email ) {
				fieldError( 'customer_email', i18n.emailRequired );
				invalid = invalid || form.elements.customer_email;
			}

			if ( invalid ) {
				invalid.focus();
				return;
			}

			var $create = $dialog.parent().find( '.iftp-ld-dialog__create' );

			$create.prop( 'disabled', true ).text( i18n.creating );
			setLoading( wrap, true );

			post(
				'iftp_ld_create_entry',
				$.extend(
					{
						customer_name: form.elements.customer_name.value,
						customer_email: email,
						product_id: product.value,
						amount: String( amount ),
						status: status.value,
						send_email: send ? '1' : '',
					},
					viewParams( 'view_' )
				)
			)
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						var data = response && response.data;

						if ( data && data.field ) {
							fieldError( data.field, data.message );
							form.elements[ data.field ].focus();
						} else {
							showError( ( data && data.message ) || i18n.saveError );
						}

						return;
					}

					applyView( wrap, response.data );
					$dialog.dialog( 'close' );
					showToast( response.data.message ? i18n.paymentCreated + '. ' + response.data.message : 'sent' === response.data.email ? i18n.paymentCreated + '. ' + format( i18n.emailSent, email ) : i18n.paymentCreated, !! response.data.message );
				} )
				.catch( function () {
					showError( i18n.saveError );
				} )
				.then( function () {
					setLoading( wrap, false );
					$create.prop( 'disabled', false ).text( i18n.createPayment );
				} );
		}

		makeDialog( $dialog, 'new-payment', {
			width: dialogWidth( 560, 0.94 ),
			title: i18n.newPayment,
			buttons: [
				{
					text: i18n.cancel,
					class: 'button button-secondary',
					click: function () {
						$dialog.dialog( 'close' );
					},
				},
				{
					text: i18n.createPayment,
					class: 'button button-primary iftp-ld-dialog__create',
					click: submit,
				},
			],
			open: function () {
				syncSend();
				form.elements.customer_name.focus();
			},
			close: function () {
				form.reset();
				showError( '' );
				fieldError( 'amount', '' );
				fieldError( 'customer_email', '' );

				if ( trigger && document.body.contains( trigger ) ) {
					trigger.focus();
				}
			},
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit();
		} );
		status.addEventListener( 'change', syncSend );
		product.addEventListener( 'change', function () {
			var option = product.options[ product.selectedIndex ];
			var price = option ? option.getAttribute( 'data-iftp-price' ) : '';

			if ( price && ! form.elements.amount.value ) {
				form.elements.amount.value = price;
			}
		} );

		// LearnDash's React header mounts after this script, so the trigger is delegated.
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.iftp-ld-header-new-payment' );

			if ( btn ) {
				event.preventDefault();
				trigger = btn;
				$dialog.dialog( 'open' );
			}
		} );
	}

	// ---------------------------------------------------------------- payment email editor

	function bindEmailEditor( wrap ) {
		var $dialog = $( '#iftp-ld-email-dialog' );
		var settings = cfg.emailSettings;
		var form = $dialog.find( '[data-iftp-email-form]' )[ 0 ];

		if ( ! form || ! settings || ! settings.defaults ) {
			return;
		}

		var subject = form.querySelector( '[data-iftp-email-subject]' );
		var bodyArea = form.querySelector( '[data-iftp-email-body]' );
		var errorBox = form.querySelector( '[data-iftp-email-error]' );
		var previewWrap = form.querySelector( '[data-iftp-email-preview-wrap]' );
		var previewFrame = form.querySelector( '[data-iftp-email-preview]' );
		var previewSubject = form.querySelector( '[data-iftp-email-preview-subject]' );
		var tagDesc = form.querySelector( '[data-iftp-email-tag-desc]' );
		var tagDefault = tagDesc.textContent;
		var mediaBtn = form.querySelector( '[data-iftp-email-media]' );
		var saved = { subject: subject.value, body: bodyArea.value };
		var editor = null;
		var tagTarget = 'body';
		var previewTimer = 0;
		var previewSeq = 0;
		var mediaFrame = null;
		var trigger = null;
		var discarding = false;
		// The email card is ~520px wide, so images are capped there.
		var IMAGE_MAX_WIDTH = 520;

		function getBody() {
			return editor ? editor.getValue() : bodyArea.value;
		}

		function setBody( value ) {
			if ( editor ) {
				editor.setValue( value );
				editor.clearHistory();
			} else {
				bodyArea.value = value;
			}
		}

		// CodeMirror can't measure itself while hidden, so it starts on first open.
		function ensureEditor() {
			if ( editor || ! settings.editor || ! window.wp || ! window.wp.codeEditor ) {
				return;
			}

			editor = window.wp.codeEditor.initialize( bodyArea, settings.editor ).codemirror;
			editor.on( 'change', schedulePreview );
			editor.on( 'focus', function () {
				tagTarget = 'body';
			} );
		}

		function dirty() {
			return subject.value !== saved.subject || getBody() !== saved.body;
		}

		function showError( message ) {
			errorBox.querySelector( '[data-iftp-email-error-text]' ).textContent = message;
			errorBox.hidden = ! message;
		}

		function schedulePreview() {
			window.clearTimeout( previewTimer );
			previewTimer = window.setTimeout( refreshPreview, parseInt( settings.previewDelay, 10 ) || 400 );
		}

		function refreshPreview() {
			var seq = ++previewSeq;

			previewWrap.classList.add( 'iftp-ld-email__frame--loading' );

			post( 'iftp_ld_preview_payment_email', { subject: subject.value, body: getBody() } )
				.then( function ( response ) {
					if ( seq !== previewSeq ) {
						return;
					}

					if ( ! response || ! response.success ) {
						previewSubject.textContent = ( response && response.data && response.data.message ) || i18n.previewError;
						return;
					}

					previewSubject.textContent = response.data.subject;
					previewFrame.srcdoc = response.data.html;
				} )
				.catch( function () {
					if ( seq === previewSeq ) {
						previewSubject.textContent = i18n.previewError;
					}
				} )
				.then( function () {
					if ( seq === previewSeq ) {
						previewWrap.classList.remove( 'iftp-ld-email__frame--loading' );
					}
				} );
		}

		function insert( text ) {
			if ( 'subject' === tagTarget ) {
				subject.setRangeText( text, subject.selectionStart, subject.selectionEnd, 'end' );
				subject.focus();
				schedulePreview();
				return;
			}

			if ( editor ) {
				editor.replaceSelection( text );
				editor.focus();
				return;
			}

			bodyArea.setRangeText( text, bodyArea.selectionStart, bodyArea.selectionEnd, 'end' );
			bodyArea.focus();
			schedulePreview();
		}

		// Built through the DOM, so the URL and alt text come out escaped.
		function imageHtml( attachment ) {
			var size = ( attachment.sizes && ( attachment.sizes.large || attachment.sizes.full ) ) || attachment;
			var img = document.createElement( 'img' );

			img.setAttribute( 'src', size.url );
			img.setAttribute( 'alt', attachment.alt || attachment.title || '' );

			if ( size.width ) {
				img.setAttribute( 'width', String( Math.min( parseInt( size.width, 10 ), IMAGE_MAX_WIDTH ) ) );
			}

			img.setAttribute( 'style', 'display:block;max-width:100%;height:auto;border:0;' );

			return img.outerHTML;
		}

		function save() {
			var $save = $dialog.parent().find( '.iftp-ld-dialog__save' );

			$save.prop( 'disabled', true ).text( i18n.saving );
			showError( '' );

			post( 'iftp_ld_save_payment_email', { subject: subject.value, body: getBody() } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || i18n.saveError );
						return;
					}

					// What was stored, after sanitizing, so reopening shows exactly what goes out.
					saved = { subject: response.data.subject, body: response.data.body };
					discarding = true;
					$dialog.dialog( 'close' );
					showToast( response.data.message );
				} )
				.catch( function () {
					showError( i18n.saveError );
				} )
				.then( function () {
					$save.prop( 'disabled', false ).text( i18n.saveTemplate );
				} );
		}

		makeDialog( $dialog, 'email', {
			width: dialogWidth( 1100, 0.94 ),
			height: Math.min( 720, Math.round( window.innerHeight * 0.9 ) ),
			title: i18n.paymentEmail,
			buttons: [
				{
					text: i18n.cancel,
					class: 'button button-secondary',
					click: function () {
						$dialog.dialog( 'close' );
					},
				},
				{
					text: i18n.saveTemplate,
					class: 'button button-primary iftp-ld-dialog__save',
					click: save,
				},
			],
			open: function () {
				discarding = false;
				ensureEditor();

				if ( editor ) {
					editor.refresh();
				}

				subject.focus();
				refreshPreview();
			},
			// Closing throws edits away, so with any pending I ask first.
			beforeClose: function () {
				if ( discarding || ! dirty() ) {
					return true;
				}

				confirmAction( i18n.discardTitle, i18n.discardMessage, '', i18n.discard, function () {
					discarding = true;
					$dialog.dialog( 'close' );
				}, true );

				return false;
			},
			close: function () {
				window.clearTimeout( previewTimer );
				subject.value = saved.subject;
				setBody( saved.body );
				tagDesc.textContent = tagDefault;
				showError( '' );

				if ( trigger && document.body.contains( trigger ) ) {
					trigger.focus();
				}
			},
		} );

		if ( mediaBtn ) {
			mediaBtn.hidden = ! window.wp || ! window.wp.media;
			mediaBtn.addEventListener( 'click', function () {
				if ( ! mediaFrame ) {
					mediaFrame = window.wp.media( {
						title: i18n.mediaTitle,
						button: { text: i18n.mediaButton },
						library: { type: 'image' },
						multiple: false,
					} );
					mediaFrame.on( 'select', function () {
						var attachment = mediaFrame.state().get( 'selection' ).first();

						if ( attachment ) {
							tagTarget = 'body';
							insert( imageHtml( attachment.toJSON() ) );
						}
					} );
				}

				mediaFrame.open();
			} );
		}

		subject.addEventListener( 'focus', function () {
			tagTarget = 'subject';
		} );
		bodyArea.addEventListener( 'focus', function () {
			tagTarget = 'body';
		} );
		subject.addEventListener( 'input', schedulePreview );
		bodyArea.addEventListener( 'input', schedulePreview );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			save();
		} );

		form.querySelectorAll( '[data-iftp-email-tag]' ).forEach( function ( btn ) {
			function describe() {
				tagDesc.textContent = btn.getAttribute( 'data-iftp-email-tag' ) + ': ' + btn.getAttribute( 'data-iftp-email-tag-help' );
			}

			function undescribe() {
				tagDesc.textContent = tagDefault;
			}

			// mousedown would steal focus first, and then the tag lands nowhere.
			btn.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
			} );
			btn.addEventListener( 'click', function () {
				insert( btn.getAttribute( 'data-iftp-email-tag' ) );
			} );
			btn.addEventListener( 'mouseenter', describe );
			btn.addEventListener( 'focus', describe );
			btn.addEventListener( 'mouseleave', undescribe );
			btn.addEventListener( 'blur', undescribe );
		} );

		form.querySelector( '[data-iftp-email-reset]' ).addEventListener( 'click', function () {
			subject.value = settings.defaults.subject;
			setBody( settings.defaults.body );
			showError( '' );
			schedulePreview();
			showToast( i18n.emailResetDone );
		} );

		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.iftp-ld-header-email' );

			if ( btn ) {
				event.preventDefault();
				trigger = btn;
				$dialog.dialog( 'open' );
			}
		} );
	}

	// ---------------------------------------------------------------- columns

	function columnOrder( wrap ) {
		return ( wrap.getAttribute( 'data-iftp-col-order' ) || '' ).split( ' ' ).filter( Boolean );
	}

	// Table cells can't use CSS order, so I move the real cells. Check + Reference stay first.
	function applyColumnOrder( wrap, order ) {
		wrap.querySelectorAll( '.iftp-ld-table thead tr, .iftp-ld-table .iftp-ld-table__row' ).forEach( function ( row ) {
			var cells = {};

			Array.prototype.forEach.call( row.children, function ( cell ) {
				cells[ cell.getAttribute( 'data-col' ) ] = cell;
			} );

			order.forEach( function ( slug ) {
				if ( cells[ slug ] ) {
					row.appendChild( cells[ slug ] );
				}
			} );
		} );
	}

	// Trigger toggles; Escape, an outside click or focus leaving closes.
	function popover( trigger, panel ) {
		function isOpen() {
			return ! panel.hidden;
		}

		function setOpen( open ) {
			panel.hidden = ! open;
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		function inside( node ) {
			return !! node && ( trigger.contains( node ) || panel.contains( node ) );
		}

		function onFocusOut( event ) {
			if ( isOpen() && event.relatedTarget && ! inside( event.relatedTarget ) ) {
				setOpen( false );
			}
		}

		trigger.addEventListener( 'click', function () {
			setOpen( ! isOpen() );
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() ) {
				setOpen( false );
				trigger.focus();
			}
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! inside( event.target ) ) {
				setOpen( false );
			}
		} );
		trigger.addEventListener( 'focusout', onFocusOut );
		panel.addEventListener( 'focusout', onFocusOut );

		return setOpen;
	}

	function bindColumnsMenu( wrap ) {
		var trigger = wrap.querySelector( '[data-iftp-columns-trigger]' );
		var panel = wrap.querySelector( '[data-iftp-columns-menu]' );

		if ( ! trigger || ! panel ) {
			return;
		}

		var list = panel.querySelector( '[data-iftp-col-list]' );
		var toggles = Array.prototype.slice.call( panel.querySelectorAll( '[data-iftp-col-toggle]' ) );
		var statusEl = wrap.querySelector( '[data-iftp-col-status]' );
		var saveTimer = 0;
		var announceTimer = 0;
		var dragging = null;

		popover( trigger, panel );

		function hiddenCols() {
			return toggles
				.filter( function ( input ) {
					return ! input.checked;
				} )
				.map( function ( input ) {
					return input.getAttribute( 'data-iftp-col-toggle' );
				} );
		}

		function items() {
			return Array.prototype.slice.call( list.querySelectorAll( '[data-iftp-col-item]' ) );
		}

		function readOrder() {
			return items().map( function ( item ) {
				return item.getAttribute( 'data-iftp-col-item' );
			} );
		}

		function scheduleSave() {
			window.clearTimeout( saveTimer );
			saveTimer = window.setTimeout( function () {
				post( 'iftp_ld_save_columns', { hidden: hiddenCols(), order: readOrder() } )
					.then( function ( response ) {
						if ( ! response || ! response.success ) {
							showToast( i18n.columnsSaveError, true );
						}
					} )
					.catch( function () {
						showToast( i18n.columnsSaveError, true );
					} );
			}, 400 );
		}

		function syncMoveButtons() {
			var all = items();

			all.forEach( function ( item, i ) {
				var up = item.querySelector( '[data-iftp-col-move="up"]' );
				var down = item.querySelector( '[data-iftp-col-move="down"]' );

				[ [ up, 0 === i ], [ down, all.length - 1 === i ] ].forEach( function ( pair ) {
					if ( pair[ 1 ] ) {
						pair[ 0 ].setAttribute( 'aria-disabled', 'true' );
					} else {
						pair[ 0 ].removeAttribute( 'aria-disabled' );
					}
				} );
			} );
		}

		// Cleared first, so the same message twice still gets spoken.
		function announce( message ) {
			if ( ! statusEl ) {
				return;
			}

			statusEl.textContent = '';
			window.clearTimeout( announceTimer );
			announceTimer = window.setTimeout( function () {
				statusEl.textContent = message;
			}, 100 );
		}

		function commit( item ) {
			var order = readOrder();
			var slug = item.getAttribute( 'data-iftp-col-item' );
			var label = item.querySelector( '.iftp-ld-columns__label' );

			if ( order.join( ' ' ) === columnOrder( wrap ).join( ' ' ) ) {
				return;
			}

			wrap.setAttribute( 'data-iftp-col-order', order.join( ' ' ) );
			applyColumnOrder( wrap, order );
			syncMoveButtons();
			announce( format( i18n.columnMoved, label ? label.textContent.trim() : slug, order.indexOf( slug ) + 1, order.length ) );
			scheduleSave();
		}

		panel.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-iftp-col-toggle]' ) ) {
				wrap.setAttribute( 'data-iftp-hidden-cols', hiddenCols().join( ' ' ) );
				trigger.classList.toggle( 'iftp-ld-tool--has-hidden', hiddenCols().length > 0 );
				scheduleSave();
			}
		} );

		// I move the neighbour, not the item, so the focused button never leaves the DOM.
		panel.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-col-move]' );
			var item = btn ? btn.closest( '[data-iftp-col-item]' ) : null;
			var neighbour;

			if ( ! item || 'true' === btn.getAttribute( 'aria-disabled' ) ) {
				return;
			}

			if ( 'up' === btn.getAttribute( 'data-iftp-col-move' ) ) {
				neighbour = item.previousElementSibling;

				if ( neighbour ) {
					list.insertBefore( neighbour, item.nextElementSibling );
				}
			} else {
				neighbour = item.nextElementSibling;

				if ( neighbour ) {
					list.insertBefore( neighbour, item );
				}
			}

			commit( item );
			btn.focus();
		} );

		list.addEventListener( 'dragstart', function ( event ) {
			var handle = event.target && event.target.closest ? event.target.closest( '[data-iftp-col-drag]' ) : null;
			var item = handle ? handle.closest( '[data-iftp-col-item]' ) : null;

			if ( ! item ) {
				return;
			}

			dragging = item;
			event.dataTransfer.effectAllowed = 'move';
			// Firefox needs data; a custom type keeps it out of the search field.
			event.dataTransfer.setData( 'application/x-iftp-ld-column', item.getAttribute( 'data-iftp-col-item' ) );
			window.setTimeout( function () {
				item.classList.add( 'iftp-ld-columns__item--dragging' );
			}, 0 );
		} );

		list.addEventListener( 'dragover', function ( event ) {
			var el = event.target && 1 !== event.target.nodeType ? event.target.parentElement : event.target;
			var target = el && el.closest ? el.closest( '[data-iftp-col-item]' ) : null;

			if ( ! dragging ) {
				return;
			}

			items().forEach( function ( item ) {
				item.classList.remove( 'iftp-ld-columns__item--drop-before', 'iftp-ld-columns__item--drop-after' );
			} );

			if ( ! target || target === dragging ) {
				return;
			}

			event.preventDefault();

			var rect = target.getBoundingClientRect();

			target.classList.add( event.clientY < rect.top + rect.height / 2 ? 'iftp-ld-columns__item--drop-before' : 'iftp-ld-columns__item--drop-after' );
		} );

		list.addEventListener( 'drop', function ( event ) {
			var target = list.querySelector( '.iftp-ld-columns__item--drop-before, .iftp-ld-columns__item--drop-after' );

			if ( ! dragging ) {
				return;
			}

			event.preventDefault();

			if ( target && target !== dragging ) {
				list.insertBefore( dragging, target.classList.contains( 'iftp-ld-columns__item--drop-before' ) ? target : target.nextElementSibling );
				commit( dragging );
			}
		} );

		list.addEventListener( 'dragend', function () {
			items().forEach( function ( item ) {
				item.classList.remove( 'iftp-ld-columns__item--dragging', 'iftp-ld-columns__item--drop-before', 'iftp-ld-columns__item--drop-after' );
			} );
			dragging = null;
		} );

		panel.querySelector( '[data-iftp-col-reset]' ).addEventListener( 'click', function () {
			var defaults = cfg.defaultColumnOrder;
			var bySlug = {};

			items().forEach( function ( item ) {
				bySlug[ item.getAttribute( 'data-iftp-col-item' ) ] = item;
			} );
			defaults.forEach( function ( slug ) {
				if ( bySlug[ slug ] ) {
					list.appendChild( bySlug[ slug ] );
				}
			} );
			toggles.forEach( function ( input ) {
				input.checked = true;
			} );
			wrap.setAttribute( 'data-iftp-hidden-cols', '' );
			wrap.setAttribute( 'data-iftp-col-order', defaults.join( ' ' ) );
			trigger.classList.remove( 'iftp-ld-tool--has-hidden' );
			applyColumnOrder( wrap, defaults );
			syncMoveButtons();
			announce( i18n.columnsReset );
			scheduleSave();
		} );

		trigger.classList.toggle( 'iftp-ld-tool--has-hidden', hiddenCols().length > 0 );
	}

	function bindExportMenu( wrap ) {
		var trigger = wrap.querySelector( '[data-iftp-export-trigger]' );
		var panel = wrap.querySelector( '[data-iftp-export-menu]' );

		if ( ! trigger || ! panel ) {
			return;
		}

		var setOpen = popover( trigger, panel );

		// The download doesn't leave the page, so the menu closes itself.
		panel.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-iftp-export-option]' ) ) {
				setOpen( false );
				trigger.focus();
			}
		} );
	}

	// ---------------------------------------------------------------- filters, per page, scroll

	// An inline disclosure, remembered for the tab's session.
	function bindFilterPanel( wrap ) {
		var toggle = wrap.querySelector( '[data-iftp-filters-toggle]' );
		var panel = wrap.querySelector( '[data-iftp-filter-panel]' );
		var form = document.getElementById( 'iftp-ld-filter-form' );
		var key = 'undefined' !== typeof iftpLdEntriesStore && iftpLdEntriesStore.key ? iftpLdEntriesStore.key + ':panel' : '';

		if ( ! toggle || ! panel || ! form ) {
			return;
		}

		function setOpen( open, remember ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( remember && key ) {
				try {
					if ( open ) {
						window.sessionStorage.setItem( key, '1' );
					} else {
						window.sessionStorage.removeItem( key );
					}
				} catch ( e ) {
					// Starts closed next time.
				}
			}
		}

		try {
			if ( key && '1' === window.sessionStorage.getItem( key ) ) {
				setOpen( true, false );
			}
		} catch ( e ) {
			// Starts closed.
		}

		toggle.addEventListener( 'click', function () {
			var open = panel.hidden;

			setOpen( open, true );

			if ( open ) {
				form.querySelector( 'select' ).focus();
			}
		} );

		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				setOpen( false, true );
				toggle.focus();
			}
		} );

		// Blank fields stay out of the URL, so the view reads cleanly and saves as it is.
		form.addEventListener( 'submit', function () {
			Array.prototype.forEach.call( form.elements, function ( field ) {
				if ( field.name && '' === field.value ) {
					field.disabled = true;
				}
			} );
		} );

		// Submitting the form keeps unapplied edits in the other fields.
		panel.addEventListener( 'click', function ( event ) {
			var preset = event.target.closest( '[data-iftp-date-preset]' );

			if ( ! preset || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
				return;
			}

			event.preventDefault();
			form.elements.date_from.value = preset.getAttribute( 'data-iftp-date-from' );
			form.elements.date_to.value = preset.getAttribute( 'data-iftp-date-to' );
			submitForm( form );
		} );
	}

	// Arrowing a closed select fires change on Windows, so those don't reload (WCAG 3.2.2).
	function bindPerPage( wrap ) {
		var form = wrap.querySelector( '[data-iftp-per-page-form]' );
		var select = form ? form.querySelector( '[data-iftp-per-page]' ) : null;
		var go = form ? form.querySelector( '[data-iftp-per-page-submit]' ) : null;
		var custom = form ? form.querySelector( '[data-iftp-per-page-custom]' ) : null;
		var input = form ? form.querySelector( '#iftp-ld-per-page-custom' ) : null;
		var keyNav = false;

		if ( ! select ) {
			return;
		}

		function sync() {
			var isCustom = 'custom' === select.value;

			custom.hidden = ! isCustom;
			input.disabled = ! isCustom;
			input.required = isCustom;
			go.hidden = ! isCustom;

			return isCustom;
		}

		sync();

		select.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();

				if ( 'custom' === select.value ) {
					input.focus();
				} else {
					submitForm( form );
				}

				return;
			}

			keyNav = ! event.altKey && ( -1 !== [ 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown' ].indexOf( event.key ) || ( 1 === event.key.length && ' ' !== event.key ) );
		} );
		select.addEventListener( 'pointerdown', function () {
			keyNav = false;
		} );
		select.addEventListener( 'change', function () {
			if ( sync() ) {
				if ( ! keyNav ) {
					input.focus();
					input.select();
				}

				return;
			}

			if ( keyNav ) {
				go.hidden = false;
				return;
			}

			submitForm( form );
		} );
	}

	function bindScrollTop( wrap ) {
		var btn = wrap.querySelector( '[data-iftp-scroll-top]' );
		var queued = false;

		if ( ! btn ) {
			return;
		}

		window.addEventListener(
			'scroll',
			function () {
				if ( queued ) {
					return;
				}

				queued = true;
				window.requestAnimationFrame( function () {
					queued = false;
					btn.classList.toggle( 'iftp-ld-scroll-top--visible', window.scrollY > 600 );
				} );
			},
			{ passive: true }
		);

		btn.addEventListener( 'click', function () {
			var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( { top: 0, behavior: reduced ? 'auto' : 'smooth' } );
		} );
	}
} )( jQuery );
