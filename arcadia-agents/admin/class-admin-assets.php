<?php
/**
 * Per-screen admin asset loading.
 *
 * Three tiers, from broadest to narrowest:
 * - edit.php for posts/pages → arcadia-list.css (badge + guard chip);
 * - our two plugin pages → arcadia-admin.css (full Arcadia skin) + the
 *   page's script with its localized payload.
 *
 * The plugin pages are matched by the hook suffixes RETURNED by
 * add_menu_page()/add_submenu_page(), never hardcoded: the pending-revisions
 * bubble is concatenated into the menu title, and sanitize_title() keeps its
 * digits — the dashboard hook becomes "toplevel_page_arcadia-agents" or
 * "arcadia-agents-3_page_…" depending on state. Hardcoded suffixes break
 * exactly when a revision is pending.
 *
 * @package ArcadiaAgents
 * @since   0.6.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Admin_Assets
 */
class Arcadia_Admin_Assets {

	/**
	 * Hook suffixes of our plugin pages, keyed by page ('dashboard'|'settings').
	 *
	 * @var array<string, string>
	 */
	private static $page_hooks = array();

	/**
	 * Register the enqueue hook. Called from the plugin entry point (admin only).
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Record the hook suffixes returned by add_menu_page()/add_submenu_page().
	 *
	 * @param string $page        'dashboard' or 'settings'.
	 * @param string $hook_suffix The value returned by the registration call.
	 */
	public static function set_page_hook( $page, $hook_suffix ) {
		if ( is_string( $hook_suffix ) && '' !== $hook_suffix ) {
			self::$page_hooks[ $page ] = $hook_suffix;
		}
	}

	/**
	 * Enqueue the right assets for the current screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue( $hook_suffix ) {
		// Native list screens: badge + chip styles only.
		if ( 'edit.php' === $hook_suffix ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
				wp_enqueue_style(
					'arcadia-list',
					ARCADIA_AGENTS_PLUGIN_URL . 'admin/css/arcadia-list.css',
					array(),
					ARCADIA_AGENTS_VERSION
				);
			}
			return;
		}

		$page = array_search( $hook_suffix, self::$page_hooks, true );
		if ( false === $page ) {
			return;
		}

		// Full Arcadia skin, scoped .arcadia-admin — our two pages only.
		wp_enqueue_style(
			'arcadia-admin',
			ARCADIA_AGENTS_PLUGIN_URL . 'admin/css/arcadia-admin.css',
			array(),
			ARCADIA_AGENTS_VERSION
		);

		if ( 'settings' === $page ) {
			wp_enqueue_script(
				'arcadia-settings',
				ARCADIA_AGENTS_PLUGIN_URL . 'admin/js/settings.js',
				array(),
				ARCADIA_AGENTS_VERSION,
				true
			);
			wp_localize_script( 'arcadia-settings', 'aaSettingsData', self::settings_payload() );
		}

		if ( 'dashboard' === $page ) {
			wp_enqueue_script(
				'arcadia-dashboard',
				ARCADIA_AGENTS_PLUGIN_URL . 'admin/js/dashboard.js',
				array(),
				ARCADIA_AGENTS_VERSION,
				true
			);
			wp_localize_script( 'arcadia-dashboard', 'aaDashboardData', self::dashboard_payload() );
		}
	}

	/**
	 * Payload for settings.js. Never includes connection_key or public_key.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_payload() {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'arcadia/v1/health' ) ),
			'i18n'    => array(
				'testing'       => __( 'Testing…', 'arcadia-agents' ),
				'error_prefix'  => __( 'Error:', 'arcadia-agents' ),
				'error_generic' => __( 'Request failed.', 'arcadia-agents' ),
				/* translators: %s: plugin version reported by the health endpoint */
				'health_ok'     => __( 'Connection OK — plugin version %s.', 'arcadia-agents' ),
			),
		);
	}

	/**
	 * Payload for dashboard.js. Never includes connection_key or public_key.
	 *
	 * @return array<string, mixed>
	 */
	private static function dashboard_payload() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aa_revision_action' ),
			'i18n'    => array(
				'confirm_approve' => __( 'Approve this proposal?', 'arcadia-agents' ),
				'reject_prompt'   => __( 'Reason for rejection (optional):', 'arcadia-agents' ),
				'error_generic'   => __( 'Request failed.', 'arcadia-agents' ),
				'approve'         => __( 'Approve', 'arcadia-agents' ),
				'reject'          => __( 'Reject', 'arcadia-agents' ),
				'cancel'          => __( 'Cancel', 'arcadia-agents' ),
				'working'         => __( 'Working…', 'arcadia-agents' ),
			),
		);
	}
}
