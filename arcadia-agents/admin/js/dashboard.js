/**
 * Arcadia Agents — dashboard behaviour.
 *
 * Approve/reject over the same AJAX endpoints and nonce as before
 * (aa_approve_revision / aa_reject_revision), with two changes:
 * - no location.reload(): the decided row is removed and the pending
 *   counters decremented in place — a 50-item queue never re-scrolls;
 * - focus management: opening a confirm mini-form moves focus to its first
 *   button, cancelling returns it to the action that opened it.
 *
 * All text nodes are built with createElement/textContent — innerHTML is
 * never fed anything dynamic.
 */
( function () {
	'use strict';

	var data = window.aaDashboardData;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n || {};

	// -------------------------------------------------------
	// Counters + empty state
	// -------------------------------------------------------

	function decrementPendingCounters() {
		var value = document.getElementById( 'aa-pending-value' );
		if ( value ) {
			var current = parseInt( value.textContent, 10 );
			if ( ! isNaN( current ) && current > 0 ) {
				value.textContent = String( current - 1 );
			}
		}
	}

	function removeRow( row ) {
		var tr = row.closest( 'tr' );
		var table = document.getElementById( 'aa-pending-table' );
		if ( tr ) {
			tr.remove();
		}
		decrementPendingCounters();

		if ( table && ! table.querySelector( 'tbody tr' ) ) {
			table.hidden = true;
			var empty = document.getElementById( 'aa-pending-empty' );
			if ( empty ) {
				empty.hidden = false;
				empty.setAttribute( 'tabindex', '-1' );
				empty.focus();
			}
		}
	}

	// -------------------------------------------------------
	// Small DOM helpers (textContent only)
	// -------------------------------------------------------

	function makeButton( label, classes ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = classes;
		btn.textContent = label;
		return btn;
	}

	function makeStatus( row ) {
		var el = document.createElement( 'span' );
		el.className = 'aa-row-status';
		row.appendChild( el );
		return el;
	}

	function setBusy( row, busy ) {
		row.querySelectorAll( 'button, a' ).forEach( function ( el ) {
			el.disabled = busy;
			el.classList.toggle( 'aa-is-busy', busy );
		} );
	}

	// -------------------------------------------------------
	// AJAX
	// -------------------------------------------------------

	function postAction( action, revisionId, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'revision_id', revisionId );
		body.append( 'nonce', data.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				body.append( key, extra[ key ] );
			} );
		}
		return fetch( data.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// -------------------------------------------------------
	// Row wiring
	// -------------------------------------------------------

	function bindRow( row ) {
		var revisionId = row.dataset.revisionId;
		var original = row.innerHTML; // Our own static server markup.

		function restore( focusSelector ) {
			row.innerHTML = original;
			bindRow( row );
			if ( focusSelector ) {
				var target = row.querySelector( focusSelector );
				if ( target ) {
					target.focus();
				}
			}
		}

		function run( action, extra, statusEl ) {
			setBusy( row, true );
			statusEl.textContent = i18n.working || 'Working…';
			statusEl.className = 'aa-row-status';

			postAction( action, revisionId, extra )
				.then( function ( resp ) {
					if ( resp && resp.success ) {
						removeRow( row );
						return;
					}
					// Server data goes through textContent — never innerHTML.
					statusEl.textContent =
						resp && typeof resp.data === 'string'
							? resp.data
							: i18n.error_generic || 'Request failed.';
					statusEl.className = 'aa-row-status aa-row-status--bad';
					setBusy( row, false );
				} )
				.catch( function () {
					statusEl.textContent = i18n.error_generic || 'Request failed.';
					statusEl.className = 'aa-row-status aa-row-status--bad';
					setBusy( row, false );
				} );
		}

		var approveBtn = row.querySelector( '.aa-dash-approve' );
		var rejectBtn = row.querySelector( '.aa-dash-reject' );

		if ( approveBtn ) {
			approveBtn.addEventListener( 'click', function () {
				row.textContent = '';

				var question = document.createElement( 'span' );
				question.className = 'aa-row-status';
				question.textContent = i18n.confirm_approve || 'Approve this proposal?';

				var yes = makeButton( i18n.approve || 'Approve', 'aa-btn aa-btn--primary aa-btn--small' );
				var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--ghost aa-btn--small' );

				row.appendChild( question );
				row.appendChild( yes );
				row.appendChild( no );

				yes.focus();

				yes.addEventListener( 'click', function () {
					var statusEl = makeStatus( row );
					run( 'aa_approve_revision', null, statusEl );
				} );
				no.addEventListener( 'click', function () {
					restore( '.aa-dash-approve' );
				} );
			} );
		}

		if ( rejectBtn ) {
			rejectBtn.addEventListener( 'click', function () {
				row.textContent = '';

				var notes = document.createElement( 'textarea' );
				notes.className = 'aa-reject-notes';
				notes.rows = 2;
				notes.placeholder = i18n.reject_prompt || 'Reason for rejection (optional):';

				var actions = document.createElement( 'div' );
				actions.className = 'aa-row-actions__confirm';

				var confirm = makeButton( i18n.reject || 'Reject', 'aa-btn aa-btn--warn aa-btn--small' );
				var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--ghost aa-btn--small' );

				actions.appendChild( confirm );
				actions.appendChild( no );
				row.appendChild( notes );
				row.appendChild( actions );

				notes.focus();

				confirm.addEventListener( 'click', function () {
					var statusEl = makeStatus( row );
					run( 'aa_reject_revision', { decision_notes: notes.value }, statusEl );
				} );
				no.addEventListener( 'click', function () {
					restore( '.aa-dash-reject' );
				} );
			} );
		}
	}

	document.querySelectorAll( '.aa-row-actions' ).forEach( bindRow );
} )();
