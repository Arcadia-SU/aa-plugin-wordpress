<?php
/**
 * Posts and pages API handlers.
 *
 * Handles CRUD operations for posts and pages via REST API.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Posts_Handler
 *
 * Provides methods for handling posts and pages endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Posts_Handler {

	/**
	 * Get posts.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_posts( $request ) {
		$post_type = $request->get_param( 'post_type' ) ?? 'post';

		$args = array(
			'post_type'      => sanitize_text_field( $post_type ),
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

		// Resolve author: meta.author (email or login) with first-admin fallback.
		$post_author = $this->resolve_author( $meta );

		// Resolve post type: meta.post_type with 'post' fallback.
		$post_type = ! empty( $meta['post_type'] ) ? sanitize_text_field( $meta['post_type'] ) : 'post';

		// Build post data.
		$post_data = array(
			'post_type'   => $post_type,
			'post_status' => isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'draft',
			'post_author' => $post_author,
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
			$content = $this->blocks->json_to_blocks( $content_data );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$post_data['post_content'] = $content;
		} elseif ( ! empty( $body['content'] ) && is_string( $body['content'] ) ) {
			// Direct content (HTML or plain text).
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		// Set current user so wp_insert_post() grants unfiltered_html capability.
		// Without this, wp_filter_post_kses encodes block comments containing HTML.
		wp_set_current_user( $post_author );

		// Slash data for wp_insert_post() which internally calls wp_unslash().
		// Without this, backslashes in JSON block data (e.g. escaped quotes in
		// href attributes) are stripped, breaking the block JSON structure.
		$post_data = wp_slash( $post_data );

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

		if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
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
		if ( ! empty( $body['h1'] ) || ! empty( $body['sections'] ) || ! empty( $body['children'] ) ) {
			$content = $this->blocks->json_to_blocks( $body );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$post_data['post_content'] = $content;
		} elseif ( isset( $body['content'] ) && is_string( $body['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		// Set current user so wp_update_post() grants unfiltered_html capability.
		$post = get_post( $post_id );
		if ( $post ) {
			wp_set_current_user( $post->post_author );
		}

		// Slash data for wp_update_post() which internally calls wp_unslash().
		$post_data = wp_slash( $post_data );

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

		if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
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
		if ( ! empty( $body['h1'] ) || ! empty( $body['sections'] ) || ! empty( $body['children'] ) ) {
			$content = $this->blocks->json_to_blocks( $body );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$post_data['post_content'] = $content;
		} elseif ( isset( $body['content'] ) && is_string( $body['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $body['content'] );
		}

		// Set current user so wp_update_post() grants unfiltered_html capability.
		$page = get_post( $page_id );
		if ( $page ) {
			wp_set_current_user( $page->post_author );
		}

		// Slash data for wp_update_post() which internally calls wp_unslash().
		$post_data = wp_slash( $post_data );

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

	/**
	 * Resolve the post author from meta.author (email or login).
	 *
	 * Falls back to the first administrator on the site if meta.author
	 * is absent or doesn't match a valid WordPress user.
	 *
	 * @param array $meta The post meta from the request.
	 * @return int User ID.
	 */
	private function resolve_author( $meta ) {
		if ( ! empty( $meta['author'] ) ) {
			$author_value = sanitize_text_field( $meta['author'] );

			// Try email first.
			$user = get_user_by( 'email', $author_value );

			// Then try login.
			if ( ! $user ) {
				$user = get_user_by( 'login', $author_value );
			}

			if ( $user ) {
				return (int) $user->ID;
			}
		}

		// Fallback: first administrator.
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Check if a post type is allowed for post operations.
	 *
	 * Allows public, non-hierarchical post types (posts, articles, etc.)
	 * but excludes pages (hierarchical) and attachments.
	 *
	 * @param string $post_type The post type to check.
	 * @return bool
	 */
	private function is_allowed_post_type( $post_type ) {
		$post_type_obj = get_post_type_object( $post_type );

		if ( ! $post_type_obj ) {
			return false;
		}

		return $post_type_obj->public && ! $post_type_obj->hierarchical;
	}
}
