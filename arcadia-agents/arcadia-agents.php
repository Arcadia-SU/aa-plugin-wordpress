<?php
/**
 * Plugin Name: Arcadia Agents
 * Plugin URI: https://arcadia-agents.com
 * Description: Connect your WordPress site to Arcadia Agents for autonomous SEO content management.
 * Version: 0.1.8
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Arcadia
 * Author URI: https://arcadia-agents.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: arcadia-agents
 *
 * @package ArcadiaAgents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'ARCADIA_AGENTS_VERSION', '0.1.8' );
define( 'ARCADIA_AGENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCADIA_AGENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Check for Composer dependency conflicts before loading autoloader.
if ( class_exists( 'Firebase\JWT\JWT' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Arcadia Agents: Firebase JWT library is already loaded by another plugin. This may cause version conflicts. If you experience authentication errors, check for conflicting plugins.', 'arcadia-agents' );
			echo '</p></div>';
		}
	);
}

// Load Composer autoloader.
$autoloader = ARCADIA_AGENTS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
} else {
	// Show admin notice if Composer dependencies are missing.
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Arcadia Agents: Composer dependencies are missing. Please run "composer install" in the plugin directory.', 'arcadia-agents' );
			echo '</p></div>';
		}
	);
	return; // Stop loading plugin without dependencies.
}

/**
 * Main plugin class.
 */
class Arcadia_Agents {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Agents|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Agents
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required files.
	 */
	private function load_dependencies() {
		// Core classes.
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-auth.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-blocks.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-block-registry.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-acf-validator.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-seo-meta.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-redirects.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-preview.php';
		require_once ARCADIA_AGENTS_PLUGIN_DIR . 'includes/class-api.php';

		// Admin.
		if ( is_admin() ) {
			require_once ARCADIA_AGENTS_PLUGIN_DIR . 'admin/settings.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Register custom taxonomy for source tracking.
		add_action( 'init', array( $this, 'register_arcadia_source_taxonomy' ) );

		// Register redirects CPT and handler.
		add_action( 'init', array( $this, 'register_redirect_post_type' ) );
		add_action( 'template_redirect', array( $this, 'handle_arcadia_redirects' ) );

		// Preview URL: fix main query for CPT drafts, then render on template_redirect.
		add_action( 'pre_get_posts', array( $this, 'fix_arcadia_preview_query' ) );
		add_action( 'template_redirect', array( $this, 'handle_arcadia_preview' ), 1 );
		add_action( 'arcadia_preview_cleanup', array( $this, 'run_preview_cleanup' ) );
		add_action( 'init', array( 'Arcadia_Preview', 'schedule_cleanup' ) );

		// Admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Invalidate blocks usage cache when posts are saved.
		add_action( 'save_post', array( $this, 'invalidate_blocks_usage_cache' ) );
	}

	/**
	 * Register the hidden arcadia_source taxonomy.
	 *
	 * Used to track which posts were created by Arcadia Agents
	 * vs. manually in WordPress.
	 */
	public function register_arcadia_source_taxonomy() {
		register_taxonomy(
			'arcadia_source',
			$this->get_source_taxonomy_post_types(),
			array(
				'label'             => 'Arcadia Source',
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'show_admin_column' => false,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Get post types for the arcadia_source taxonomy.
	 *
	 * Returns all public post types that the plugin can manage.
	 *
	 * @return array Post type names.
	 */
	private function get_source_taxonomy_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Register the arcadia_redirect Custom Post Type.
	 */
	public function register_redirect_post_type() {
		Arcadia_Redirects::get_instance()->register_post_type();
	}

	/**
	 * Handle arcadia redirects on template_redirect hook.
	 */
	public function handle_arcadia_redirects() {
		Arcadia_Redirects::get_instance()->handle_redirect();
	}

	/**
	 * Fix the main query for preview requests (pre_get_posts callback).
	 *
	 * @param \WP_Query $query The main WP_Query instance.
	 */
	public function fix_arcadia_preview_query( $query ) {
		Arcadia_Preview::get_instance()->fix_query_for_preview( $query );
	}

	/**
	 * Handle arcadia preview requests on template_redirect hook.
	 */
	public function handle_arcadia_preview() {
		Arcadia_Preview::get_instance()->handle_preview();
	}

	/**
	 * Run preview token cleanup (cron callback).
	 */
	public function run_preview_cleanup() {
		Arcadia_Preview::get_instance()->cleanup_expired_tokens();
	}

	/**
	 * Invalidate blocks usage transient cache when a post is saved.
	 *
	 * @param int $post_id The post ID.
	 */
	public function invalidate_blocks_usage_cache( $post_id ) {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_arcadia_blocks_usage%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_timeout_arcadia_blocks_usage%'
			)
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		// Health check endpoint (no auth required for testing).
		register_rest_route(
			'arcadia/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health_check' ),
				'permission_callback' => '__return_true',
			)
		);

		// Register all authenticated endpoints.
		$api = Arcadia_API::get_instance();
		$api->register_routes();
	}

	/**
	 * Health check endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public function health_check() {
		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'version' => ARCADIA_AGENTS_VERSION,
			),
			200
		);
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Arcadia Agents', 'arcadia-agents' ),
			__( 'Arcadia Agents', 'arcadia-agents' ),
			'manage_options',
			'arcadia-agents',
			'arcadia_agents_settings_page'
		);
	}
}

// Initialize plugin.
Arcadia_Agents::get_instance();
