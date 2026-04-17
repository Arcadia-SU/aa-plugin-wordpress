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
require_once __DIR__ . '/api/trait-api-acf-fields.php';
require_once __DIR__ . '/api/trait-api-site.php';
require_once __DIR__ . '/api/trait-api-redirects.php';
require_once __DIR__ . '/api/trait-api-preview.php';
require_once __DIR__ . '/api/trait-api-field-schema.php';
require_once __DIR__ . '/api/trait-api-revisions.php';

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
	use Arcadia_API_ACF_Fields_Handler;
	use Arcadia_API_Site_Handler;
	use Arcadia_API_Redirects_Handler;
	use Arcadia_API_Preview_Handler;
	use Arcadia_API_Field_Schema_Handler;
	use Arcadia_API_Revisions_Handler;

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
		// Articles endpoints.
		register_rest_route(
			$this->namespace,
			'/articles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_posts' ),
					'permission_callback' => array( $this, 'check_articles_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => array( $this, 'check_articles_write_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'check_articles_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'check_articles_delete_permission' ),
				),
			)
		);

		// Article blocks structure endpoint.
		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_blocks' ),
				'permission_callback' => array( $this, 'check_articles_read_permission' ),
			)
		);

		// Preview URL endpoint.
		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/preview-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_preview_url' ),
				'permission_callback' => array( $this, 'check_articles_read_permission' ),
			)
		);

		// Featured image endpoint.
		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/featured-image',
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
				'permission_callback' => array( $this, 'check_articles_write_permission' ),
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

		register_rest_route(
			$this->namespace,
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_media' ),
					'permission_callback' => array( $this, 'check_media_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_media' ),
					'permission_callback' => array( $this, 'check_media_delete_permission' ),
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
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_tags' ),
					'permission_callback' => array( $this, 'check_taxonomies_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_tag' ),
					'permission_callback' => array( $this, 'check_taxonomies_write_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'check_taxonomies_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'check_taxonomies_delete_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tags/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_tag' ),
					'permission_callback' => array( $this, 'check_taxonomies_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_tag' ),
					'permission_callback' => array( $this, 'check_taxonomies_delete_permission' ),
				),
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

		// Menus endpoint.
		register_rest_route(
			$this->namespace,
			'/menus',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_menus' ),
				'permission_callback' => array( $this, 'check_site_read_permission' ),
			)
		);

		// Users endpoint.
		register_rest_route(
			$this->namespace,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_users_list' ),
				'permission_callback' => array( $this, 'check_site_read_permission' ),
			)
		);

		// Redirects endpoints.
		register_rest_route(
			$this->namespace,
			'/redirects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_redirects' ),
					'permission_callback' => array( $this, 'check_redirects_read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_redirect' ),
					'permission_callback' => array( $this, 'check_redirects_write_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/redirects/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_redirect' ),
				'permission_callback' => array( $this, 'check_redirects_write_permission' ),
			)
		);

		// Field schema endpoints (FS-2, FS-3).
		register_rest_route(
			$this->namespace,
			'/field-schema',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_field_schema' ),
					'permission_callback' => array( $this, 'check_site_read_permission' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_field_schema' ),
					'permission_callback' => array( $this, 'check_settings_write_permission' ),
				),
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

		// Blocks usage analysis endpoint.
		register_rest_route(
			$this->namespace,
			'/blocks/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocks_usage' ),
				'permission_callback' => array( $this, 'check_site_read_permission' ),
			)
		);

		// Content dry-run validation endpoint.
		register_rest_route(
			$this->namespace,
			'/validate-content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_content' ),
				'permission_callback' => array( $this, 'check_articles_read_permission' ),
			)
		);

		// Article revisions endpoints.
		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/revisions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_revisions' ),
				'permission_callback' => array( $this, 'check_articles_read_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/revisions/(?P<revision_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_revision' ),
				'permission_callback' => array( $this, 'check_articles_read_permission' ),
			)
		);
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

	/**
	 * Check articles:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_articles_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'articles:read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check articles:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_articles_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'articles:write' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check articles:delete permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_articles_delete_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'articles:delete' );
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
	 * Check media:delete permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_media_delete_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'media:delete' );
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
	 * Check taxonomies:delete permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_taxonomies_delete_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'taxonomies:delete' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check redirects:read permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_redirects_read_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'redirects:read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check redirects:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_redirects_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'redirects:write' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Check settings:write permission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function check_settings_write_permission( $request ) {
		$result = $this->auth->authenticate_request( $request, 'settings:write' );
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
					'name'   => $theme->get( 'Name' ),
					'author' => $theme->get( 'Author' ),
				),
				'plugin'         => array(
					'version' => ARCADIA_AGENTS_VERSION,
					'adapter' => $this->blocks->get_adapter_name(),
				),
				'settings'         => array(
					'force_draft'        => (bool) get_option( 'aa_force_draft', false ),
					'pending_revisions'  => (bool) get_option( 'aa_pending_revisions', false ),
					'enabled_scopes'     => $this->auth->get_enabled_scopes(),
				),
				'acf_available'    => Arcadia_Blocks::is_acf_available(),
				'acf_field_groups' => $this->get_acf_field_groups_for_post_types(),
				'permalink'        => get_option( 'permalink_structure' ),
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
