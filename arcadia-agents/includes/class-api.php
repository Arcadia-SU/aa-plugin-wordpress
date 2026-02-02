<?php
/**
 * REST API endpoints.
 *
 * @package ArcadiaAgents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_API
 *
 * Registers and handles all REST API endpoints.
 */
class Arcadia_API {

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
	// Posts endpoints
	// =========================================================================

	/**
	 * Get posts.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_posts( $request ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $request->get_param( 'per_page' ) ?? 20,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => $request->get_param( 'orderby' ) ?? 'date',
			'order'          => $request->get_param( 'order' ) ?? 'DESC',
		);

		// Filter by status.
		$status = $request->get_param( 'status' );
		if ( $status ) {
			$args['post_status'] = $status;
		}

		// Filter by category.
		$category = $request->get_param( 'category' );
		if ( $category ) {
			$args['cat'] = $category;
		}

		// Search.
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post( $post );
		}

		return new WP_REST_Response(
			array(
				'posts'       => $posts,
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Create a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( $request ) {
		$body = $request->get_json_params();

		// Extract meta.
		$meta = isset( $body['meta'] ) ? $body['meta'] : array();

		// Build post data.
		$post_data = array(
			'post_type'   => 'post',
			'post_status' => isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'draft',
		);

		// Title from meta or direct.
		if ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
		} elseif ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		}

		// Slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Excerpt / meta description.
		if ( ! empty( $meta['description'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
		}

		// Content: convert JSON structure to blocks if present.
		// Support both top-level structure and nested in 'content' key.
		$content_data = $body;
		if ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			// Content is nested in 'content' key (API wrapper format).
			$content_data = $body['content'];

			// Also extract meta from nested content if not already at top level.
			if ( empty( $meta ) && ! empty( $content_data['meta'] ) ) {
				$meta = $content_data['meta'];

				// Apply meta values that weren't set yet.
				if ( empty( $post_data['post_title'] ) && ! empty( $meta['title'] ) ) {
					$post_data['post_title'] = sanitize_text_field( $meta['title'] );
				}
				if ( empty( $post_data['post_name'] ) && ! empty( $meta['slug'] ) ) {
					$post_data['post_name'] = sanitize_title( $meta['slug'] );
				}
				if ( empty( $post_data['post_excerpt'] ) && ! empty( $meta['description'] ) ) {
					$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
				}
			}
		}

		// Check for structured content (h1 + sections/children).
		if ( ! empty( $content_data['h1'] ) || ! empty( $content_data['sections'] ) || ! empty( $content_data['children'] ) ) {
			$post_data['post_content'] = $this->blocks->json_to_blocks( $content_data );
		} elseif ( ! empty( $body['content'] ) && is_string( $body['content'] ) ) {
			// Direct content (HTML or plain text).
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		// Create the post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set categories.
		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$category_ids = $this->get_or_create_terms( $meta['categories'], 'category' );
			wp_set_post_categories( $post_id, $category_ids );
		}

		// Set tags.
		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			wp_set_post_tags( $post_id, $meta['tags'] );
		}

		// Handle featured image from URL.
		if ( ! empty( $meta['featured_image_url'] ) ) {
			$this->sideload_and_set_featured_image( $post_id, $meta['featured_image_url'] );
		}

		// Store SEO meta if Yoast or similar is available.
		if ( ! empty( $meta['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $meta['title'] ) );
		}
		if ( ! empty( $meta['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $meta['description'] ) );
		}

		$post = get_post( $post_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'post'    => $this->format_post( $post ),
			),
			201
		);
	}

	/**
	 * Update a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$body = $request->get_json_params();
		$meta = isset( $body['meta'] ) ? $body['meta'] : array();

		$post_data = array( 'ID' => $post_id );

		// Update title.
		if ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
		} elseif ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		}

		// Update slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Update excerpt.
		if ( ! empty( $meta['description'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
		}

		// Update status.
		if ( ! empty( $body['status'] ) ) {
			$post_data['post_status'] = sanitize_text_field( $body['status'] );
		}

		// Update content.
		if ( ! empty( $body['h1'] ) || ! empty( $body['sections'] ) ) {
			$post_data['post_content'] = $this->blocks->json_to_blocks( $body );
		} elseif ( isset( $body['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update categories.
		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$category_ids = $this->get_or_create_terms( $meta['categories'], 'category' );
			wp_set_post_categories( $post_id, $category_ids );
		}

		// Update tags.
		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			wp_set_post_tags( $post_id, $meta['tags'] );
		}

		// Update featured image.
		if ( ! empty( $meta['featured_image_url'] ) ) {
			$this->sideload_and_set_featured_image( $post_id, $meta['featured_image_url'] );
		}

		// Update SEO meta.
		if ( ! empty( $meta['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $meta['title'] ) );
		}
		if ( ! empty( $meta['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $meta['description'] ) );
		}

		$post = get_post( $post_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'post'    => $this->format_post( $post ),
			),
			200
		);
	}

	/**
	 * Delete a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_post( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		// Move to trash by default, force delete if specified.
		$force = $request->get_param( 'force' ) === true;

		$result = wp_delete_post( $post_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete post.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'deleted' => $post_id,
				'trashed' => ! $force,
			),
			200
		);
	}

	// =========================================================================
	// Pages endpoints
	// =========================================================================

	/**
	 * Get pages.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pages( $request ) {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $request->get_param( 'per_page' ) ?? 50,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );
		$pages = array();

		foreach ( $query->posts as $page ) {
			$pages[] = $this->format_page( $page );
		}

		return new WP_REST_Response(
			array(
				'pages'       => $pages,
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Update a page.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( $request ) {
		$page_id = (int) $request->get_param( 'id' );
		$page    = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$body = $request->get_json_params();
		$meta = isset( $body['meta'] ) ? $body['meta'] : array();

		$post_data = array( 'ID' => $page_id );

		// Update title.
		if ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
		} elseif ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		}

		// Update slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Update status.
		if ( ! empty( $body['status'] ) ) {
			$post_data['post_status'] = sanitize_text_field( $body['status'] );
		}

		// Update content.
		if ( ! empty( $body['h1'] ) || ! empty( $body['sections'] ) ) {
			$post_data['post_content'] = $this->blocks->json_to_blocks( $body );
		} elseif ( isset( $body['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update SEO meta.
		if ( ! empty( $meta['title'] ) ) {
			update_post_meta( $page_id, '_yoast_wpseo_title', sanitize_text_field( $meta['title'] ) );
		}
		if ( ! empty( $meta['description'] ) ) {
			update_post_meta( $page_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $meta['description'] ) );
		}

		$page = get_post( $page_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'page'    => $this->format_page( $page ),
			),
			200
		);
	}

	// =========================================================================
	// Media endpoints
	// =========================================================================

	/**
	 * Upload media from URL (sideload).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['url'] ) ) {
			return new WP_Error(
				'missing_url',
				__( 'Image URL is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$url = esc_url_raw( $body['url'] );
		$alt = isset( $body['alt'] ) ? sanitize_text_field( $body['alt'] ) : '';
		$title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
		$caption = isset( $body['caption'] ) ? sanitize_textarea_field( $body['caption'] ) : '';

		// Sideload the image.
		$attachment_id = $this->sideload_image( $url, $title );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Update attachment meta.
		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( $caption ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}

		$attachment = get_post( $attachment_id );

		return new WP_REST_Response(
			array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'title'         => $attachment->post_title,
				'alt'           => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			),
			201
		);
	}

	/**
	 * Set featured image for a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_featured_image( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$body = $request->get_json_params();

		// Accept either attachment_id or url.
		if ( ! empty( $body['attachment_id'] ) ) {
			$attachment_id = (int) $body['attachment_id'];

			// Verify attachment exists.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Invalid attachment ID or not an image.', 'arcadia-agents' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( ! empty( $body['url'] ) ) {
			$attachment_id = $this->sideload_image( esc_url_raw( $body['url'] ) );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		} else {
			return new WP_Error(
				'missing_image',
				__( 'Either attachment_id or url is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$result = set_post_thumbnail( $post_id, $attachment_id );

		if ( ! $result ) {
			return new WP_Error(
				'set_thumbnail_failed',
				__( 'Failed to set featured image.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
			),
			200
		);
	}

	// =========================================================================
	// Taxonomies endpoints
	// =========================================================================

	/**
	 * Get categories.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( $request ) {
		$args = array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$parent = $request->get_param( 'parent' );
		if ( null !== $parent ) {
			$args['parent'] = (int) $parent;
		}

		$terms      = get_terms( $args );
		$categories = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = $this->format_term( $term );
			}
		}

		return new WP_REST_Response(
			array(
				'categories' => $categories,
				'total'      => count( $categories ),
			),
			200
		);
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Category name is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$args = array(
			'slug' => isset( $body['slug'] ) ? sanitize_title( $body['slug'] ) : '',
		);

		if ( ! empty( $body['parent'] ) ) {
			$args['parent'] = (int) $body['parent'];
		}

		if ( ! empty( $body['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $body['description'] );
		}

		$result = wp_insert_term(
			sanitize_text_field( $body['name'] ),
			'category',
			$args
		);

		if ( is_wp_error( $result ) ) {
			// If term already exists, return it.
			if ( 'term_exists' === $result->get_error_code() ) {
				$term = get_term( $result->get_error_data(), 'category' );
				return new WP_REST_Response(
					array(
						'success'     => true,
						'category_id' => $term->term_id,
						'category'    => $this->format_term( $term ),
						'existing'    => true,
					),
					200
				);
			}
			return $result;
		}

		$term = get_term( $result['term_id'], 'category' );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'category_id' => $result['term_id'],
				'category'    => $this->format_term( $term ),
			),
			201
		);
	}

	/**
	 * Get tags.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tags( $request ) {
		$args = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$terms = get_terms( $args );
		$tags  = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$tags[] = $this->format_term( $term );
			}
		}

		return new WP_REST_Response(
			array(
				'tags'  => $tags,
				'total' => count( $tags ),
			),
			200
		);
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
				'name'            => get_bloginfo( 'name' ),
				'description'     => get_bloginfo( 'description' ),
				'url'             => get_site_url(),
				'home'            => get_home_url(),
				'admin_email'     => get_option( 'admin_email' ),
				'language'        => get_locale(),
				'timezone'        => wp_timezone_string(),
				'date_format'     => get_option( 'date_format' ),
				'time_format'     => get_option( 'time_format' ),
				'posts_per_page'  => (int) get_option( 'posts_per_page' ),
				'theme'           => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'author'  => $theme->get( 'Author' ),
				),
				'wordpress'       => array(
					'version' => get_bloginfo( 'version' ),
				),
				'plugin'          => array(
					'version' => ARCADIA_AGENTS_VERSION,
					'adapter' => $this->blocks->get_adapter_name(),
				),
				'acf_available'   => Arcadia_Blocks::is_acf_available(),
				'permalink'       => get_option( 'permalink_structure' ),
			),
			200
		);
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Format a post for API response.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted post data.
	 */
	private function format_post( $post ) {
		$featured_image_id  = get_post_thumbnail_id( $post->ID );
		$featured_image_url = $featured_image_id ? wp_get_attachment_url( $featured_image_id ) : null;

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'url'                => get_permalink( $post->ID ),
			'excerpt'            => $post->post_excerpt,
			'content'            => $post->post_content,
			'author'             => (int) $post->post_author,
			'date'               => $post->post_date,
			'date_gmt'           => $post->post_date_gmt,
			'modified'           => $post->post_modified,
			'modified_gmt'       => $post->post_modified_gmt,
			'featured_image_id'  => $featured_image_id ? (int) $featured_image_id : null,
			'featured_image_url' => $featured_image_url,
			'categories'         => wp_get_post_categories( $post->ID ),
			'tags'               => wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) ),
		);
	}

	/**
	 * Format a page for API response.
	 *
	 * @param WP_Post $page The page object.
	 * @return array Formatted page data.
	 */
	private function format_page( $page ) {
		return array(
			'id'           => $page->ID,
			'title'        => $page->post_title,
			'slug'         => $page->post_name,
			'status'       => $page->post_status,
			'url'          => get_permalink( $page->ID ),
			'parent'       => $page->post_parent,
			'menu_order'   => $page->menu_order,
			'template'     => get_page_template_slug( $page->ID ),
			'date'         => $page->post_date,
			'modified'     => $page->post_modified,
		);
	}

	/**
	 * Format a term for API response.
	 *
	 * @param WP_Term $term The term object.
	 * @return array Formatted term data.
	 */
	private function format_term( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);
	}

	/**
	 * Sideload an image from URL.
	 *
	 * @param string $url   The image URL.
	 * @param string $title Optional title for the attachment.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_image( $url, $title = '' ) {
		// Require media functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Download the file.
		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download image: %s', 'arcadia-agents' ),
					$tmp->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		// Get filename from URL.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $url_path );

		// Prepare file array.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Sideload the file.
		$attachment_id = media_handle_sideload( $file_array, 0, $title );

		// Clean up temp file if sideload failed.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new WP_Error(
				'sideload_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to import image: %s', 'arcadia-agents' ),
					$attachment_id->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		return $attachment_id;
	}

	/**
	 * Sideload image and set as featured image.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $url     The image URL.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_and_set_featured_image( $post_id, $url ) {
		$attachment_id = $this->sideload_image( $url );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Get or create terms by name.
	 *
	 * @param array  $names    Array of term names.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Array of term IDs.
	 */
	private function get_or_create_terms( $names, $taxonomy ) {
		$term_ids = array();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			$term = get_term_by( 'name', $name, $taxonomy );

			if ( $term ) {
				$term_ids[] = $term->term_id;
			} else {
				$result = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $result ) ) {
					$term_ids[] = $result['term_id'];
				}
			}
		}

		return $term_ids;
	}
}
