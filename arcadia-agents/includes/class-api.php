<?php
/**
 * REST API router and permission handlers.
 *
 * Main API class that registers routes and delegates to trait handlers.
 * Each domain (posts, media, taxonomies) is handled by a separate trait
 * to keep files small and focused.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load trait handlers.
require_once __DIR__ . '/api/trait-api-formatters.php';
require_once __DIR__ . '/api/trait-api-posts.php';
require_once __DIR__ . '/api/trait-api-media.php';
require_once __DIR__ . '/api/trait-api-taxonomies.php';

/**
 * Class Arcadia_API
 *
 * Registers and handles all REST API endpoints.
 * Uses traits for domain-specific handlers to keep code organized.
 */
class Arcadia_API {

	use Arcadia_API_Formatters;
	use Arcadia_API_Posts_Handler;
	use Arcadia_API_Media_Handler;
	use Arcadia_API_Taxonomies_Handler;

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_API|null
	 */
	private static $instance = null;

	/**
	 * Auth handler.
	 *
	 * @var Arcadia_Auth
	 */
	private $auth;

	/**
	 * Blocks handler.
	 *
	 * @var Arcadia_Blocks
	 */
	private $blocks;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'arcadia/v1';

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_API
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
		$this->auth   = Arcadia_Auth::get_instance();
		$this->blocks = Arcadia_Blocks::get_instance();
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		// Posts endpoints.
		register_rest_route(
			$this->namespace,
			'/posts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_posts' ),
					'permission_callback' => array( $this, 'check_posts_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => array( $this, 'check_posts_write_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'check_posts_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'check_posts_delete_permission' ),
				),
			)
		);

		// Featured image endpoint.
		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)/featured-image',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_featured_image' ),
				'permission_callback' => array( $this, 'check_media_write_permission' ),
			)
		);

		// Pages endpoints.
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pages' ),
				'permission_callback' => array( $this, 'check_site_read_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_page' ),
				'permission_callback' => array( $this, 'check_posts_write_permission' ),
			)
		);

		// Media endpoint.
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_media' ),
				'permission_callback' => array( $this, 'check_media_write_permission' ),
			)
		);

		// Taxonomies endpoints.
		register_rest_route(
			$this->namespace,
			'/categories',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( $this, 'check_taxonomies_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'check_taxonomies_write_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tags',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tags' ),
				'permission_callback' => array( $this, 'check_taxonomies_read_permission' ),
			)
		);

		// Site info endpoint.
		register_rest_route(
			$this->namespace,
			'/site-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_site_info' ),
				'permission_callback' => array( $this, 'check_site_read_permission' ),
			)
		);
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

	/**
	 * Check posts:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_posts_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'posts:read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check posts:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_posts_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'posts:write' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check posts:delete permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_posts_delete_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'posts:delete' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check media:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_media_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'media:write' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check taxonomies:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_taxonomies_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'taxonomies:read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check taxonomies:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_taxonomies_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'taxonomies:write' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check site:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_site_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'site:read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	// =========================================================================
	// Site info endpoint
	// =========================================================================

	/**
	 * Get site information.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_site_info( $request ) {
		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'name'           => get_bloginfo( 'name' ),
				'description'    => get_bloginfo( 'description' ),
				'url'            => get_site_url(),
				'home'           => get_home_url(),
				'admin_email'    => get_option( 'admin_email' ),
				'language'       => get_locale(),
				'timezone'       => wp_timezone_string(),
				'date_format'    => get_option( 'date_format' ),
				'time_format'    => get_option( 'time_format' ),
				'posts_per_page' => (int) get_option( 'posts_per_page' ),
				'theme'          => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'author'  => $theme->get( 'Author' ),
				),
				'wordpress'      => array(
					'version' => get_bloginfo( 'version' ),
				),
				'plugin'         => array(
					'version' => ARCADIA_AGENTS_VERSION,
					'adapter' => $this->blocks->get_adapter_name(),
				),
				'acf_available'  => Arcadia_Blocks::is_acf_available(),
				'permalink'      => get_option( 'permalink_structure' ),
			),
			200
		);
	}
}
