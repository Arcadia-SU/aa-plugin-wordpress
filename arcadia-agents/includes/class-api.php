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
	 * Register all REST routes — orchestrator only.
	 *
	 * Route definitions are split per domain to keep the file navigable.
	 */
	public function register_routes() {
		$this->register_article_routes();
		$this->register_page_routes();
		$this->register_media_routes();
		$this->register_taxonomy_routes();
		$this->register_site_routes();
		$this->register_redirect_routes();
		$this->register_field_schema_routes();
		$this->register_block_routes();
		$this->register_validation_routes();
		$this->register_revision_routes();
	}

	// =========================================================================
	// Permission gate
	// =========================================================================

	/**
	 * Authenticate a request against a required scope.
	 *
	 * Single permission gate used by every route via a closure callback.
	 * Returns true on success or the underlying WP_Error from auth.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param string          $scope   Required scope, e.g. 'articles:read'.
	 * @return bool|WP_Error
	 */
	private function check_permission( $request, $scope ) {
		$result = $this->auth->authenticate_request( $request, $scope );
		return is_wp_error( $result ) ? $result : true;
	}

	// =========================================================================
	// Route groups
	// =========================================================================

	/**
	 * Article routes: /articles, /articles/{id}, /articles/{id}/blocks,
	 * /articles/{id}/preview-url, /articles/{id}/featured-image.
	 */
	private function register_article_routes() {
		register_rest_route(
			$this->namespace,
			'/articles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_posts' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:write' ),
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
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:delete' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_blocks' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/preview-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_preview_url' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/featured-image',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_featured_image' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'media:write' ),
			)
		);
	}

	/**
	 * Page routes: /pages, /pages/{id}.
	 */
	private function register_page_routes() {
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pages' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_page' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:write' ),
			)
		);
	}

	/**
	 * Media routes: /media, /media/{id}.
	 */
	private function register_media_routes() {
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_media' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'media:read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_media' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'media:write' ),
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
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'media:write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_media' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'media:delete' ),
				),
			)
		);
	}

	/**
	 * Taxonomy routes: /categories, /tags + their {id} variants.
	 */
	private function register_taxonomy_routes() {
		register_rest_route(
			$this->namespace,
			'/categories',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:write' ),
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
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_tag' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:write' ),
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
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:delete' ),
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
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_tag' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'taxonomies:delete' ),
				),
			)
		);
	}

	/**
	 * Site routes: /site-info, /menus, /users.
	 */
	private function register_site_routes() {
		register_rest_route(
			$this->namespace,
			'/site-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_site_info' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/menus',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_menus' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_users_list' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);
	}

	/**
	 * Redirect routes: /redirects, /redirects/{id}.
	 */
	private function register_redirect_routes() {
		register_rest_route(
			$this->namespace,
			'/redirects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_redirects' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'redirects:read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_redirect' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'redirects:write' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/redirects/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_redirect' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'redirects:write' ),
			)
		);
	}

	/**
	 * Field schema routes: /field-schema (GET/PUT).
	 */
	private function register_field_schema_routes() {
		register_rest_route(
			$this->namespace,
			'/field-schema',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_field_schema' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_field_schema' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'settings:write' ),
				),
			)
		);
	}

	/**
	 * Block routes: /blocks, /blocks/usage.
	 */
	private function register_block_routes() {
		register_rest_route(
			$this->namespace,
			'/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocks' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/blocks/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocks_usage' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'site:read' ),
			)
		);
	}

	/**
	 * Validation routes: /validate-content.
	 */
	private function register_validation_routes() {
		register_rest_route(
			$this->namespace,
			'/validate-content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_content' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
			)
		);
	}

	/**
	 * Revision routes: /articles/{id}/revisions, /articles/{id}/revisions/{revision_id}.
	 */
	private function register_revision_routes() {
		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/revisions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_revisions' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/articles/(?P<id>\d+)/revisions/(?P<revision_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_article_revision' ),
				'permission_callback' => fn( $request ) => $this->check_permission( $request, 'articles:read' ),
			)
		);
	}

}
