<?php
/**
 * Per-screen admin asset loading.
 *
 * Three tiers, from broadest to narrowest:
 * - edit.php for supported post types → arcadia-list.css (badge);
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
		// Native list screens: badge styles only. Same post-type rule as the
		// view/filter (all public admin-visible types) — the badge renders on
		// any list screen holding agent content, CPTs included.
		if ( 'edit.php' === $hook_suffix ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && Arcadia_Admin_List_UI::is_supported_post_type( $screen->post_type ) ) {
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

		// Full Arcadia skin, scoped .arcadia-admin — our plugin pages only.
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

		if ( 'dashboard' === $page || 'pending' === $page ) {
			wp_enqueue_script(
				'arcadia-dashboard',
				ARCADIA_AGENTS_PLUGIN_URL . 'admin/js/dashboard.js',
				array(),
				ARCADIA_AGENTS_VERSION,
				true
			);
			wp_localize_script( 'arcadia-dashboard', 'aaDashboardData', 'pending' === $page ? self::pending_payload() : self::dashboard_payload() );
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
	 * Payload for dashboard.js on the pending-actions page (Phase 55).
	 *
	 * Same script and nonce as the revision queue; its own AJAX actions, id
	 * field and wording ("change" applied to the site, not "proposal" to an
	 * article).
	 *
	 * @return array<string, mixed>
	 */
	private static function pending_payload() {
		$payload                  = self::dashboard_payload();
		$payload['approveAction'] = 'aa_approve_pending_action';
		$payload['rejectAction']  = 'aa_reject_pending_action';
		$payload['idField']       = 'action_id';
		$payload['i18n']          = array_merge(
			$payload['i18n'],
			array(
				'confirm_approve'           => __( 'Apply this change to your site?', 'arcadia-agents' ),
				/* translators: %s: number of selected changes (always 1 here) */
				'selected_one'              => __( '%s change selected', 'arcadia-agents' ),
				/* translators: %s: number of selected changes */
				'selected_many'             => __( '%s changes selected', 'arcadia-agents' ),
				'select_all'                => __( 'Select all changes', 'arcadia-agents' ),
				'bulk_confirm_approve_one'  => __( 'Apply this change to your site?', 'arcadia-agents' ),
				/* translators: %s: number of selected changes */
				'bulk_confirm_approve_many' => __( 'Apply %s changes to your site?', 'arcadia-agents' ),
				'bulk_confirm_reject_one'   => __( 'Reject this change?', 'arcadia-agents' ),
				/* translators: %s: number of selected changes */
				'bulk_confirm_reject_many'  => __( 'Reject %s changes?', 'arcadia-agents' ),
				'bulk_reject_prompt'        => __( 'Reason (optional)', 'arcadia-agents' ),
				/* translators: %s: number of changes applied (always 1 here) */
				'bulk_done_approve_one'     => __( '%s change applied', 'arcadia-agents' ),
				/* translators: %s: number of changes applied */
				'bulk_done_approve_many'    => __( '%s changes applied', 'arcadia-agents' ),
				/* translators: %s: number of changes rejected (always 1 here) */
				'bulk_done_reject_one'      => __( '%s change rejected', 'arcadia-agents' ),
				/* translators: %s: number of changes rejected */
				'bulk_done_reject_many'     => __( '%s changes rejected', 'arcadia-agents' ),
			)
		);
		return $payload;
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
				'confirm_approve'      => __( 'Approve this proposal?', 'arcadia-agents' ),
				'reject_prompt'        => __( 'Reason for rejection (optional):', 'arcadia-agents' ),
				'error_generic'        => __( 'Request failed.', 'arcadia-agents' ),
				'approve'              => __( 'Approve', 'arcadia-agents' ),
				'reject'               => __( 'Reject', 'arcadia-agents' ),
				'cancel'               => __( 'Cancel', 'arcadia-agents' ),
				'dismiss'              => __( 'Dismiss', 'arcadia-agents' ),
				'working'              => __( 'Working…', 'arcadia-agents' ),
				/* translators: %s: number of selected proposals (always 1 here) */
				'selected_one'         => __( '%s proposal selected', 'arcadia-agents' ),
				/* translators: %s: number of selected proposals */
				'selected_many'        => __( '%s proposals selected', 'arcadia-agents' ),
				'select_all'           => __( 'Select all proposals', 'arcadia-agents' ),
				'clear_selection'      => __( 'Clear selection', 'arcadia-agents' ),
				'bulk_confirm_approve_one'  => __( 'Apply this proposal to the live article?', 'arcadia-agents' ),
				/* translators: %s: number of selected proposals */
				'bulk_confirm_approve_many' => __( 'Apply %s proposals to the live articles?', 'arcadia-agents' ),
				'bulk_confirm_reject_one'   => __( 'Reject this proposal?', 'arcadia-agents' ),
				/* translators: %s: number of selected proposals */
				'bulk_confirm_reject_many'  => __( 'Reject %s proposals?', 'arcadia-agents' ),
				'bulk_reject_prompt'   => __( 'Reason, applied to every selected proposal (optional):', 'arcadia-agents' ),
				/* translators: 1: current item number, 2: total items */
				'bulk_progress'        => __( 'Processing %1$s of %2$s…', 'arcadia-agents' ),
				/* translators: %s: number of proposals applied (always 1 here) */
				'bulk_done_approve_one'  => __( '%s proposal applied', 'arcadia-agents' ),
				/* translators: %s: number of proposals applied */
				'bulk_done_approve_many' => __( '%s proposals applied', 'arcadia-agents' ),
				/* translators: %s: number of proposals rejected (always 1 here) */
				'bulk_done_reject_one'   => __( '%s proposal rejected', 'arcadia-agents' ),
				/* translators: %s: number of proposals rejected */
				'bulk_done_reject_many'  => __( '%s proposals rejected', 'arcadia-agents' ),
				/* translators: %s: number that failed; the text is a link to the first failed row */
				'bulk_failed'            => __( '%s failed', 'arcadia-agents' ),
				/* translators: %s: number of proposals no longer valid (always 1 here) */
				'bulk_stale_one'         => __( '%s is no longer valid', 'arcadia-agents' ),
				/* translators: %s: number of proposals no longer valid */
				'bulk_stale_many'        => __( '%s are no longer valid', 'arcadia-agents' ),
				'stale_row'              => __( 'No longer valid: the item changed on your site. Removed from the list.', 'arcadia-agents' ),
			),
		);
	}
}
