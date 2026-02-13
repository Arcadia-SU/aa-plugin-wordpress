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
require_once __DIR__ . '/api/trait-api-blocks.php';

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
	use Arcadia_API_Blocks_Handler;

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
	 * Block registry.
	 *
	 * @var Arcadia_Block_Registry
	 */
	private $registry;

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
		$this->auth     = Arcadia_Auth::get_instance();
		$this->blocks   = Arcadia_Blocks::get_instance();
		$this->registry = Arcadia_Block_Registry::get_instance();
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

		// Media endpoints.
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_media' ),
					'permission_callback' => array( $this, 'check_media_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_media' ),
					'permission_callback' => array( $this, 'check_media_write_permission' ),
				),
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

		// Blocks discovery endpoint.
		register_rest_route(
			$this->namespace,
			'/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocks' ),
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
	 * Check media:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_media_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'media:read' );
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
				'authors'        => $this->get_authors(),
				'post_types'     => $this->get_post_types(),
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

	/**
	 * Get authors who can publish posts.
	 *
	 * Returns users with the 'edit_posts' capability (administrators, editors, authors).
	 *
	 * @return array List of authors with email, name, and role.
	 */
	private function get_authors() {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$authors = array();
		foreach ( $users as $user ) {
			$authors[] = array(
				'email' => $user->user_email,
				'name'  => $user->display_name,
				'role'  => ! empty( $user->roles ) ? $user->roles[0] : 'none',
			);
		}

		return $authors;
	}

	/**
	 * Get public post types that support the editor.
	 *
	 * Returns post types where content can be created/edited via the API.
	 * Excludes built-in non-content types (attachment, revision, nav_menu_item, etc.).
	 *
	 * @return array List of post types with name, label, and hierarchical flag.
	 */
	private function get_post_types() {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$excluded = array( 'attachment' );
		$result   = array();

		foreach ( $types as $type ) {
			if ( in_array( $type->name, $excluded, true ) ) {
				continue;
			}

			$counts = wp_count_posts( $type->name );

			$result[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'hierarchical' => $type->hierarchical,
				'count'        => array(
					'publish' => (int) ( $counts->publish ?? 0 ),
					'draft'   => (int) ( $counts->draft ?? 0 ),
					'total'   => (int) ( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) + ( $counts->pending ?? 0 ) + ( $counts->private ?? 0 ) ),
				),
			);
		}

		return $result;
	}
}
