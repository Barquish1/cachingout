/**
 * Database cleanup tab — AJAX handlers for Preview and Run operations.
 *
 * @package CachingOut
 * @since 0.1.0
 */

( function ( doc ) {
	'use strict';

	if ( typeof cachingOutDB === 'undefined' ) {
		return;
	}

	/**
	 * Format bytes as human-readable string.
	 *
	 * @param {number} bytes
	 * @return {string}
	 */
	function formatBytes( bytes ) {
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( bytes >= 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return bytes + ' B';
	}

	/**
	 * Send an AJAX request to the cleanup endpoint.
	 *
	 * @param {string}   action   'cachingout_db_preview' or 'cachingout_db_run'.
	 * @param {string[]} ops      Operation names.
	 * @return {Promise}
	 */
	function dbAjax( action, ops ) {
		return fetch( cachingOutDB.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: action,
				nonce: cachingOutDB.nonce,
				operations: ops.join( ',' ),
			} ),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Update a row's count cell with result data.
	 *
	 * @param {string} op    Operation key.
	 * @param {Object} data  { count, space_saved }.
	 */
	function updateCountCell( op, data ) {
		if ( ! data ) {
			return;
		}
		var countEl = doc.querySelector(
			'.rs-db-count-value[data-op="' + op + '"]'
		);
		var estEl = countEl
			? countEl.nextElementSibling
			: null;

		if ( countEl ) {
			countEl.textContent = data.count;
		}
		if ( estEl && data.space_saved !== undefined ) {
			estEl.textContent = ' (' + formatBytes( data.space_saved ) + ' estimated)';
		}
	}

	/**
	 * Disable / re-enable buttons in a row.
	 *
	 * @param {string}  op       Operation key.
	 * @param {boolean} disabled
	 */
	function setRowBusy( op, disabled ) {
		var row = doc.querySelector( '.rs-db-row[data-operation="' + op + '"]' );
		if ( ! row ) {
			return;
		}
		var buttons = row.querySelectorAll( 'button' );
		buttons.forEach( function ( btn ) {
			// Preview-first (1.21.8): Run stays locked until this row has a
			// count, and locks again after a run until the next preview.
			if ( ! disabled && btn.classList.contains( 'rs-db-run' ) ) {
				btn.disabled = row.getAttribute( 'data-previewed' ) !== '1';
				return;
			}
			btn.disabled = disabled;
		} );
	}

	/**
	 * Record whether a row has a fresh preview count.
	 *
	 * @param {string}  op        Operation key.
	 * @param {boolean} previewed
	 */
	function setPreviewed( op, previewed ) {
		var row = doc.querySelector( '.rs-db-row[data-operation="' + op + '"]' );
		if ( row ) {
			row.setAttribute( 'data-previewed', previewed ? '1' : '0' );
		}
	}

	/**
	 * Show a results summary.
	 *
	 * @param {Object} results Map of op -> { count, space_saved }.
	 */
	function showResults( results ) {
		var container = doc.querySelector( '.rs-db-results' );
		var inner     = doc.querySelector( '.rs-db-results-inner' );

		if ( ! container || ! inner ) {
			return;
		}

		var total       = 0;
		var totalBytes  = 0;
		var html        = '<ul>';

		Object.keys( results ).forEach( function ( op ) {
			var data = results[ op ];
			total      += data.count || 0;
			totalBytes += data.space_saved || 0;
			var name = doc.querySelector(
				'.rs-db-row[data-operation="' + op + '"] .rs-db-col-name strong'
			);
			var label = name ? name.textContent : op;
			html += '<li><strong>' + label + ':</strong> ' + ( data.count || 0 ) +
				' items removed (' + formatBytes( data.space_saved || 0 ) + ' estimated)</li>';
		} );

		html += '</ul>';
		html += '<p><strong>Total:</strong> ' + total + ' items, ' +
			formatBytes( totalBytes ) + ' estimated space freed.</p>';

		inner.innerHTML = html;
		container.style.display = 'block';
	}

	// ───── Preview single operation ─────

	doc.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.rs-db-preview' );
		if ( ! btn ) {
			return;
		}

		var op    = btn.getAttribute( 'data-op' );
		var nonce = btn.getAttribute( 'data-nonce' );

		if ( ! op || ! nonce ) {
			return;
		}

		setRowBusy( op, true );

		dbAjax( 'cachingout_db_preview', [ op ] )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.operations ) {
					updateCountCell( op, json.data.operations[ op ] );
					setPreviewed( op, true );
				}
			} )
			.finally( function () {
				setRowBusy( op, false );
			} );
	} );

	// ───── Run single operation ─────

	doc.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.rs-db-run' );
		if ( ! btn ) {
			return;
		}

		var op    = btn.getAttribute( 'data-op' );
		var nonce = btn.getAttribute( 'data-nonce' );

		if ( ! op || ! nonce ) {
			return;
		}

		if (
			! window.confirm(
				'Are you sure you want to run this cleanup? This action cannot be undone.'
			)
		) {
			return;
		}

		setRowBusy( op, true );

		dbAjax( 'cachingout_db_run', [ op ] )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.operations ) {
					updateCountCell( op, json.data.operations[ op ] );
					showResults( json.data.operations );
				}
			} )
			.finally( function () {
				// The count cell now shows what was removed, not what is
				// left: a new preview is needed before the next run.
				setPreviewed( op, false );
				setRowBusy( op, false );
			} );
	} );

	// ───── Preview All ─────

	doc.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.rs-db-preview-all' );
		if ( ! btn ) {
			return;
		}

		var ops   = [];
		var rows  = doc.querySelectorAll( '.rs-db-row' );
		var nonce = btn.getAttribute( 'data-nonce' );

		rows.forEach( function ( row ) {
			var op = row.getAttribute( 'data-operation' );
			if ( op ) {
				ops.push( op );
				setRowBusy( op, true );
			}
		} );

		dbAjax( 'cachingout_db_preview', ops )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.operations ) {
					Object.keys( json.data.operations ).forEach( function ( op ) {
						updateCountCell( op, json.data.operations[ op ] );
						setPreviewed( op, true );
					} );
				}
			} )
			.finally( function () {
				ops.forEach( function ( op ) {
					setRowBusy( op, false );
				} );
			} );
	} );

	// ───── Run All ─────

	doc.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.rs-db-run-all' );
		if ( ! btn ) {
			return;
		}

		if (
			! window.confirm(
				'Are you sure you want to run ALL cleanup operations? This action cannot be undone.'
			)
		) {
			return;
		}

		var ops   = [];
		var rows  = doc.querySelectorAll( '.rs-db-row' );
		var nonce = btn.getAttribute( 'data-nonce' );

		// "Run All Previewed" means exactly that (1.21.8): a row that has not
		// shown its count is left alone.
		rows.forEach( function ( row ) {
			var op = row.getAttribute( 'data-operation' );
			if ( op && row.getAttribute( 'data-previewed' ) === '1' ) {
				ops.push( op );
				setRowBusy( op, true );
			}
		} );

		if ( ! ops.length ) {
			window.alert( 'Preview at least one operation first. Run only removes what you have seen.' );
			return;
		}

		dbAjax( 'cachingout_db_run', ops )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.operations ) {
					Object.keys( json.data.operations ).forEach( function ( op ) {
						updateCountCell( op, json.data.operations[ op ] );
					} );
					showResults( json.data.operations );
				}
			} )
			.finally( function () {
				ops.forEach( function ( op ) {
					setPreviewed( op, false );
					setRowBusy( op, false );
				} );
			} );
	} );

} )( document );