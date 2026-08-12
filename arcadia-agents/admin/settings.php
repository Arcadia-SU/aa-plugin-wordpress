<?php
/**
 * Admin settings page — Arcadia design system (skin scoped .arcadia-admin).
 *
 * Layout: guard + connection + technical info on the left, permissions by
 * group on the right; the connection block goes full-width while the site is
 * not connected yet. All POST handling is unchanged from the previous
 * version; the dialog and toggletips are UX only — security stays
 * check_admin_referer() + manage_options server-side.
 *
 * @package ArcadiaAgents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the settings page.
 */
function arcadia_agents_settings_page() {
	// Check user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Get saved options.
	$connection_key = get_option( 'arcadia_agents_connection_key', '' );
	$is_connected   = get_option( 'arcadia_agents_connected', false );
	$connected_at   = get_option( 'arcadia_agents_connected_at', '' );
	$last_activity  = get_option( 'arcadia_agents_last_activity', '' );

	// Scopes come from Arcadia_Auth, which is also what enforces them at request
	// time. Two copies of this list is how a scope ends up enforced by the API and
	// ungrantable in the UI.
	//
	// Note the consequence for upgrades: `arcadia_agents_scopes` is only used as a
	// default when the option has never been saved, so a scope added in a new
	// version arrives DISABLED on any site that has visited this page. That is the
	// right default for a new capability — it is granted deliberately, never by
	// installing an update.
	$all_scopes = Arcadia_Auth::all_scopes();

	$notice      = '';
	$notice_type = '';

	// Handle form submission.
	if ( isset( $_POST['arcadia_agents_save'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		// Save connection key — only when the field was actually posted, so a
		// form that omits it can never silently wipe the stored key.
		if ( isset( $_POST['arcadia_agents_connection_key'] ) ) {
			$new_connection_key = sanitize_text_field( wp_unslash( $_POST['arcadia_agents_connection_key'] ) );
			update_option( 'arcadia_agents_connection_key', $new_connection_key, false );
			$connection_key = $new_connection_key;
		}

		// Save scopes.
		$selected_scopes = isset( $_POST['arcadia_agents_scopes'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['arcadia_agents_scopes'] ) ) : array();
		// Validate scopes.
		$selected_scopes = array_intersect( $selected_scopes, $all_scopes );
		update_option( 'arcadia_agents_scopes', $selected_scopes, false );

		// Save Force Draft setting.
		$force_draft = ! empty( $_POST['aa_force_draft'] );
		update_option( 'aa_force_draft', $force_draft, false );

		$notice      = __( 'Settings saved.', 'arcadia-agents' );
		$notice_type = 'success';
	}

	// Handle handshake request.
	if ( isset( $_POST['arcadia_agents_handshake'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		// Connect uses the key typed in the same form submit, falling back to
		// the stored one — so "paste key, click Connect" works in one step.
		if ( isset( $_POST['arcadia_agents_connection_key'] ) ) {
			$posted_key = sanitize_text_field( wp_unslash( $_POST['arcadia_agents_connection_key'] ) );
			if ( '' !== $posted_key ) {
				update_option( 'arcadia_agents_connection_key', $posted_key, false );
				$connection_key = $posted_key;
			}
		}
		$connection_key = get_option( 'arcadia_agents_connection_key', '' );

		if ( empty( $connection_key ) ) {
			$notice      = __( 'Please enter a Connection Key first.', 'arcadia-agents' );
			$notice_type = 'error';
		} else {
			$auth   = Arcadia_Auth::get_instance();
			$result = $auth->handshake( $connection_key );

			if ( is_wp_error( $result ) ) {
				$notice      = $result->get_error_message();
				$notice_type = 'error';
			} else {
				$is_connected = true;
				$connected_at = get_option( 'arcadia_agents_connected_at', '' );
				$notice       = __( 'Successfully connected to Arcadia Agents!', 'arcadia-agents' );
				$notice_type  = 'success';
			}
		}
	}

	// Handle disconnect.
	if ( isset( $_POST['arcadia_agents_disconnect'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		$auth = Arcadia_Auth::get_instance();
		$auth->disconnect();
		$is_connected = false;
		$connected_at = '';
		$notice       = __( 'Disconnected from Arcadia Agents.', 'arcadia-agents' );
		$notice_type  = 'info';
	}

	// Get current scopes.
	$enabled_scopes = get_option( 'arcadia_agents_scopes', $all_scopes );

	// Labels, descriptions and groups — same source as the list above, so a
	// scope can never be enforced without being displayable, or displayed
	// without being enforceable.
	$scope_labels       = Arcadia_Auth::scope_labels();
	$scope_descriptions = Arcadia_Auth::scope_descriptions();
	$scope_groups       = Arcadia_Auth::scope_groups();

	$force_draft = get_option( 'aa_force_draft', false );

	?>
	<div class="wrap">
		<div class="aa-page-header">
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="" class="aa-page-header__logo" />
			<h1 class="aa-page-header__title"><?php esc_html_e( 'Arcadia Agents — Settings', 'arcadia-agents' ); ?></h1>
		</div>
		<hr class="wp-header-end" />

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<div class="arcadia-admin">
			<form method="post" action="" id="aa-settings-form">
				<?php wp_nonce_field( 'arcadia_agents_settings' ); ?>

				<?php if ( ! $is_connected ) : ?>
					<!-- Not connected: connection front and center, full width. -->
					<section class="aa-card aa-card--connect">
						<h2 class="aa-card__title"><?php esc_html_e( 'Connection', 'arcadia-agents' ); ?></h2>
						<p class="aa-status aa-status--off">
							<span class="aa-status__dot" aria-hidden="true"></span>
							<?php esc_html_e( 'Not connected', 'arcadia-agents' ); ?>
						</p>
						<p class="aa-muted"><?php esc_html_e( 'Enter the Connection Key from your Arcadia Agents dashboard, then click "Connect".', 'arcadia-agents' ); ?></p>
						<div class="aa-field-row">
							<label class="aa-label" for="arcadia_agents_connection_key"><?php esc_html_e( 'Connection Key', 'arcadia-agents' ); ?></label>
							<input type="password"
								id="arcadia_agents_connection_key"
								name="arcadia_agents_connection_key"
								value="<?php echo esc_attr( $connection_key ); ?>"
								class="aa-input"
								placeholder="aa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
								autocomplete="off"
							/>
						</div>
						<div class="aa-actions">
							<button type="submit" name="arcadia_agents_handshake" value="1" class="aa-btn aa-btn--primary">
								<?php esc_html_e( 'Connect to Arcadia Agents', 'arcadia-agents' ); ?>
							</button>
						</div>
					</section>
				<?php endif; ?>

				<div class="aa-grid">
					<div class="aa-col">
						<!-- FS-4 — the guard, first thing on the page. -->
						<section class="aa-card aa-card--guard">
							<h2 class="aa-card__title"><?php esc_html_e( 'Publishing', 'arcadia-agents' ); ?></h2>

							<label class="aa-toggle" for="aa-guard-toggle">
								<input type="checkbox"
									id="aa-guard-toggle"
									name="aa_force_draft"
									value="1"
									class="aa-toggle__input"
									<?php checked( $force_draft ); ?>
								/>
								<span class="aa-toggle__track" aria-hidden="true"></span>
								<span class="aa-toggle__text"><?php esc_html_e( 'The agent saves as draft only.', 'arcadia-agents' ); ?></span>
							</label>

							<p class="aa-guard-hint aa-muted">
								<?php esc_html_e( 'When enabled, the agent can never change your live site on its own. New articles are saved as drafts, and edits to already-published articles are held as proposals you approve from the article editor — your live article is never taken offline. When disabled, the agent publishes new articles and applies edits to live articles directly, with no review step.', 'arcadia-agents' ); ?>
							</p>
						</section>

						<?php if ( $is_connected ) : ?>
							<!-- Connected: compact connection card. -->
							<section class="aa-card aa-card--connection">
								<h2 class="aa-card__title"><?php esc_html_e( 'Connection', 'arcadia-agents' ); ?></h2>
								<p class="aa-status aa-status--on">
									<span class="aa-status__dot" aria-hidden="true"></span>
									<?php esc_html_e( 'Connected', 'arcadia-agents' ); ?>
								</p>
								<?php if ( $connected_at ) : ?>
									<p class="aa-muted aa-small">
										<?php
										/* translators: %s: date/time of connection */
										echo esc_html( sprintf( __( 'Connected since: %s', 'arcadia-agents' ), $connected_at ) );
										?>
									</p>
								<?php endif; ?>
								<?php if ( $last_activity ) : ?>
									<p class="aa-muted aa-small">
										<?php
										/* translators: %s: date/time of last agent activity */
										echo esc_html( sprintf( __( 'Last activity: %s', 'arcadia-agents' ), $last_activity ) );
										?>
									</p>
								<?php endif; ?>
								<div class="aa-field-row">
									<label class="aa-label" for="arcadia_agents_connection_key"><?php esc_html_e( 'Connection Key', 'arcadia-agents' ); ?></label>
									<input type="password"
										id="arcadia_agents_connection_key"
										name="arcadia_agents_connection_key"
										value="<?php echo esc_attr( $connection_key ); ?>"
										class="aa-input"
										autocomplete="off"
										readonly
									/>
									<p class="aa-muted aa-small"><?php esc_html_e( 'Connected. To change the key, disconnect first.', 'arcadia-agents' ); ?></p>
								</div>
								<div class="aa-actions">
									<button type="submit" name="arcadia_agents_disconnect" value="1" class="aa-btn aa-btn--ghost">
										<?php esc_html_e( 'Disconnect', 'arcadia-agents' ); ?>
									</button>
								</div>
							</section>
						<?php endif; ?>

						<!-- Technical info, folded by default. Native details: zero JS. -->
						<details class="aa-details">
							<summary class="aa-details__summary"><?php esc_html_e( 'Technical information', 'arcadia-agents' ); ?></summary>
							<div class="aa-details__body">
								<table class="aa-debug-table">
									<tbody>
										<tr>
											<td><?php esc_html_e( 'Plugin Version', 'arcadia-agents' ); ?></td>
											<td><code><?php echo esc_html( ARCADIA_AGENTS_VERSION ); ?></code></td>
										</tr>
										<tr>
											<td><?php esc_html_e( 'WordPress Version', 'arcadia-agents' ); ?></td>
											<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
										</tr>
										<tr>
											<td><?php esc_html_e( 'PHP Version', 'arcadia-agents' ); ?></td>
											<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
										</tr>
										<tr>
											<td><?php esc_html_e( 'Block Adapter', 'arcadia-agents' ); ?></td>
											<td>
												<code><?php echo esc_html( Arcadia_Blocks::get_instance()->get_adapter_name() ); ?></code>
												<?php if ( Arcadia_Blocks::is_acf_available() ) : ?>
													<span class="aa-good">(<?php esc_html_e( 'ACF detected', 'arcadia-agents' ); ?>)</span>
												<?php endif; ?>
											</td>
										</tr>
										<tr>
											<td><?php esc_html_e( 'REST API Base', 'arcadia-agents' ); ?></td>
											<td><code><?php echo esc_url( rest_url( 'arcadia/v1/' ) ); ?></code></td>
										</tr>
									</tbody>
								</table>

								<p>
									<button type="button" class="aa-btn aa-btn--ghost" id="arcadia-test-connection">
										<?php esc_html_e( 'Test Health Endpoint', 'arcadia-agents' ); ?>
									</button>
									<span id="arcadia-test-result" class="aa-test-result" role="status"></span>
								</p>
								<p class="aa-muted aa-small">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: health check URL */
											__( 'Health check endpoint: %s', 'arcadia-agents' ),
											'<code>' . esc_url( rest_url( 'arcadia/v1/health' ) ) . '</code>'
										),
										array( 'code' => array() )
									);
									?>
								</p>
							</div>
						</details>
					</div>

					<div class="aa-col">
						<!-- Permissions, grouped, one toggletip per scope. -->
						<section class="aa-card aa-card--permissions">
							<h2 class="aa-card__title"><?php esc_html_e( 'Permissions', 'arcadia-agents' ); ?></h2>
							<p class="aa-muted"><?php esc_html_e( 'Control what the agent can do on your site.', 'arcadia-agents' ); ?></p>

							<?php foreach ( $scope_groups as $group_key => $group ) : ?>
								<fieldset class="aa-perm-group">
									<legend class="aa-perm-group__legend"><?php echo esc_html( $group['label'] ); ?></legend>
									<?php foreach ( $group['scopes'] as $scope ) : ?>
										<?php
										$checked = in_array( $scope, $enabled_scopes, true );
										$tip_id  = 'aa-tip-' . sanitize_html_class( str_replace( ':', '-', $scope ) );
										?>
										<div class="aa-perm">
											<label class="aa-perm__label">
												<input type="checkbox"
													name="arcadia_agents_scopes[]"
													value="<?php echo esc_attr( $scope ); ?>"
													<?php checked( $checked ); ?>
												/>
												<span><?php echo esc_html( isset( $scope_labels[ $scope ] ) ? $scope_labels[ $scope ] : $scope ); ?></span>
											</label>
											<span class="aa-help">
												<button type="button"
													class="aa-help__btn"
													aria-expanded="false"
													aria-describedby="<?php echo esc_attr( $tip_id ); ?>"
													aria-label="<?php echo esc_attr( sprintf( /* translators: %s: permission name */ __( 'What does "%s" allow?', 'arcadia-agents' ), isset( $scope_labels[ $scope ] ) ? $scope_labels[ $scope ] : $scope ) ); ?>"
												>?</button>
												<span role="tooltip" id="<?php echo esc_attr( $tip_id ); ?>" class="aa-tooltip">
													<?php echo esc_html( isset( $scope_descriptions[ $scope ] ) ? $scope_descriptions[ $scope ] : '' ); ?>
												</span>
											</span>
										</div>
									<?php endforeach; ?>
								</fieldset>
							<?php endforeach; ?>
						</section>
					</div>
				</div>

				<div class="aa-actions aa-actions--footer">
					<button type="submit" name="arcadia_agents_save" value="1" class="aa-btn aa-btn--primary" id="aa-save-settings">
						<?php esc_html_e( 'Save Settings', 'arcadia-agents' ); ?>
					</button>
				</div>
			</form>

			<!-- FS-4 — confirmation dialog, opened by settings.js when the guard is switched off. -->
			<dialog class="aa-dialog" id="aa-guard-dialog" aria-labelledby="aa-guard-dialog-title">
				<h2 id="aa-guard-dialog-title" class="aa-dialog__title"><?php esc_html_e( 'Allow direct publishing?', 'arcadia-agents' ); ?></h2>
				<p class="aa-dialog__body">
					<?php esc_html_e( 'The agent will be able to publish new content and edit your live pages immediately, without a review step.', 'arcadia-agents' ); ?>
				</p>
				<div class="aa-dialog__actions">
					<button type="button" class="aa-btn aa-btn--ghost" id="aa-guard-cancel">
						<?php esc_html_e( 'Cancel', 'arcadia-agents' ); ?>
					</button>
					<button type="button" class="aa-btn aa-btn--warn" id="aa-guard-confirm">
						<?php esc_html_e( 'Allow direct publishing', 'arcadia-agents' ); ?>
					</button>
				</div>
			</dialog>
		</div>
	</div>
	<?php
}
