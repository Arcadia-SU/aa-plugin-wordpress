<?php
/**
 * Pending Revision metaboxes for the post edit screen.
 *
 * Displays a banner when a pending revision exists and a sidebar
 * history of all revisions. Provides AJAX approve/reject handlers.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Revision_Metabox
 *
 * Registers metaboxes and handles AJAX actions for pending revisions.
 */
class Arcadia_Revision_Metabox {

	/**
	 * Register metaboxes on the post edit screen.
	 *
	 * Only registers for public post types (not aa_revision itself).
	 *
	 * @param string $post_type The current post type.
	 */
	public function register_metaboxes( $post_type ) {
		// Skip non-public types and our own CPT.
		if ( 'aa_revision' === $post_type ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		add_meta_box(
			'aa-revision-banner',
			__( 'Arcadia Agents — Pending Revision', 'arcadia-agents' ),
			array( $this, 'render_banner' ),
			$post_type,
			'normal',
			'high'
		);

		add_meta_box(
			'aa-revision-history',
			__( 'Arcadia Revisions', 'arcadia-agents' ),
			array( $this, 'render_history' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render the banner metabox for a pending revision.
	 *
	 * Shows a yellow banner with Preview/Approve/Reject buttons.
	 * Hidden if no pending revision exists.
	 *
	 * @param WP_Post $post The current post.
	 */
	public function render_banner( $post ) {
		$revisions_handler = Arcadia_Revisions::get_instance();
		$pending           = $revisions_handler->get_pending_revision( $post->ID );

		if ( ! $pending ) {
			echo '<p style="color: #666; margin: 0;">';
			esc_html_e( 'No pending revision.', 'arcadia-agents' );
			echo '</p>';
			// Hide the metabox container via JS.
			echo '<script>document.getElementById("aa-revision-banner").style.display="none";</script>';
			return;
		}

		$version = (int) get_post_meta( $pending->ID, '_aa_revision_version', true );
		$notes   = get_post_meta( $pending->ID, '_aa_revision_notes', true );
		$date    = get_the_date( '', $pending );

		// Build preview URL.
		$preview      = Arcadia_Preview::get_instance();
		$token        = $preview->get_or_create_token( $pending->ID );
		$preview_url  = add_query_arg(
			array(
				'p'          => $pending->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		$nonce = wp_create_nonce( 'aa_revision_action' );

		// What this revision actually proposes (Phase 43.2). Until this existed,
		// the reviewer approved on the strength of a version number and a date.
		$diff = new Arcadia_Revision_Diff();
		$rows = $diff->to_display_rows( $diff->build( $pending )['changes'] );
		?>
		<div id="aa-revision-banner-content" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 16px; margin: -6px -12px;">
			<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
				<strong style="font-size: 14px;">
					<?php
					printf(
						/* translators: 1: version number, 2: date */
						esc_html__( 'Proposed modification v%1$d — %2$s', 'arcadia-agents' ),
						$version,
						esc_html( $date )
					);
					?>
				</strong>
			</div>

			<?php if ( ! empty( $notes ) ) : ?>
				<p style="margin: 0 0 12px; color: #664d03; font-style: italic;">
					&ldquo;<?php echo esc_html( $notes ); ?>&rdquo;
				</p>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin: 0 0 12px; color: #664d03;">
					<?php esc_html_e( 'This revision proposes new page content only — no individual field is modified. Use Preview to review it.', 'arcadia-agents' ); ?>
				</p>
			<?php else : ?>
				<details style="margin: 0 0 12px;" <?php echo count( $rows ) <= 5 ? 'open' : ''; ?>>
					<summary style="cursor: pointer; font-weight: 600; color: #664d03; margin-bottom: 8px;">
						<?php
						printf(
							/* translators: %d: number of modified fields */
							esc_html( _n( '%d field modified', '%d fields modified', count( $rows ), 'arcadia-agents' ) ),
							count( $rows )
						);
						?>
					</summary>
					<table style="width: 100%; border-collapse: collapse; font-size: 12px; background: #fff; border: 1px solid #ffe08a;">
						<thead>
							<tr style="background: #fff8e1;">
								<th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ffe08a; width: 20%;">
									<?php esc_html_e( 'Field', 'arcadia-agents' ); ?>
								</th>
								<th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ffe08a; width: 40%;">
									<?php esc_html_e( 'Current', 'arcadia-agents' ); ?>
								</th>
								<th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ffe08a; width: 40%;">
									<?php esc_html_e( 'Proposed', 'arcadia-agents' ); ?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr style="vertical-align: top;">
									<td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f1;">
										<code style="font-size: 11px;"><?php echo esc_html( $row['label'] ); ?></code>
										<?php if ( '' !== $row['note'] ) : ?>
											<div style="margin-top: 4px; color: #856404; font-size: 11px; font-style: italic;">
												<?php echo esc_html( $row['note'] ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f1; color: #666;">
										<?php echo esc_html( $row['current'] ); ?>
									</td>
									<td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f1; color: #0a5c36;">
										<?php echo esc_html( $row['proposed'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>

			<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
				<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'Preview', 'arcadia-agents' ); ?>
				</a>
				<button type="button" class="button button-primary" id="aa-approve-btn">
					<?php esc_html_e( 'Approve', 'arcadia-agents' ); ?>
				</button>
				<button type="button" class="button" id="aa-reject-btn" style="color: #b32d2e; border-color: #b32d2e;">
					<?php esc_html_e( 'Reject', 'arcadia-agents' ); ?>
				</button>
				<span id="aa-revision-status" style="margin-left: 8px; display: none;"></span>
			</div>

			<div id="aa-reject-form" style="display: none; margin-top: 12px;">
				<label for="aa-reject-notes" style="display: block; margin-bottom: 4px; font-weight: 600;">
					<?php esc_html_e( 'Rejection notes (optional):', 'arcadia-agents' ); ?>
				</label>
				<textarea id="aa-reject-notes" rows="3" style="width: 100%; max-width: 500px;"></textarea>
				<div style="margin-top: 8px;">
					<button type="button" class="button" id="aa-reject-confirm" style="color: #b32d2e; border-color: #b32d2e;">
						<?php esc_html_e( 'Confirm Rejection', 'arcadia-agents' ); ?>
					</button>
					<button type="button" class="button" id="aa-reject-cancel">
						<?php esc_html_e( 'Cancel', 'arcadia-agents' ); ?>
					</button>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var revisionId = <?php echo (int) $pending->ID; ?>;
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var statusEl = document.getElementById('aa-revision-status');

			function setStatus(msg, color) {
				statusEl.style.display = 'inline';
				statusEl.style.color = color;
				statusEl.textContent = msg;
			}

			function disableButtons() {
				document.getElementById('aa-approve-btn').disabled = true;
				document.getElementById('aa-reject-btn').disabled = true;
			}

			document.getElementById('aa-approve-btn').addEventListener('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Apply this revision to the live article?', 'arcadia-agents' ) ); ?>')) return;
				disableButtons();
				setStatus('<?php echo esc_js( __( 'Approving...', 'arcadia-agents' ) ); ?>', '#664d03');

				var data = new FormData();
				data.append('action', 'aa_approve_revision');
				data.append('revision_id', revisionId);
				data.append('nonce', nonce);

				fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						if (resp.success) {
							setStatus('<?php echo esc_js( __( 'Approved! Reloading...', 'arcadia-agents' ) ); ?>', '#0a5c36');
							setTimeout(function() { location.reload(); }, 1000);
						} else {
							setStatus(resp.data || 'Error', '#b32d2e');
						}
					})
					.catch(function() { setStatus('Network error', '#b32d2e'); });
			});

			document.getElementById('aa-reject-btn').addEventListener('click', function() {
				document.getElementById('aa-reject-form').style.display = 'block';
			});

			document.getElementById('aa-reject-cancel').addEventListener('click', function() {
				document.getElementById('aa-reject-form').style.display = 'none';
			});

			document.getElementById('aa-reject-confirm').addEventListener('click', function() {
				disableButtons();
				document.getElementById('aa-reject-confirm').disabled = true;
				setStatus('<?php echo esc_js( __( 'Rejecting...', 'arcadia-agents' ) ); ?>', '#664d03');

				var notes = document.getElementById('aa-reject-notes').value;
				var data = new FormData();
				data.append('action', 'aa_reject_revision');
				data.append('revision_id', revisionId);
				data.append('decision_notes', notes);
				data.append('nonce', nonce);

				fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						if (resp.success) {
							setStatus('<?php echo esc_js( __( 'Rejected. Reloading...', 'arcadia-agents' ) ); ?>', '#0a5c36');
							setTimeout(function() { location.reload(); }, 1000);
						} else {
							setStatus(resp.data || 'Error', '#b32d2e');
						}
					})
					.catch(function() { setStatus('Network error', '#b32d2e'); });
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render the sidebar history metabox.
	 *
	 * Shows the last 20 revisions with version, date, and status badge.
	 *
	 * @param WP_Post $post The current post.
	 */
	public function render_history( $post ) {
		$revisions_handler = Arcadia_Revisions::get_instance();
		$result            = $revisions_handler->get_revisions( $post->ID, array( 'per_page' => 20 ) );

		if ( empty( $result['revisions'] ) ) {
			echo '<p style="color: #666; margin: 0;">';
			esc_html_e( 'No revisions yet.', 'arcadia-agents' );
			echo '</p>';
			return;
		}

		$status_styles = array(
			'pending'    => 'background: #fff3cd; color: #664d03;',
			'approved'   => 'background: #d1e7dd; color: #0a5c36;',
			'rejected'   => 'background: #f8d7da; color: #842029;',
			'superseded' => 'background: #e2e3e5; color: #41464b;',
		);

		echo '<ul style="margin: 0; padding: 0; list-style: none;">';
		foreach ( $result['revisions'] as $rev ) {
			$style = $status_styles[ $rev['status'] ] ?? 'background: #e2e3e5; color: #41464b;';
			$date  = wp_date( 'j M Y', strtotime( $rev['created_at'] ) );
			?>
			<li style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f1;">
				<a href="<?php echo esc_url( $rev['preview_url'] ); ?>" target="_blank" style="text-decoration: none; color: #2271b1;">
					v<?php echo (int) $rev['revision_version']; ?> &mdash; <?php echo esc_html( $date ); ?>
				</a>
				<span style="<?php echo esc_attr( $style ); ?> padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
					<?php echo esc_html( $rev['status'] ); ?>
				</span>
			</li>
			<?php
		}
		echo '</ul>';
	}

	/**
	 * AJAX handler: approve a pending revision.
	 */
	public function ajax_approve() {
		check_ajax_referer( 'aa_revision_action', 'nonce' );

		$revision_id = (int) ( $_POST['revision_id'] ?? 0 );
		if ( ! $revision_id ) {
			wp_send_json_error( __( 'Missing revision ID.', 'arcadia-agents' ) );
		}

		$authorized = $this->authorize_revision_action( $revision_id );
		if ( is_wp_error( $authorized ) ) {
			wp_send_json_error( $authorized->get_error_message() );
		}

		$user   = wp_get_current_user();
		$result = Arcadia_Revisions::get_instance()->approve_revision( $revision_id, $user->user_login );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$warnings = $result['warnings'] ?? array();
		$message  = __( 'Revision approved and applied.', 'arcadia-agents' );
		if ( ! empty( $warnings ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: semicolon-separated list of non-fatal warnings */
				__( 'Warnings: %s', 'arcadia-agents' ),
				implode( '; ', $warnings )
			);
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'warnings' => $warnings,
			)
		);
	}

	/**
	 * AJAX handler: reject a pending revision.
	 */
	public function ajax_reject() {
		check_ajax_referer( 'aa_revision_action', 'nonce' );

		$revision_id    = (int) ( $_POST['revision_id'] ?? 0 );
		$decision_notes = sanitize_textarea_field( $_POST['decision_notes'] ?? '' );

		if ( ! $revision_id ) {
			wp_send_json_error( __( 'Missing revision ID.', 'arcadia-agents' ) );
		}

		$authorized = $this->authorize_revision_action( $revision_id );
		if ( is_wp_error( $authorized ) ) {
			wp_send_json_error( $authorized->get_error_message() );
		}

		$user   = wp_get_current_user();
		$result = Arcadia_Revisions::get_instance()->reject_revision( $revision_id, $user->user_login, $decision_notes );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'message' => __( 'Revision rejected.', 'arcadia-agents' ) ) );
	}

	/**
	 * Authorize an approve/reject action on a revision.
	 *
	 * A global `edit_posts` check is too weak: a contributor could approve a
	 * revision that targets a post they may not edit. We resolve the revision's
	 * parent post and require `edit_post` on *that specific post*, so authority
	 * follows the real target, not a blanket capability.
	 *
	 * Returns the parent post ID on success so callers need not re-query it.
	 *
	 * @param int $revision_id The aa_revision post ID.
	 * @return int|WP_Error Parent post ID, or WP_Error when not found / not permitted.
	 */
	public function authorize_revision_action( $revision_id ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', __( 'Revision not found.', 'arcadia-agents' ) );
		}

		$parent_id = (int) $revision->post_parent;
		if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
			return new WP_Error( 'permission_denied', __( 'Permission denied.', 'arcadia-agents' ) );
		}

		return $parent_id;
	}

	/**
	 * Enqueue the Gutenberg sidebar panel script.
	 *
	 * Registers a PluginDocumentSettingPanel in the Post sidebar
	 * when a pending revision exists for the current post.
	 */
	public function enqueue_sidebar_script() {
		global $post;

		if ( ! $post || 'aa_revision' === $post->post_type ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		$revisions_handler = Arcadia_Revisions::get_instance();
		$pending           = $revisions_handler->get_pending_revision( $post->ID );

		if ( ! $pending ) {
			return;
		}

		$version = (int) get_post_meta( $pending->ID, '_aa_revision_version', true );
		$notes   = get_post_meta( $pending->ID, '_aa_revision_notes', true );
		$date    = get_the_date( '', $pending );

		$preview     = Arcadia_Preview::get_instance();
		$token       = $preview->get_or_create_token( $pending->ID );
		$preview_url = add_query_arg(
			array(
				'p'          => $pending->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		wp_enqueue_script(
			'aa-revision-sidebar',
			ARCADIA_AGENTS_PLUGIN_URL . 'admin/js/revision-sidebar.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-editor' ),
			ARCADIA_AGENTS_VERSION,
			true
		);

		// Same rows the classic banner renders — the two surfaces must show the
		// same thing, so they read the same list rather than each building one.
		$diff = new Arcadia_Revision_Diff();
		$rows = $diff->to_display_rows( $diff->build( $pending )['changes'] );

		wp_localize_script(
			'aa-revision-sidebar',
			'aaRevisionData',
			array(
				'has_pending'  => true,
				'revision_id'  => $pending->ID,
				'version'      => $version,
				'date'         => $date ?: '',
				'notes'        => $notes ?: '',
				'preview_url'  => $preview_url,
				'changes'      => $rows,
				'nonce'        => wp_create_nonce( 'aa_revision_action' ),
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'i18n'         => array(
					'changes_none'    => __( 'This revision proposes new page content only — no individual field is modified.', 'arcadia-agents' ),
					/* translators: %d: number of modified fields */
					'changes_count'   => __( '%d field(s) modified', 'arcadia-agents' ),
					'changes_hide'    => __( 'Hide details', 'arcadia-agents' ),
					'changes_current' => __( 'Current', 'arcadia-agents' ),
					'changes_proposed' => __( 'Proposed', 'arcadia-agents' ),
					'title'           => __( 'Pending Revision', 'arcadia-agents' ),
					'approve_confirm' => __( 'Apply this revision to the live article?', 'arcadia-agents' ),
					'approving'       => __( 'Approving...', 'arcadia-agents' ),
					'approved'        => __( 'Approved! Reloading...', 'arcadia-agents' ),
					'rejecting'       => __( 'Rejecting...', 'arcadia-agents' ),
					'rejected'        => __( 'Rejected. Reloading...', 'arcadia-agents' ),
					'preview'         => __( 'Preview', 'arcadia-agents' ),
					'approve'         => __( 'Approve', 'arcadia-agents' ),
					'reject'          => __( 'Reject', 'arcadia-agents' ),
					'reject_notes'    => __( 'Rejection notes (optional):', 'arcadia-agents' ),
					'confirm_approve' => __( 'Confirm', 'arcadia-agents' ),
					'confirm_reject'  => __( 'Confirm Rejection', 'arcadia-agents' ),
					'cancel'          => __( 'Cancel', 'arcadia-agents' ),
					'network_error'   => __( 'Network error', 'arcadia-agents' ),
				),
			)
		);
	}
}
