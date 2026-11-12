/**
 * Arcadia Agents — dashboard behaviour.
 *
 * Approve/reject over the same AJAX endpoints and nonce as the metabox
 * (aa_approve_revision / aa_reject_revision), with:
 * - one script for two queues: the dashboard (article revisions) and the
 *   pending-actions screen (deletions, categories, media, redirects). The page
 *   payload names its AJAX actions and id field; revisions are the default;
 * - no location.reload(): the decided row is removed and the pending
 *   counters decremented in place — a 50-item queue never re-scrolls;
 * - focus management: opening a confirm mini-form moves focus to its first
 *   button, cancelling returns it to the action that opened it;
 * - selection + bulk actions: the bar only exists while something is
 *   selected. A bulk run ends on a short tally ("3 applied · 1 failed"):
 *   the "failed" part is a link to the failed row, which turns red and says
 *   why under the item's name — no sentence explaining the mechanism.
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
	var approveAction = data.approveAction || 'aa_approve_revision';
	var rejectAction = data.rejectAction || 'aa_reject_revision';
	var idField = data.idField || 'revision_id';

	/**
	 * Minimal sprintf for the %s / %1$s placeholders WordPress ships in
	 * translated strings. Values are inserted into textContent only.
	 *
	 * @param {string} template Localized template.
	 * @param {Array}  values   Replacements, in order.
	 * @return {string} Filled template.
	 */
	function format( template, values ) {
		var index = 0;
		return String( template ).replace( /%(\d+\$)?s/g, function ( match, position ) {
			if ( position ) {
				return String( values[ parseInt( position, 10 ) - 1 ] );
			}
			return String( values[ index++ ] );
		} );
	}

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

	function removeTr( tr ) {
		var table = document.getElementById( 'aa-pending-table' );
		if ( tr ) {
			tr.remove();
		}
		decrementPendingCounters();

		if ( table && ! table.querySelector( 'tbody tr' ) ) {
			table.hidden = true;
			var bar = document.getElementById( 'aa-bulkbar' );
			if ( bar ) {
				bar.hidden = true;
			}
			var empty = document.getElementById( 'aa-pending-empty' );
			if ( empty ) {
				empty.hidden = false;
				empty.setAttribute( 'tabindex', '-1' );
				empty.focus();
			}
		}
	}

	function removeRow( row ) {
		removeTr( row.closest( 'tr' ) );
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

	/**
	 * Marks a row that a bulk run could not process: the row itself turns red
	 * and the reason goes under the item's name (the .aa-row-error slot), so
	 * the tally's "1 failed" link leads to something you recognise at once.
	 * Pages without the slot fall back to the actions cell.
	 */
	function showRowError( check, message ) {
		var tr = check ? check.closest( 'tr' ) : null;
		if ( ! tr ) {
			return;
		}
		tr.classList.add( 'aa-row--failed' );
		// Server data goes through textContent — never innerHTML.
		var text = message || i18n.error_generic || 'Request failed.';
		var slot = tr.querySelector( '.aa-row-error' );
		if ( slot ) {
			slot.textContent = text;
			slot.hidden = false;
			return;
		}
		var row = tr.querySelector( '.aa-row-actions' );
		if ( row ) {
			var statusEl = makeStatus( row );
			statusEl.textContent = text;
			statusEl.className = 'aa-row-status aa-row-status--bad';
		}
	}

	/** Clears the marks a previous bulk run left, before the next one. */
	function clearRowErrors() {
		document.querySelectorAll( '.aa-row--failed' ).forEach( function ( tr ) {
			tr.classList.remove( 'aa-row--failed' );
			var slot = tr.querySelector( '.aa-row-error' );
			if ( slot ) {
				slot.textContent = '';
				slot.hidden = true;
			}
		} );
	}

	/** Scrolls to the first failed row and puts focus on its checkbox. */
	function goToFirstFailure() {
		var tr = document.querySelector( '.aa-row--failed' );
		if ( ! tr ) {
			return;
		}
		tr.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		var check = tr.querySelector( '.aa-row-check' );
		if ( check ) {
			check.focus( { preventScroll: true } );
		}
		tr.classList.remove( 'aa-row--flash' );
		// Restart the highlight even on a second click.
		void tr.offsetWidth;
		tr.classList.add( 'aa-row--flash' );
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
		body.append( idField, revisionId );
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

	/**
	 * The error of a failed AJAX answer. The pending-actions handlers send
	 * {message, code}; the revision handlers send the message alone.
	 *
	 * @param {Object} resp JSON answer.
	 * @return {{message: (string|null), code: string}} Error.
	 */
	function errorOf( resp ) {
		var payload = resp ? resp.data : null;
		if ( typeof payload === 'string' ) {
			return { message: payload, code: '' };
		}
		return {
			message: payload && typeof payload.message === 'string' ? payload.message : null,
			code: payload && typeof payload.code === 'string' ? payload.code : '',
		};
	}

	/**
	 * Out of date: the item changed on the site after Arcadia proposed. Not a
	 * failure to fix — the proposal is simply gone (it moves to the decisions
	 * as "out of date"), so the row leaves the list instead of turning red.
	 */
	var STALE = 'pending_action_stale';

	// -------------------------------------------------------
	// Selection + bulk bar
	// -------------------------------------------------------

	var bulkBar = document.getElementById( 'aa-bulkbar' );
	var bulkCount = document.getElementById( 'aa-bulkbar-count' );
	var bulkActions = document.getElementById( 'aa-bulkbar-actions' );
	var selectAll = document.getElementById( 'aa-select-all' );

	function rowChecks() {
		return Array.prototype.slice.call( document.querySelectorAll( '.aa-row-check' ) );
	}

	function selectedChecks() {
		return rowChecks().filter( function ( check ) {
			return check.checked;
		} );
	}

	/** Renders the bar's resting state: count + Approve / Reject / Clear. */
	function renderBulkBar() {
		if ( ! bulkBar ) {
			return;
		}

		var all = rowChecks();
		var selected = selectedChecks();

		if ( selectAll ) {
			selectAll.checked = all.length > 0 && selected.length === all.length;
			selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
			// The box is the only clear-selection control, so its label has to
			// name the action its next click performs, not a fixed "select all".
			var label = selected.length ? i18n.clear_selection : i18n.select_all;
			if ( label ) {
				selectAll.setAttribute( 'aria-label', label );
				selectAll.title = label;
			}
		}

		if ( ! selected.length ) {
			bulkBar.hidden = true;
			bulkCount.textContent = '';
			bulkActions.textContent = '';
			return;
		}

		bulkBar.hidden = false;
		bulkBar.dataset.state = 'idle';
		bulkCount.textContent = format(
			1 === selected.length ? i18n.selected_one : i18n.selected_many,
			[ selected.length ]
		);

		bulkActions.textContent = '';

		var approve = makeButton( i18n.approve || 'Approve', 'aa-btn aa-btn--primary aa-btn--small' );
		var reject = makeButton( i18n.reject || 'Reject', 'aa-btn aa-btn--ghost aa-btn--small' );

		approve.addEventListener( 'click', function () {
			confirmBulkApprove();
		} );
		reject.addEventListener( 'click', function () {
			confirmBulkReject();
		} );

		bulkActions.appendChild( approve );
		bulkActions.appendChild( reject );
	}

	/**
	 * Bulk approve publishes N articles at once — the count goes in the
	 * question, so nobody confirms "some proposals".
	 */
	function confirmBulkApprove() {
		var ids = selectedChecks().map( function ( check ) {
			return check.value;
		} );

		bulkBar.dataset.state = 'confirm';
		bulkCount.textContent = format(
			1 === ids.length ? i18n.bulk_confirm_approve_one : i18n.bulk_confirm_approve_many,
			[ ids.length ]
		);
		bulkActions.textContent = '';

		var yes = makeButton( i18n.approve || 'Approve', 'aa-btn aa-btn--primary aa-btn--small' );
		var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--quiet aa-btn--small' );

		yes.addEventListener( 'click', function () {
			runBulk( approveAction, ids, null );
		} );
		no.addEventListener( 'click', renderBulkBar );

		bulkActions.appendChild( yes );
		bulkActions.appendChild( no );
		yes.focus();
	}

	/** Bulk reject: one shared reason, applied to every selected proposal. */
	function confirmBulkReject() {
		var ids = selectedChecks().map( function ( check ) {
			return check.value;
		} );

		bulkBar.dataset.state = 'confirm';
		bulkCount.textContent = format(
			1 === ids.length ? i18n.bulk_confirm_reject_one : i18n.bulk_confirm_reject_many,
			[ ids.length ]
		);
		bulkActions.textContent = '';

		var notes = document.createElement( 'textarea' );
		notes.className = 'aa-reject-notes aa-bulkbar__notes';
		notes.rows = 1;
		notes.placeholder = i18n.bulk_reject_prompt || i18n.reject_prompt || '';

		var confirm = makeButton( i18n.reject || 'Reject', 'aa-btn aa-btn--ink aa-btn--small' );
		var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--quiet aa-btn--small' );

		confirm.addEventListener( 'click', function () {
			runBulk( rejectAction, ids, { decision_notes: notes.value } );
		} );
		no.addEventListener( 'click', renderBulkBar );

		bulkActions.appendChild( notes );
		bulkActions.appendChild( confirm );
		bulkActions.appendChild( no );
		notes.focus();
	}

	/**
	 * Runs one action over a selection, one request at a time so a 50-item
	 * queue doesn't fire 50 concurrent writes. Rows that succeed disappear as
	 * they go; rows that fail stay listed, and the tally says so.
	 *
	 * @param {string}      action Ajax action name.
	 * @param {Array}       ids    Revision IDs.
	 * @param {Object|null} extra  Extra POST fields.
	 */
	function runBulk( action, ids, extra ) {
		var done = 0;
		var failed = 0;
		var stale = 0;

		clearRowErrors();
		bulkActions.textContent = '';
		bulkBar.dataset.state = 'running';
		rowChecks().forEach( function ( check ) {
			check.disabled = true;
		} );
		if ( selectAll ) {
			selectAll.disabled = true;
		}
		document.querySelectorAll( '.aa-row-actions' ).forEach( function ( row ) {
			setBusy( row, true );
		} );

		function finish() {
			rowChecks().forEach( function ( check ) {
				check.disabled = false;
				check.checked = false;
			} );
			if ( selectAll ) {
				selectAll.disabled = false;
				selectAll.checked = false;
				selectAll.indeterminate = false;
			}
			document.querySelectorAll( '.aa-row-actions' ).forEach( function ( row ) {
				setBusy( row, false );
			} );

			report();
		}

		/**
		 * The tally, in as few words as possible: what was done, then — only if
		 * something failed — a link to it. The failed rows say why themselves.
		 */
		function report() {
			var approving = action === approveAction;
			bulkBar.hidden = false;
			bulkBar.dataset.state = 'report';
			bulkCount.textContent = '';
			bulkActions.textContent = '';

			if ( done ) {
				var doneKey = ( approving ? 'bulk_done_approve_' : 'bulk_done_reject_' ) + ( 1 === done ? 'one' : 'many' );
				bulkCount.appendChild( document.createTextNode( format( i18n[ doneKey ], [ done ] ) ) );
			}

			var dismiss = makeButton( i18n.dismiss || 'OK', 'aa-btn aa-btn--quiet aa-btn--small' );
			dismiss.addEventListener( 'click', renderBulkBar );

			if ( stale ) {
				if ( done ) {
					bulkCount.appendChild( document.createTextNode( ' · ' ) );
				}
				var gone = document.createElement( 'span' );
				gone.className = 'aa-bulkbar__quiet';
				gone.textContent = format( 1 === stale ? i18n.bulk_stale_one : i18n.bulk_stale_many, [ stale ] );
				bulkCount.appendChild( gone );
			}

			if ( failed ) {
				if ( done || stale ) {
					bulkCount.appendChild( document.createTextNode( ' · ' ) );
				}
				var link = makeButton(
					format( i18n.bulk_failed || '%s failed', [ failed ] ),
					'aa-link-button aa-link-button--bad'
				);
				link.addEventListener( 'click', goToFirstFailure );
				bulkCount.appendChild( link );
				bulkActions.appendChild( dismiss );
				link.focus();
				return;
			}

			bulkActions.appendChild( dismiss );
			dismiss.focus();
		}

		function step( index ) {
			if ( index >= ids.length ) {
				finish();
				return;
			}

			bulkCount.textContent = format( i18n.bulk_progress, [ index + 1, ids.length ] );

			var check = document.querySelector( '.aa-row-check[value="' + ids[ index ] + '"]' );

			postAction( action, ids[ index ], extra )
				.then( function ( resp ) {
					if ( resp && resp.success ) {
						done++;
						if ( check ) {
							removeTr( check.closest( 'tr' ) );
						}
					} else if ( STALE === errorOf( resp ).code ) {
						stale++;
						if ( check ) {
							removeTr( check.closest( 'tr' ) );
						}
					} else {
						failed++;
						// Say WHY on the row that stayed.
						showRowError( check, errorOf( resp ).message );
					}
				} )
				.catch( function () {
					failed++;
					showRowError( check, null );
				} )
				.then( function () {
					step( index + 1 );
				} );
		}

		step( 0 );
	}

	if ( selectAll ) {
		// Not the native toggle: with a partial selection the box shows a minus,
		// and a minus has to mean "clear", otherwise the only way back to zero is
		// unticking rows one by one — the very cost the selection exists to avoid.
		selectAll.addEventListener( 'click', function () {
			var target = 0 === selectedChecks().length;
			rowChecks().forEach( function ( check ) {
				check.checked = target;
			} );
			lastClicked = null;
			renderBulkBar();
		} );
	}

	// Shift-click extends from the last checkbox clicked, the way file managers
	// and mail clients do — picking 12 consecutive rows is one gesture, not 12.
	var lastClicked = null;

	rowChecks().forEach( function ( check ) {
		check.addEventListener( 'click', function ( event ) {
			var checks = rowChecks();
			var from = checks.indexOf( lastClicked );
			var to = checks.indexOf( check );

			if ( event.shiftKey && from > -1 && to > -1 && from !== to ) {
				var start = Math.min( from, to );
				var end = Math.max( from, to );
				for ( var i = start; i <= end; i++ ) {
					// The clicked box already carries its new state: the range
					// follows it, so shift-click both selects and deselects.
					checks[ i ].checked = check.checked;
				}
			}

			lastClicked = check;
			renderBulkBar();
		} );
	} );

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
						renderBulkBar();
						return;
					}
					var error = errorOf( resp );
					if ( STALE === error.code ) {
						// Said once, quietly, then the row leaves the list. The
						// message goes under the item's name and the buttons
						// leave: nothing is left to click on this row.
						var staleText = i18n.stale_row || error.message;
						var staleTr = row.closest( 'tr' );
						var staleSlot = staleTr ? staleTr.querySelector( '.aa-row-error' ) : null;
						if ( staleSlot ) {
							staleSlot.textContent = staleText;
							staleSlot.classList.add( 'aa-row-error--quiet' );
							staleSlot.hidden = false;
							row.textContent = '';
						} else {
							statusEl.textContent = staleText;
							statusEl.className = 'aa-row-status';
						}
						window.setTimeout( function () {
							removeRow( row );
							renderBulkBar();
						}, 2500 );
						return;
					}
					// Server data goes through textContent — never innerHTML.
					statusEl.textContent = error.message || i18n.error_generic || 'Request failed.';
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
				var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--quiet aa-btn--small' );

				row.appendChild( question );
				row.appendChild( yes );
				row.appendChild( no );

				yes.focus();

				yes.addEventListener( 'click', function () {
					var statusEl = makeStatus( row );
					run( approveAction, null, statusEl );
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

				var confirm = makeButton( i18n.reject || 'Reject', 'aa-btn aa-btn--ink aa-btn--small' );
				var no = makeButton( i18n.cancel || 'Cancel', 'aa-btn aa-btn--quiet aa-btn--small' );

				actions.appendChild( confirm );
				actions.appendChild( no );
				row.appendChild( notes );
				row.appendChild( actions );

				notes.focus();

				confirm.addEventListener( 'click', function () {
					var statusEl = makeStatus( row );
					run( rejectAction, { decision_notes: notes.value }, statusEl );
				} );
				no.addEventListener( 'click', function () {
					restore( '.aa-dash-reject' );
				} );
			} );
		}
	}

	document.querySelectorAll( '.aa-row-actions' ).forEach( bindRow );

	// Escape backs out one level: an open bulk confirmation first, then the
	// selection. A confirmation you can only leave by aiming at "Cancel" is a
	// trap — every other confirm on the web answers to Escape.
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key || ! bulkBar || bulkBar.hidden ) {
			return;
		}

		if ( 'running' === bulkBar.dataset.state ) {
			return;
		}

		if ( 'idle' !== bulkBar.dataset.state ) {
			renderBulkBar();
			return;
		}

		rowChecks().forEach( function ( check ) {
			check.checked = false;
		} );
		renderBulkBar();
	} );
} )();
