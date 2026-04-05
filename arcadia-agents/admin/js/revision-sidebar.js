/**
 * Arcadia Agents — Pending Revision sidebar panel for the block editor.
 *
 * Registers a PluginDocumentSettingPanel in the Post sidebar
 * showing pending revision info with Preview / Approve / Reject actions.
 * All confirmation flows are inline (no browser alerts).
 *
 * @since 0.2.0
 */
( function( wp ) {
	var el       = wp.element.createElement;
	var useState = wp.element.useState;
	var Button   = wp.components.Button;
	var TextareaControl = wp.components.TextareaControl;
	var registerPlugin  = wp.plugins.registerPlugin;

	// WP 6.6+ moved PluginDocumentSettingPanel to wp.editor; older versions use wp.editPost.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	var data = window.aaRevisionData;
	if ( ! data || ! data.has_pending || ! PluginDocumentSettingPanel ) {
		return;
	}

	/**
	 * Send an AJAX action to the server.
	 */
	function doAction( action, extraData ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'revision_id', data.revision_id );
		formData.append( 'nonce', data.nonce );
		if ( extraData ) {
			Object.keys( extraData ).forEach( function( k ) {
				formData.append( k, extraData[ k ] );
			} );
		}
		return fetch( data.ajax_url, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		} ).then( function( r ) {
			return r.json();
		} );
	}

	/**
	 * Main panel component.
	 */
	function RevisionPanel() {
		// 'idle' | 'confirm-approve' | 'confirm-reject' | 'approving' | 'rejecting' | 'done' | 'error'
		var statusState     = useState( 'idle' );
		var status          = statusState[0];
		var setStatus       = statusState[1];
		var notesState      = useState( '' );
		var rejectNotes     = notesState[0];
		var setRejectNotes  = notesState[1];
		var msgState        = useState( '' );
		var message         = msgState[0];
		var setMessage      = msgState[1];

		var processing = status === 'approving' || status === 'rejecting' || status === 'done';

		function sendApprove() {
			setStatus( 'approving' );
			setMessage( data.i18n.approving );
			doAction( 'aa_approve_revision' )
				.then( function( resp ) {
					if ( resp.success ) {
						setStatus( 'done' );
						setMessage( data.i18n.approved );
						setTimeout( function() { location.reload(); }, 1000 );
					} else {
						setStatus( 'error' );
						setMessage( resp.data || 'Error' );
					}
				} )
				.catch( function() {
					setStatus( 'error' );
					setMessage( data.i18n.network_error );
				} );
		}

		function sendReject() {
			setStatus( 'rejecting' );
			setMessage( data.i18n.rejecting );
			doAction( 'aa_reject_revision', { decision_notes: rejectNotes } )
				.then( function( resp ) {
					if ( resp.success ) {
						setStatus( 'done' );
						setMessage( data.i18n.rejected );
						setTimeout( function() { location.reload(); }, 1000 );
					} else {
						setStatus( 'error' );
						setMessage( resp.data || 'Error' );
					}
				} )
				.catch( function() {
					setStatus( 'error' );
					setMessage( data.i18n.network_error );
				} );
		}

		function resetToIdle() {
			setStatus( 'idle' );
			setMessage( '' );
		}

		var children = [];

		// Info banner — always visible.
		children.push(
			el( 'div', {
				key: 'info',
				style: {
					background: '#fff3cd',
					border: '1px solid #ffc107',
					borderRadius: '4px',
					padding: '12px',
					marginBottom: '12px',
				},
			},
				el( 'strong', null, 'v' + data.version ),
				el( 'span', {
					style: { color: '#664d03', marginLeft: '8px' },
				}, data.date ),
				data.notes
					? el( 'p', {
						style: {
							margin: '8px 0 0',
							fontStyle: 'italic',
							color: '#664d03',
							fontSize: '12px',
						},
					}, '\u201C' + data.notes + '\u201D' )
					: null
			)
		);

		// ---------- IDLE: show action buttons ----------
		if ( status === 'idle' || status === 'error' ) {
			children.push(
				el( 'div', {
					key: 'buttons',
					style: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
				},
					el( Button, {
						href: data.preview_url,
						target: '_blank',
						variant: 'secondary',
						size: 'compact',
					}, data.i18n.preview ),
					el( Button, {
						variant: 'primary',
						size: 'compact',
						onClick: function() { setStatus( 'confirm-approve' ); setMessage( '' ); },
					}, data.i18n.approve ),
					el( Button, {
						variant: 'secondary',
						size: 'compact',
						isDestructive: true,
						onClick: function() { setStatus( 'confirm-reject' ); setMessage( '' ); },
					}, data.i18n.reject )
				)
			);
		}

		// ---------- CONFIRM APPROVE ----------
		if ( status === 'confirm-approve' ) {
			children.push(
				el( 'div', {
					key: 'confirm-approve',
					style: {
						background: '#d1e7dd',
						border: '1px solid #a3cfbb',
						borderRadius: '4px',
						padding: '12px',
					},
				},
					el( 'p', {
						style: { margin: '0 0 10px', fontSize: '13px', color: '#0a5c36' },
					}, data.i18n.approve_confirm ),
					el( 'div', { style: { display: 'flex', gap: '8px' } },
						el( Button, {
							variant: 'primary',
							size: 'compact',
							onClick: sendApprove,
						}, data.i18n.confirm_approve ),
						el( Button, {
							variant: 'tertiary',
							size: 'compact',
							onClick: resetToIdle,
						}, data.i18n.cancel )
					)
				)
			);
		}

		// ---------- CONFIRM REJECT ----------
		if ( status === 'confirm-reject' ) {
			children.push(
				el( 'div', {
					key: 'confirm-reject',
					style: {
						background: '#f8d7da',
						border: '1px solid #f1aeb5',
						borderRadius: '4px',
						padding: '12px',
					},
				},
					el( TextareaControl, {
						label: data.i18n.reject_notes,
						value: rejectNotes,
						onChange: setRejectNotes,
						rows: 3,
					} ),
					el( 'div', { style: { display: 'flex', gap: '8px' } },
						el( Button, {
							variant: 'secondary',
							isDestructive: true,
							size: 'compact',
							onClick: sendReject,
						}, data.i18n.confirm_reject ),
						el( Button, {
							variant: 'tertiary',
							size: 'compact',
							onClick: resetToIdle,
						}, data.i18n.cancel )
					)
				)
			);
		}

		// ---------- PROCESSING / DONE ----------
		if ( processing ) {
			var spinnerColor = status === 'done' ? '#0a5c36' : '#664d03';
			children.push(
				el( 'div', {
					key: 'processing',
					style: {
						textAlign: 'center',
						padding: '8px 0',
						color: spinnerColor,
						fontSize: '13px',
					},
				}, message )
			);
		}

		// ---------- ERROR ----------
		if ( status === 'error' && message ) {
			children.push(
				el( 'div', {
					key: 'error',
					style: {
						background: '#f8d7da',
						border: '1px solid #f1aeb5',
						borderRadius: '4px',
						padding: '8px 12px',
						marginTop: '8px',
						color: '#842029',
						fontSize: '12px',
					},
				}, message )
			);
		}

		return el( PluginDocumentSettingPanel, {
			name: 'arcadia-pending-revision',
			title: data.i18n.title + ' (v' + data.version + ')',
			className: 'arcadia-revision-panel',
		}, children );
	}

	registerPlugin( 'arcadia-revision-sidebar', {
		render: RevisionPanel,
		icon: 'update',
	} );
} )( window.wp );
