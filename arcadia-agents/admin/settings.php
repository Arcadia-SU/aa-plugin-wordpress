<?php
/**
 * Admin settings page.
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

	// Default scopes (all enabled).
	$all_scopes = array(
		'posts:read',
		'posts:write',
		'posts:delete',
		'media:read',
		'media:write',
		'taxonomies:read',
		'taxonomies:write',
		'site:read',
	);

	$notice        = '';
	$notice_type   = '';

	// Handle form submission.
	if ( isset( $_POST['arcadia_agents_save'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		// Save connection key.
		$new_connection_key = sanitize_text_field( wp_unslash( $_POST['arcadia_agents_connection_key'] ?? '' ) );
		update_option( 'arcadia_agents_connection_key', $new_connection_key );
		$connection_key = $new_connection_key;

		// Save scopes.
		$selected_scopes = isset( $_POST['arcadia_agents_scopes'] ) ? array_map( 'sanitize_text_field', $_POST['arcadia_agents_scopes'] ) : array();
		// Validate scopes.
		$selected_scopes = array_intersect( $selected_scopes, $all_scopes );
		update_option( 'arcadia_agents_scopes', $selected_scopes );

		$notice      = __( 'Settings saved.', 'arcadia-agents' );
		$notice_type = 'success';
	}

	// Handle handshake request.
	if ( isset( $_POST['arcadia_agents_handshake'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
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

	// Handle manual setup (dev/testing).
	if ( isset( $_POST['arcadia_agents_manual_setup'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		$public_key = sanitize_textarea_field( wp_unslash( $_POST['arcadia_agents_public_key'] ?? '' ) );

		if ( empty( $public_key ) || false === strpos( $public_key, '-----BEGIN PUBLIC KEY-----' ) ) {
			$notice      = __( 'Invalid public key. Must be a PEM-encoded RSA public key.', 'arcadia-agents' );
			$notice_type = 'error';
		} else {
			update_option( 'arcadia_agents_public_key', $public_key );
			update_option( 'arcadia_agents_connected', true );
			update_option( 'arcadia_agents_connected_at', current_time( 'mysql' ) );
			$is_connected = true;
			$connected_at = current_time( 'mysql' );
			$notice       = __( 'Manual setup complete. Public key saved.', 'arcadia-agents' );
			$notice_type  = 'success';
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

	// Scope labels.
	$scope_labels = array(
		'posts:read'       => __( 'Read posts', 'arcadia-agents' ),
		'posts:write'      => __( 'Create/edit posts', 'arcadia-agents' ),
		'posts:delete'     => __( 'Delete posts', 'arcadia-agents' ),
		'media:read'       => __( 'Read media library', 'arcadia-agents' ),
		'media:write'      => __( 'Upload media', 'arcadia-agents' ),
		'taxonomies:read'  => __( 'Read categories/tags', 'arcadia-agents' ),
		'taxonomies:write' => __( 'Create categories/tags', 'arcadia-agents' ),
		'site:read'        => __( 'Read site info & pages', 'arcadia-agents' ),
	);

	?>
	<div class="wrap">
		<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo.jpg' ); ?>" alt="Arcadia Agents" style="width: 36px; height: 36px; border-radius: 6px;" />
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo-text-black.png' ); ?>" alt="Arcadia" style="height: 24px; width: auto;" />
		</div>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Connection Status -->
		<div class="arcadia-status" style="margin: 20px 0; padding: 15px; background: #fff; border-left: 4px solid <?php echo $is_connected ? '#00a32a' : '#d63638'; ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<strong><?php esc_html_e( 'Connection Status:', 'arcadia-agents' ); ?></strong>
			<?php if ( $is_connected ) : ?>
				<span style="color: #00a32a;">● <?php esc_html_e( 'Connected', 'arcadia-agents' ); ?></span>
				<?php if ( $connected_at ) : ?>
					<br><small><?php echo esc_html( sprintf( __( 'Connected since: %s', 'arcadia-agents' ), $connected_at ) ); ?></small>
				<?php endif; ?>
				<?php if ( $last_activity ) : ?>
					<br><small><?php echo esc_html( sprintf( __( 'Last activity: %s', 'arcadia-agents' ), $last_activity ) ); ?></small>
				<?php endif; ?>
			<?php else : ?>
				<span style="color: #d63638;">● <?php esc_html_e( 'Not connected', 'arcadia-agents' ); ?></span>
				<br><small><?php esc_html_e( 'Enter your Connection Key and click "Connect" to get started.', 'arcadia-agents' ); ?></small>
			<?php endif; ?>
		</div>

		<form method="post" action="">
			<?php wp_nonce_field( 'arcadia_agents_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="arcadia_agents_connection_key"><?php esc_html_e( 'Connection Key', 'arcadia-agents' ); ?></label>
					</th>
					<td>
						<input type="text"
							id="arcadia_agents_connection_key"
							name="arcadia_agents_connection_key"
							value="<?php echo esc_attr( $connection_key ); ?>"
							class="regular-text"
							placeholder="aa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
							<?php echo $is_connected ? 'readonly' : ''; ?>
						/>
						<?php if ( $is_connected ) : ?>
							<p class="description" style="color: #00a32a;">
								<?php esc_html_e( 'Connected. To change the key, disconnect first.', 'arcadia-agents' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'Enter the Connection Key from your Arcadia Agents dashboard.', 'arcadia-agents' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! $is_connected ) : ?>
				<p>
					<?php submit_button( __( 'Connect to Arcadia Agents', 'arcadia-agents' ), 'primary', 'arcadia_agents_handshake', false ); ?>
					<?php submit_button( __( 'Save Settings', 'arcadia-agents' ), 'secondary', 'arcadia_agents_save', false, array( 'style' => 'margin-left: 10px;' ) ); ?>
				</p>

				<hr style="margin: 30px 0;">

				<h2><?php esc_html_e( 'Manual Setup (Development)', 'arcadia-agents' ); ?></h2>
				<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'For testing: paste an RSA public key directly to bypass the handshake.', 'arcadia-agents' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="arcadia_agents_public_key"><?php esc_html_e( 'RSA Public Key (PEM)', 'arcadia-agents' ); ?></label>
						</th>
						<td>
							<textarea
								id="arcadia_agents_public_key"
								name="arcadia_agents_public_key"
								rows="8"
								class="large-text code"
								placeholder="-----BEGIN PUBLIC KEY-----&#10;...&#10;-----END PUBLIC KEY-----"
							></textarea>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Save Public Key', 'arcadia-agents' ), 'secondary', 'arcadia_agents_manual_setup', false ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php submit_button( __( 'Disconnect', 'arcadia-agents' ), 'secondary', 'arcadia_agents_disconnect', false ); ?>
				</p>
			<?php endif; ?>

			<hr style="margin: 30px 0;">

			<h2><?php esc_html_e( 'Permissions', 'arcadia-agents' ); ?></h2>
			<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'Control what Arcadia Agents can do on your site.', 'arcadia-agents' ); ?></p>

			<div class="arcadia-permissions" style="background: #fff; padding: 15px 20px; border: 1px solid #c3c4c7; max-width: 500px;">
				<?php foreach ( $scope_labels as $scope => $label ) : ?>
					<?php $checked = in_array( $scope, $enabled_scopes, true ); ?>
					<label style="display: flex; align-items: center; padding: 6px 0; gap: 10px; cursor: pointer;">
						<input type="checkbox"
							name="arcadia_agents_scopes[]"
							value="<?php echo esc_attr( $scope ); ?>"
							<?php checked( $checked ); ?>
						/>
						<span style="min-width: 140px;"><?php echo esc_html( $label ); ?></span>
						<code style="font-size: 12px; color: #666;"><?php echo esc_html( $scope ); ?></code>
					</label>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Save Permissions', 'arcadia-agents' ), 'primary', 'arcadia_agents_save' ); ?>
		</form>

		<hr style="margin: 30px 0;">

		<!-- Test Connection Button -->
		<h2><?php esc_html_e( 'Test Connection', 'arcadia-agents' ); ?></h2>
		<p>
			<button type="button" class="button" id="arcadia-test-connection">
				<?php esc_html_e( 'Test Health Endpoint', 'arcadia-agents' ); ?>
			</button>
			<span id="arcadia-test-result" style="margin-left: 10px;"></span>
		</p>
		<p class="description">
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

		<hr style="margin: 30px 0;">

		<!-- Debug Info -->
		<h2><?php esc_html_e( 'Debug Information', 'arcadia-agents' ); ?></h2>
		<table class="widefat" style="max-width: 600px;">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Plugin Version', 'arcadia-agents' ); ?></strong></td>
					<td><code><?php echo esc_html( ARCADIA_AGENTS_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'WordPress Version', 'arcadia-agents' ); ?></strong></td>
					<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'PHP Version', 'arcadia-agents' ); ?></strong></td>
					<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Block Adapter', 'arcadia-agents' ); ?></strong></td>
					<td>
						<code><?php echo esc_html( Arcadia_Blocks::get_instance()->get_adapter_name() ); ?></code>
						<?php if ( Arcadia_Blocks::is_acf_available() ) : ?>
							<span style="color: #00a32a;">(<?php esc_html_e( 'ACF detected', 'arcadia-agents' ); ?>)</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'REST API Base', 'arcadia-agents' ); ?></strong></td>
					<td><code><?php echo esc_url( rest_url( 'arcadia/v1/' ) ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<script>
		document.getElementById('arcadia-test-connection').addEventListener('click', function() {
			var resultEl = document.getElementById('arcadia-test-result');
			resultEl.textContent = '<?php esc_html_e( 'Testing...', 'arcadia-agents' ); ?>';
			resultEl.style.color = '#666';

			fetch('<?php echo esc_url( rest_url( 'arcadia/v1/health' ) ); ?>')
				.then(response => response.json())
				.then(data => {
					resultEl.innerHTML = '<span style="color: #00a32a;">✓ ' + JSON.stringify(data) + '</span>';
				})
				.catch(error => {
					resultEl.innerHTML = '<span style="color: #d63638;">✗ Error: ' + error.message + '</span>';
				});
		});
		</script>
	</div>
	<?php
}
