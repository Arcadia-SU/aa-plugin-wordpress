<?php
/**
 * Plugin Name: Arcadia Agents
 * Plugin URI: https://arcadiaagents.com
 * Description: Connect your WordPress site to Arcadia Agents for autonomous SEO content management.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Arcadia Agents
 * Author URI: https://arcadiaagents.com
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
define( 'ARCADIA_AGENTS_VERSION', '0.1.0' );
define( 'ARCADIA_AGENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCADIA_AGENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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

		// Admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
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
				'php'     => PHP_VERSION,
				'wp'      => get_bloginfo( 'version' ),
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
