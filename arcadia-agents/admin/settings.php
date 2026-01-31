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
	$last_activity  = get_option( 'arcadia_agents_last_activity', '' );

	// Handle form submission.
	if ( isset( $_POST['arcadia_agents_save'] ) && check_admin_referer( 'arcadia_agents_settings' ) ) {
		$connection_key = sanitize_text_field( wp_unslash( $_POST['arcadia_agents_connection_key'] ?? '' ) );
		update_option( 'arcadia_agents_connection_key', $connection_key );

		// TODO: Trigger handshake with ArcadiaAgents to validate key and get public key.
		// For now, just save the key.
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<!-- Connection Status -->
		<div class="arcadia-status" style="margin: 20px 0; padding: 15px; background: #fff; border-left: 4px solid <?php echo $is_connected ? '#00a32a' : '#d63638'; ?>;">
			<strong><?php esc_html_e( 'Connection Status:', 'arcadia-agents' ); ?></strong>
			<?php if ( $is_connected ) : ?>
				<span style="color: #00a32a;">● <?php esc_html_e( 'Connected', 'arcadia-agents' ); ?></span>
				<?php if ( $last_activity ) : ?>
					<br><small><?php echo esc_html( sprintf( __( 'Last activity: %s', 'arcadia-agents' ), $last_activity ) ); ?></small>
				<?php endif; ?>
			<?php else : ?>
				<span style="color: #d63638;">● <?php esc_html_e( 'Not connected', 'arcadia-agents' ); ?></span>
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
						/>
						<p class="description">
							<?php esc_html_e( 'Enter the Connection Key from your Arcadia Agents dashboard.', 'arcadia-agents' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Permissions', 'arcadia-agents' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Control what Arcadia Agents can do on your site.', 'arcadia-agents' ); ?></p>

			<table class="form-table">
				<?php
				$scopes = array(
					'posts:read'       => __( 'Read posts', 'arcadia-agents' ),
					'posts:write'      => __( 'Create/edit posts', 'arcadia-agents' ),
					'posts:delete'     => __( 'Delete posts', 'arcadia-agents' ),
					'media:read'       => __( 'Read media library', 'arcadia-agents' ),
					'media:write'      => __( 'Upload media', 'arcadia-agents' ),
					'taxonomies:read'  => __( 'Read categories/tags', 'arcadia-agents' ),
					'taxonomies:write' => __( 'Create categories/tags', 'arcadia-agents' ),
					'site:read'        => __( 'Read site info & pages', 'arcadia-agents' ),
				);

				$enabled_scopes = get_option( 'arcadia_agents_scopes', array_keys( $scopes ) );

				foreach ( $scopes as $scope => $label ) :
					$checked = in_array( $scope, $enabled_scopes, true );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="arcadia_agents_scopes[]"
									value="<?php echo esc_attr( $scope ); ?>"
									<?php checked( $checked ); ?>
								/>
								<code><?php echo esc_html( $scope ); ?></code>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button( __( 'Save Settings', 'arcadia-agents' ), 'primary', 'arcadia_agents_save' ); ?>
		</form>

		<!-- Test Connection Button -->
		<hr>
		<h2><?php esc_html_e( 'Test Connection', 'arcadia-agents' ); ?></h2>
		<p>
			<button type="button" class="button" id="arcadia-test-connection">
				<?php esc_html_e( 'Test Connection', 'arcadia-agents' ); ?>
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

		<script>
		document.getElementById('arcadia-test-connection').addEventListener('click', function() {
			var resultEl = document.getElementById('arcadia-test-result');
			resultEl.textContent = '<?php esc_html_e( 'Testing...', 'arcadia-agents' ); ?>';

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
