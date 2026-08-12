/**
 * Arcadia Agents — settings page behaviour.
 *
 * Three responsibilities, all UX-only (security stays server-side):
 * - FS-4 guard dialog: switching the guard OFF asks for explicit
 *   confirmation, and confirming persists immediately (requestSubmit with
 *   the Save button) so the perceived state is always the stored state.
 * - Permission toggletips: click toggles, Escape closes, hover is handled
 *   by CSS on the wrapper (WCAG 1.4.13).
 * - Test connection: renders server data with textContent only — never
 *   innerHTML.
 */
( function () {
	'use strict';

	var data = window.aaSettingsData || { i18n: {} };

	// -------------------------------------------------------
	// FS-4 — guard confirmation dialog
	// -------------------------------------------------------

	var toggle = document.getElementById( 'aa-guard-toggle' );
	var dialog = document.getElementById( 'aa-guard-dialog' );
	var form = document.getElementById( 'aa-settings-form' );
	var saveButton = document.getElementById( 'aa-save-settings' );

	if ( toggle && dialog && form && typeof dialog.showModal === 'function' ) {
		var cancelButton = document.getElementById( 'aa-guard-cancel' );
		var confirmButton = document.getElementById( 'aa-guard-confirm' );

		var revertToggle = function () {
			toggle.checked = true;
		};

		toggle.addEventListener( 'change', function () {
			// Only switching the guard OFF needs confirmation.
			if ( ! toggle.checked ) {
				dialog.showModal();
				// Focus starts on the safe action.
				if ( cancelButton ) {
					cancelButton.focus();
				}
			}
		} );

		if ( cancelButton ) {
			cancelButton.addEventListener( 'click', function () {
				dialog.close( 'cancel' );
			} );
		}

		if ( confirmButton ) {
			confirmButton.addEventListener( 'click', function () {
				dialog.close( 'confirm' );
			} );
		}

		// Escape triggers 'cancel' via the native close event; backdrop
		// clicks are treated as cancel too.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close( 'cancel' );
			}
		} );

		dialog.addEventListener( 'close', function () {
			if ( 'confirm' === dialog.returnValue ) {
				// Persist immediately: confirming IS saving. requestSubmit
				// with the Save button so the right POST branch runs.
				if ( saveButton && typeof form.requestSubmit === 'function' ) {
					form.requestSubmit( saveButton );
				} else {
					form.submit();
				}
				return;
			}
			// Cancelled (button, Escape or backdrop): visually revert and
			// hand focus back to the toggle.
			revertToggle();
			toggle.focus();
		} );
	}

	// -------------------------------------------------------
	// Permission toggletips
	// -------------------------------------------------------

	var helpButtons = Array.prototype.slice.call(
		document.querySelectorAll( '.aa-help__btn' )
	);

	var closeAllTips = function ( except ) {
		helpButtons.forEach( function ( btn ) {
			if ( btn !== except ) {
				btn.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	};

	helpButtons.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var isOpen = 'true' === btn.getAttribute( 'aria-expanded' );
			closeAllTips( btn );
			btn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeAllTips( null );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.aa-help' ) ) {
			closeAllTips( null );
		}
	} );

	// -------------------------------------------------------
	// Test connection (health endpoint)
	// -------------------------------------------------------

	var testButton = document.getElementById( 'arcadia-test-connection' );
	var resultEl = document.getElementById( 'arcadia-test-result' );

	if ( testButton && resultEl && data.restUrl ) {
		testButton.addEventListener( 'click', function () {
			resultEl.textContent = data.i18n.testing || 'Testing…';
			resultEl.className = 'aa-test-result';

			fetch( data.restUrl )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( health ) {
					var message = data.i18n.health_ok || 'Connection OK — plugin version %s.';
					var version = health && health.version ? String( health.version ) : '?';
					// Server data goes through textContent — never innerHTML.
					resultEl.textContent = '✓ ' + message.replace( '%s', version );
					resultEl.className = 'aa-test-result aa-test-result--ok';
				} )
				.catch( function ( error ) {
					var prefix = data.i18n.error_prefix || 'Error:';
					var detail = error && error.message ? error.message : ( data.i18n.error_generic || 'Request failed.' );
					resultEl.textContent = '✗ ' + prefix + ' ' + detail;
					resultEl.className = 'aa-test-result aa-test-result--bad';
				} );
		} );
	}
} )();
