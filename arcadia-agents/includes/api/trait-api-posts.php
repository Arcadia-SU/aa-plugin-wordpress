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
		$per_page  = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$per_page  = max( 1, min( 100, $per_page ) );

		// Whitelist orderby to prevent arbitrary column queries.
		$orderby_whitelist = array( 'date', 'title', 'modified' );
		$orderby           = $request->get_param( 'orderby' ) ?? 'date';
		if ( ! in_array( $orderby, $orderby_whitelist, true ) ) {
			$orderby = 'date';
		}

		// Whitelist order direction.
		$order = strtoupper( $request->get_param( 'order' ) ?? 'DESC' );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$args = array(
			'post_type'      => sanitize_text_field( $post_type ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		// Filter by status.
		$status = $request->get_param( 'status' );
		if ( $status ) {
			$args['post_status'] = sanitize_text_field( $status );
		}

		// Filter by category (slug or ID).
		$category = $request->get_param( 'category' );
		if ( $category ) {
			if ( is_numeric( $category ) ) {
				$args['cat'] = (int) $category;
			} else {
				$args['category_name'] = sanitize_text_field( $category );
			}
		}

		// Filter by tag (slug).
		$tag = $request->get_param( 'tag' );
		if ( $tag ) {
			$args['tag'] = sanitize_text_field( $tag );
		}

		// Filter by author (email or login → resolved to ID).
		$author = $request->get_param( 'author' );
		if ( $author ) {
			$author_id = $this->resolve_author_filter( $author );
			if ( $author_id ) {
				$args['author'] = $author_id;
			}
		}

		// Filter by date range.
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		if ( $date_from || $date_to ) {
			$date_query = array();
			if ( $date_from ) {
				$date_query['after'] = sanitize_text_field( $date_from );
			}
			if ( $date_to ) {
				$date_query['before'] = sanitize_text_field( $date_to );
			}
			$date_query['inclusive'] = true;
			$args['date_query']     = array( $date_query );
		}

		// Search.
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = $search;
		}

		// Filter by source (arcadia, wordpress, or all).
		$source = $request->get_param( 'source' );
		if ( $source && 'all' !== $source ) {
			if ( 'arcadia' === $source ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'arcadia_source',
						'field'    => 'slug',
						'terms'    => 'arcadia',
					),
				);
			} elseif ( 'wordpress' === $source ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'arcadia_source',
						'field'    => 'slug',
						'terms'    => 'arcadia',
						'operator' => 'NOT IN',
					),
				);
			}
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
	 * Resolve an author filter value to a user ID.
	 *
	 * Tries email first, then login name.
	 *
	 * @param string $author Author email or login.
	 * @return int|null User ID or null if not found.
	 */
	private function resolve_author_filter( $author ) {
		$author_value = sanitize_text_field( $author );

		// Try email.
		$user = get_user_by( 'email', $author_value );
		if ( $user ) {
			return (int) $user->ID;
		}

		// Try login.
		$user = get_user_by( 'login', $author_value );
		if ( $user ) {
			return (int) $user->ID;
		}

		// Try numeric ID directly.
		if ( is_numeric( $author_value ) ) {
			return (int) $author_value;
		}

		return null;
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

		if ( ! $this->is_allowed_post_type( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'Post type is not allowed.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Validate post status.
		$status           = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'draft';
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'Invalid post status.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Build post data.
		$post_data = array(
			'post_type'   => $post_type,
			'post_status' => $status,
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
		$original_user_id = get_current_user_id();
		wp_set_current_user( $post_author );

		// Save rendered post_content before wp_slash for ACF wysiwyg fallback.
		$rendered_post_content = $post_data['post_content'] ?? '';

		// Slash data for wp_insert_post() which internally calls wp_unslash().
		// Without this, backslashes in JSON block data (e.g. escaped quotes in
		// href attributes) are stripped, breaking the block JSON structure.
		$post_data = wp_slash( $post_data );

		// Create the post.
		$post_id = wp_insert_post( $post_data, true );

		wp_set_current_user( $original_user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set categories and tags, collecting any creation warnings.
		$taxonomy_warnings = array();

		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$cat_result = $this->get_or_create_terms( $meta['categories'], 'category' );
			wp_set_post_categories( $post_id, $cat_result['ids'] );
			$taxonomy_warnings = array_merge( $taxonomy_warnings, $cat_result['errors'] );
		}

		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$tag_result = $this->get_or_create_terms( $meta['tags'], 'post_tag' );
			wp_set_post_tags( $post_id, $tag_result['ids'] );
			$taxonomy_warnings = array_merge( $taxonomy_warnings, $tag_result['errors'] );
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

		// Process ACF fields: explicit payload or auto-populate from content.
		if ( ! empty( $body['acf_fields'] ) && is_array( $body['acf_fields'] ) ) {
			$acf_result = $this->process_acf_fields(
				$post_id, $body['acf_fields'], $post_type, $rendered_post_content
			);
			if ( is_wp_error( $acf_result ) ) {
				return $acf_result;
			}
		} else {
			// No explicit acf_fields — create safe ACF references.
			// Ensures get_fields() returns an array (not false), preventing
			// fatal errors in themes that don't guard against false.
			$this->auto_populate_acf_fields( $post_id, $post_type );
		}

		// Always set _acf_changed when ACF is active (finding 023).
		if ( function_exists( 'update_field' ) ) {
			update_post_meta( $post_id, '_acf_changed', 1 );
		}

		// Trigger ACF save hook to create field reference entries (finding 023).
		if ( function_exists( 'update_field' ) ) {
			do_action( 'acf/save_post', $post_id );
		}

		// Tag post as created by Arcadia (source tracking).
		if ( taxonomy_exists( 'arcadia_source' ) ) {
			wp_set_object_terms( $post_id, 'arcadia', 'arcadia_source' );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_read_failed',
				__( 'Failed to read post after creation.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

		$response_data = array(
			'success' => true,
			'post_id' => $post_id,
			'post'    => $this->format_post( $post ),
		);

		if ( ! empty( $taxonomy_warnings ) ) {
			$response_data['warnings'] = $taxonomy_warnings;
		}

		return new WP_REST_Response( $response_data, 201 );
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

		// Reject post_type changes (finding #11).
		$requested_type = ! empty( $meta['post_type'] ) ? $meta['post_type'] : ( ! empty( $body['post_type'] ) ? $body['post_type'] : null );
		if ( null !== $requested_type && $requested_type !== $post->post_type ) {
			return new WP_Error(
				'post_type_change_forbidden',
				__( 'Changing post_type via update is not allowed. Delete and re-create instead.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

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
			$status           = sanitize_text_field( $body['status'] );
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				return new WP_Error(
					'invalid_status',
					__( 'Invalid post status.', 'arcadia-agents' ),
					array( 'status' => 400 )
				);
			}
			$post_data['post_status'] = $status;
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
		$original_user_id = get_current_user_id();
		$post             = get_post( $post_id );
		if ( $post ) {
			wp_set_current_user( $post->post_author );
		}

		// Save rendered post_content before wp_slash for ACF wysiwyg fallback.
		$rendered_post_content = $post_data['post_content'] ?? '';

		// Slash data for wp_update_post() which internally calls wp_unslash().
		$post_data = wp_slash( $post_data );

		$result = wp_update_post( $post_data, true );

		wp_set_current_user( $original_user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Append mode: add taxonomies instead of replacing (finding #22).
		$append = ! empty( $body['append_taxonomies'] );

		// Update categories and tags, collecting any creation warnings.
		$taxonomy_warnings = array();

		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$cat_result = $this->get_or_create_terms( $meta['categories'], 'category' );
			wp_set_post_categories( $post_id, $cat_result['ids'], $append );
			$taxonomy_warnings = array_merge( $taxonomy_warnings, $cat_result['errors'] );
		}

		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$tag_result = $this->get_or_create_terms( $meta['tags'], 'post_tag' );
			wp_set_post_tags( $post_id, $tag_result['ids'], $append );
			$taxonomy_warnings = array_merge( $taxonomy_warnings, $tag_result['errors'] );
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

		// Process ACF fields: explicit payload or auto-populate from content.
		$content_for_acf = $rendered_post_content;
		if ( empty( $content_for_acf ) ) {
			$existing = get_post( $post_id );
			$content_for_acf = $existing ? $existing->post_content : '';
		}

		if ( ! empty( $body['acf_fields'] ) && is_array( $body['acf_fields'] ) ) {
			$acf_result = $this->process_acf_fields(
				$post_id, $body['acf_fields'], $post->post_type, $content_for_acf
			);
			if ( is_wp_error( $acf_result ) ) {
				return $acf_result;
			}
		} else {
			// No explicit acf_fields — create safe ACF references.
			$this->auto_populate_acf_fields( $post_id, $post->post_type );
		}

		// Always set _acf_changed when ACF is active (finding 023).
		if ( function_exists( 'update_field' ) ) {
			update_post_meta( $post_id, '_acf_changed', 1 );
		}

		// Trigger ACF save hook to create field reference entries (finding 023).
		if ( function_exists( 'update_field' ) ) {
			do_action( 'acf/save_post', $post_id );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_read_failed',
				__( 'Failed to read post after update.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

		$response_data = array(
			'success' => true,
			'post'    => $this->format_post( $post ),
		);

		if ( ! empty( $taxonomy_warnings ) ) {
			$response_data['warnings'] = $taxonomy_warnings;
		}

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Get the block structure of a post.
	 *
	 * Uses parse_blocks() to return the parsed block tree.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_article_blocks( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $post->post_content ) ) {
			return new WP_REST_Response(
				array(
					'post_id' => $post_id,
					'blocks'  => array(),
				),
				200
			);
		}

		$parsed = parse_blocks( $post->post_content );
		$blocks = $this->format_parsed_blocks( $parsed );

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'blocks'  => $blocks,
			),
			200
		);
	}

	/**
	 * Format parsed blocks recursively for API response.
	 *
	 * Filters out empty/whitespace-only blocks (null blockName)
	 * and includes innerBlocks recursively.
	 *
	 * @param array $parsed_blocks Array from parse_blocks().
	 * @return array Formatted block list.
	 */
	private function format_parsed_blocks( $parsed_blocks ) {
		$blocks = array();

		foreach ( $parsed_blocks as $block ) {
			// Skip empty/whitespace blocks (null blockName).
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$formatted = array(
				'blockName'  => $block['blockName'],
				'attrs'      => ! empty( $block['attrs'] ) ? $block['attrs'] : new \stdClass(),
			);

			// Include innerHTML for content inspection.
			if ( ! empty( $block['innerHTML'] ) ) {
				$formatted['innerHTML'] = $block['innerHTML'];
			}

			// Recurse into innerBlocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$formatted['innerBlocks'] = $this->format_parsed_blocks( $block['innerBlocks'] );
			}

			$blocks[] = $formatted;
		}

		return $blocks;
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
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 50 );
		$per_page = max( 1, min( 100, $per_page ) );

		$args = array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $per_page,
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
			$status           = sanitize_text_field( $body['status'] );
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				return new WP_Error(
					'invalid_status',
					__( 'Invalid post status.', 'arcadia-agents' ),
					array( 'status' => 400 )
				);
			}
			$post_data['post_status'] = $status;
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
		$original_user_id = get_current_user_id();
		$page             = get_post( $page_id );
		if ( $page ) {
			wp_set_current_user( $page->post_author );
		}

		// Slash data for wp_update_post() which internally calls wp_unslash().
		$post_data = wp_slash( $post_data );

		$result = wp_update_post( $post_data, true );

		wp_set_current_user( $original_user_id );

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

		if ( ! $page ) {
			return new WP_Error(
				'page_read_failed',
				__( 'Failed to read page after update.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

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

		return ! empty( $admins ) && is_array( $admins ) ? (int) $admins[0] : 1;
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
