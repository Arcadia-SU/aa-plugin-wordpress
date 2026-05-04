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

		// Filter by specific article ID.
		$id = $request->get_param( 'id' );
		if ( $id ) {
			$args['p'] = (int) $id;
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
				sprintf(
					/* translators: %s: post type slug */
					__( "Post type '%s' is not allowed. Must be a public, non-hierarchical type (e.g. post).", 'arcadia-agents' ),
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		// Build post_data (status validation, title/slug/excerpt, content rendering).
		$builder = new Arcadia_Post_Builder( $this->blocks );
		$built   = $builder->build_post_data( $body, $meta, $post_type );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$post_data                = $built['post_data'];
		$post_data['post_author'] = $post_author;
		$rendered_post_content    = $built['rendered_content'];
		$force_draft_applied      = $built['force_draft_applied'];

		$post_id = $builder->write_post( $post_data, $post_author );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$finalize = $builder->finalize_post(
			(int) $post_id,
			$body,
			$meta,
			$post_type,
			$rendered_post_content,
			$this,
			array( 'is_create' => true )
		);
		if ( is_wp_error( $finalize ) ) {
			return $finalize;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_read_failed',
				sprintf(
					/* translators: %d: post ID */
					__( 'Failed to read post %d after creation.', 'arcadia-agents' ),
					$post_id
				),
				array( 'status' => 500 )
			);
		}

		$response_data = array(
			'success' => true,
			'post_id' => $post_id,
			'post'    => $this->format_post( $post ),
		);

		if ( $force_draft_applied ) {
			$response_data['force_draft_applied'] = true;
		}

		if ( ! empty( $finalize['warnings'] ) ) {
			$response_data['warnings'] = $finalize['warnings'];
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
				sprintf(
					/* translators: %d: post ID */
					__( 'Post with ID %d not found.', 'arcadia-agents' ),
					$post_id
				),
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

		// Pending Revision: store as revision instead of live update.
		// pending_revision takes priority over force_draft (early return = no wp_update_post).
		$pending_revision_flag = ! empty( $body['pending_revision'] );
		if ( $pending_revision_flag
			&& get_option( 'aa_pending_revisions', false )
			&& 'publish' === $post->post_status
		) {
			// Render content for revision storage.
			$revision_content = null;
			if ( ! empty( $body['h1'] ) || ! empty( $body['sections'] ) || ! empty( $body['children'] ) ) {
				$revision_content = $this->blocks->json_to_blocks( $body, $post->post_type );
				if ( is_wp_error( $revision_content ) ) {
					return $revision_content;
				}
			} elseif ( isset( $body['content'] ) && is_string( $body['content'] ) ) {
				$revision_content = wp_kses_post( $body['content'] );
			}

			$revisions  = Arcadia_Revisions::get_instance();
			$rev_result = $revisions->create_revision( $post_id, $body, $meta, $revision_content );
			if ( is_wp_error( $rev_result ) ) {
				return $rev_result;
			}

			return new WP_REST_Response(
				array(
					'revision_created'     => true,
					'revision_id'          => $rev_result['revision_id'],
					'revision_version'     => $rev_result['revision_version'],
					'preview_url'          => $rev_result['preview_url'],
					'original_post_status' => $post->post_status,
					'message'              => 'Revision stored. Live post unchanged.',
				),
				201
			);
		}

		// Build post_data (status validation, title/slug/excerpt, content rendering).
		$builder = new Arcadia_Post_Builder( $this->blocks );
		$built   = $builder->build_post_data( $body, $meta, $post->post_type, $post );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$post_data             = $built['post_data'];
		$rendered_post_content = $built['rendered_content'];
		$force_draft_applied   = $built['force_draft_applied'];

		$result = $builder->write_post( $post_data, (int) $post->post_author );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$finalize = $builder->finalize_post(
			(int) $post_id,
			$body,
			$meta,
			$post->post_type,
			$rendered_post_content,
			$this,
			array( 'is_create' => false )
		);
		if ( is_wp_error( $finalize ) ) {
			return $finalize;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_read_failed',
				sprintf(
					/* translators: %d: post ID */
					__( 'Failed to read post %d after update.', 'arcadia-agents' ),
					$post_id
				),
				array( 'status' => 500 )
			);
		}

		$response_data = array(
			'success' => true,
			'post'    => $this->format_post( $post ),
		);

		if ( $force_draft_applied ) {
			$response_data['force_draft_applied'] = true;
		}

		if ( ! empty( $finalize['warnings'] ) ) {
			$response_data['warnings'] = $finalize['warnings'];
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
				sprintf(
					/* translators: %d: post ID */
					__( 'Post with ID %d not found.', 'arcadia-agents' ),
					$post_id
				),
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
				sprintf(
					/* translators: %d: post ID */
					__( 'Post with ID %d not found.', 'arcadia-agents' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		// Move to trash by default, force delete if specified.
		$force = $request->get_param( 'force' ) === true;

		$result = wp_delete_post( $post_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %d: post ID */
					__( 'Failed to delete post %d.', 'arcadia-agents' ),
					$post_id
				),
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
				sprintf(
					/* translators: %d: page ID */
					__( 'Page with ID %d not found.', 'arcadia-agents' ),
					$page_id
				),
				array( 'status' => 404 )
			);
		}

		$body = $request->get_json_params();
		$meta = isset( $body['meta'] ) ? $body['meta'] : array();

		$post_data = array( 'ID' => $page_id );

		// Title: body.title = H1 (visible heading), meta.title = SEO meta-title.
		// body.title takes priority for post_title; meta.title is only a fallback.
		if ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		} elseif ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
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
					sprintf(
						/* translators: 1: received status, 2: allowed statuses */
						__( "Invalid post status '%1\$s'. Allowed: %2\$s.", 'arcadia-agents' ),
						$status,
						implode( ', ', $allowed_statuses )
					),
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

		// Store SEO meta: meta.title = SEO meta-title (distinct from post_title/H1).
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
				sprintf(
					/* translators: %d: page ID */
					__( 'Failed to read page %d after update.', 'arcadia-agents' ),
					$page_id
				),
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
