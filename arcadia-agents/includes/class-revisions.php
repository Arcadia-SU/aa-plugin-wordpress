<?php
/**
 * Pending Revisions management via hidden Custom Post Type.
 *
 * Registers the aa_revision CPT and provides CRUD methods
 * for creating, approving, rejecting, and listing revisions.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Revisions
 *
 * Manages pending revisions stored as a hidden Custom Post Type.
 * When the agent sends `pending_revision: true` on a PUT /articles/{id},
 * the update is stored as an aa_revision instead of modifying the live post.
 */
class Arcadia_Revisions {

	/**
	 * Single instance.
	 *
	 * @var Arcadia_Revisions|null
	 */
	private static $instance = null;

	/**
	 * Get single instance.
	 *
	 * @return Arcadia_Revisions
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
	private function __construct() {}

	/**
	 * Register the aa_revision CPT.
	 *
	 * Hidden from admin UI and frontend. Content is the rendered
	 * post_content of the proposed revision.
	 */
	public function register_post_type() {
		register_post_type(
			'aa_revision',
			array(
				'labels'       => array( 'name' => 'AA Revisions' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Create a pending revision for a published post.
	 *
	 * Auto-supersedes any existing pending revision for the same post.
	 * Generates a preview token for the revision.
	 *
	 * @param int         $post_id          The original post ID.
	 * @param array       $body             The full request body (title, excerpt, content, acf_fields, etc.).
	 * @param array       $meta             The meta array from the request body.
	 * @param string|null $rendered_content  The rendered post_content (blocks HTML) or null.
	 * @return array|WP_Error Array with revision_id, revision_version, preview_url on success.
	 */
	public function create_revision( $post_id, $body, $meta, $rendered_content = null ) {
		// Auto-supersede existing pending revision.
		$existing = $this->get_pending_revision( $post_id );
		if ( $existing ) {
			wp_update_post(
				array(
					'ID'          => $existing->ID,
					'post_status' => 'superseded',
				)
			);
			update_post_meta(
				$existing->ID,
				'_aa_revision_decision_notes',
				sprintf( 'Superseded by newer revision.' )
			);
		}

		// Compute next version number.
		$version = $this->get_next_version( $post_id );

		// Build the title for the revision CPT.
		$title = '';
		if ( ! empty( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
		} elseif ( ! empty( $meta['title'] ) ) {
			$title = sanitize_text_field( $meta['title'] );
		} else {
			$original = get_post( $post_id );
			$title    = $original ? $original->post_title : '';
		}

		// Insert the revision CPT.
		$revision_id = wp_insert_post(
			array(
				'post_type'    => 'aa_revision',
				'post_parent'  => $post_id,
				'post_status'  => 'pending',
				'post_title'   => $title,
				'post_content' => $rendered_content ?? '',
			),
			true
		);

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		// Store the complete payload as JSON for replay on approve.
		$revision_meta = array(
			'body' => $body,
			'meta' => $meta,
		);
		update_post_meta( $revision_id, '_aa_revision_version', $version );
		update_post_meta( $revision_id, '_aa_revision_meta', wp_json_encode( $revision_meta ) );
		update_post_meta( $revision_id, '_aa_revision_created_by', 'arcadia_agent' );

		if ( ! empty( $body['revision_notes'] ) ) {
			update_post_meta(
				$revision_id,
				'_aa_revision_notes',
				sanitize_textarea_field( $body['revision_notes'] )
			);
		}

		// Generate preview token (reuses existing preview system).
		$preview  = Arcadia_Preview::get_instance();
		$token    = $preview->generate_token( $revision_id );
		$preview_url = add_query_arg(
			array(
				'p'          => $revision_id,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		return array(
			'revision_id'      => $revision_id,
			'revision_version' => $version,
			'preview_url'      => $preview_url,
		);
	}

	/**
	 * Approve a pending revision: apply changes to the live post.
	 *
	 * Uses wp_update_post() which creates a native WP revision for rollback.
	 * Replays metadata (SEO, ACF fields, featured image, taxonomies).
	 *
	 * @param int    $revision_id  The aa_revision post ID.
	 * @param string $user_login   The WP user login who approved.
	 * @return true|WP_Error True on success.
	 */
	public function approve_revision( $revision_id, $user_login ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $revision->post_status ) {
			return new WP_Error( 'revision_not_pending', 'Revision is not pending.', array( 'status' => 400 ) );
		}

		$post_id = $revision->post_parent;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'original_post_not_found', 'Original post not found.', array( 'status' => 404 ) );
		}

		// Load the stored payload.
		$revision_meta_json = get_post_meta( $revision_id, '_aa_revision_meta', true );
		$revision_meta      = json_decode( $revision_meta_json, true );
		if ( ! is_array( $revision_meta ) ) {
			return new WP_Error( 'revision_meta_corrupt', 'Revision metadata is corrupted.', array( 'status' => 500 ) );
		}

		$body = $revision_meta['body'] ?? array();
		$meta = $revision_meta['meta'] ?? array();

		// Build post_data for wp_update_post.
		$post_data = array( 'ID' => $post_id );

		// Title.
		if ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		} elseif ( ! empty( $meta['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $meta['title'] );
		}

		// Content (stored in the revision CPT's post_content).
		if ( ! empty( $revision->post_content ) ) {
			$post_data['post_content'] = $revision->post_content;
		}

		// Excerpt.
		if ( isset( $body['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $body['excerpt'] );
		} elseif ( ! empty( $meta['description'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $meta['description'] );
		}

		// Slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Apply update to live post (creates native WP revision for rollback).
		$post_data = wp_slash( $post_data );
		$result    = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Replay SEO meta.
		if ( ! empty( $meta['title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $meta['title'] ) );
		}
		if ( ! empty( $meta['description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $meta['description'] ) );
		}

		// Replay featured image.
		if ( ! empty( $meta['featured_image_url'] ) ) {
			$api = Arcadia_API::get_instance();
			if ( method_exists( $api, 'sideload_and_set_featured_image' ) ) {
				$api->sideload_and_set_featured_image(
					$post_id,
					$meta['featured_image_url'],
					$meta['featured_image_alt'] ?? ''
				);
			}
		}

		// Replay taxonomies.
		if ( ! empty( $meta['categories'] ) && is_array( $meta['categories'] ) ) {
			$api = Arcadia_API::get_instance();
			if ( method_exists( $api, 'get_or_create_terms' ) ) {
				$cat_result = $api->get_or_create_terms( $meta['categories'], 'category' );
				wp_set_post_categories( $post_id, $cat_result['ids'], false );
			}
		}
		if ( ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$api = Arcadia_API::get_instance();
			if ( method_exists( $api, 'get_or_create_terms' ) ) {
				$tag_result = $api->get_or_create_terms( $meta['tags'], 'post_tag' );
				wp_set_post_tags( $post_id, $tag_result['ids'], false );
			}
		}

		// Replay ACF fields.
		if ( ! empty( $body['acf_fields'] ) && is_array( $body['acf_fields'] ) && function_exists( 'update_field' ) ) {
			foreach ( $body['acf_fields'] as $field_name => $field_value ) {
				update_field( $field_name, $field_value, $post_id );
			}
			update_post_meta( $post_id, '_acf_changed', 1 );
			do_action( 'acf/save_post', $post_id );
		}

		// Mark revision as approved.
		wp_update_post(
			array(
				'ID'          => $revision_id,
				'post_status' => 'approved',
			)
		);
		update_post_meta( $revision_id, '_aa_revision_decided_by', sanitize_text_field( $user_login ) );
		update_post_meta( $revision_id, '_aa_revision_decided_at', gmdate( 'c' ) );

		/**
		 * Fires when a revision decision is made.
		 *
		 * @param int    $revision_id The revision ID.
		 * @param string $decision    The decision: 'approved' or 'rejected'.
		 */
		do_action( 'aa_revision_decided', $revision_id, 'approved' );

		return true;
	}

	/**
	 * Reject a pending revision.
	 *
	 * @param int    $revision_id    The aa_revision post ID.
	 * @param string $user_login     The WP user login who rejected.
	 * @param string $decision_notes Optional notes explaining the rejection.
	 * @return true|WP_Error True on success.
	 */
	public function reject_revision( $revision_id, $user_login, $decision_notes = '' ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $revision->post_status ) {
			return new WP_Error( 'revision_not_pending', 'Revision is not pending.', array( 'status' => 400 ) );
		}

		wp_update_post(
			array(
				'ID'          => $revision_id,
				'post_status' => 'rejected',
			)
		);
		update_post_meta( $revision_id, '_aa_revision_decided_by', sanitize_text_field( $user_login ) );
		update_post_meta( $revision_id, '_aa_revision_decided_at', gmdate( 'c' ) );

		if ( ! empty( $decision_notes ) ) {
			update_post_meta(
				$revision_id,
				'_aa_revision_decision_notes',
				sanitize_textarea_field( $decision_notes )
			);
		}

		do_action( 'aa_revision_decided', $revision_id, 'rejected' );

		return true;
	}

	/**
	 * Get the pending revision for a post (at most one).
	 *
	 * @param int $post_id The original post ID.
	 * @return WP_Post|null The pending revision or null.
	 */
	public function get_pending_revision( $post_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'aa_revision',
				'post_parent'    => $post_id,
				'post_status'    => 'pending',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Get revisions for a post with pagination and optional status filter.
	 *
	 * @param int   $post_id The original post ID.
	 * @param array $args    Optional. Query args: status, page, per_page.
	 * @return array Array with 'revisions', 'total', 'page', 'per_page'.
	 */
	public function get_revisions( $post_id, $args = array() ) {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );

		$query_args = array(
			'post_type'      => 'aa_revision',
			'post_parent'    => $post_id,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Filter by status if provided.
		$allowed_statuses = array( 'pending', 'approved', 'rejected', 'superseded' );
		if ( ! empty( $args['status'] ) && in_array( $args['status'], $allowed_statuses, true ) ) {
			$query_args['post_status'] = $args['status'];
		} else {
			$query_args['post_status'] = $allowed_statuses;
		}

		$query = new WP_Query( $query_args );

		$revisions = array();
		foreach ( $query->posts as $rev ) {
			$revisions[] = $this->format_revision( $rev );
		}

		return array(
			'revisions' => $revisions,
			'total'     => (int) $query->found_posts,
			'page'      => $page,
			'per_page'  => $per_page,
		);
	}

	/**
	 * Get a single revision by ID.
	 *
	 * @param int $revision_id The aa_revision post ID.
	 * @return array|WP_Error Formatted revision or error.
	 */
	public function get_revision( $revision_id ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}

		return $this->format_revision( $revision );
	}

	/**
	 * Format a revision post for API response.
	 *
	 * @param WP_Post $revision The revision post object.
	 * @return array Formatted revision data.
	 */
	public function format_revision( $revision ) {
		$version = (int) get_post_meta( $revision->ID, '_aa_revision_version', true );

		// Get or create preview URL.
		$preview      = Arcadia_Preview::get_instance();
		$token        = $preview->get_or_create_token( $revision->ID );
		$preview_url  = add_query_arg(
			array(
				'p'          => $revision->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		$data = array(
			'revision_id'      => $revision->ID,
			'revision_version' => $version,
			'status'           => $revision->post_status,
			'created_at'       => ! empty( $revision->post_date ) ? gmdate( 'c', strtotime( $revision->post_date ) ) : null,
			'created_by'       => get_post_meta( $revision->ID, '_aa_revision_created_by', true ) ?: null,
			'decided_at'       => get_post_meta( $revision->ID, '_aa_revision_decided_at', true ) ?: null,
			'decided_by'       => get_post_meta( $revision->ID, '_aa_revision_decided_by', true ) ?: null,
			'decision_notes'   => get_post_meta( $revision->ID, '_aa_revision_decision_notes', true ) ?: null,
			'revision_notes'   => get_post_meta( $revision->ID, '_aa_revision_notes', true ) ?: null,
			'preview_url'      => $preview_url,
		);

		return $data;
	}

	/**
	 * Get the next version number for revisions of a post.
	 *
	 * @param int $post_id The original post ID.
	 * @return int The next version number.
	 */
	private function get_next_version( $post_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'aa_revision',
				'post_parent'    => $post_id,
				'post_status'    => array( 'pending', 'approved', 'rejected', 'superseded' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_aa_revision_version',
			)
		);

		if ( ! empty( $query->posts ) ) {
			$last_version = (int) get_post_meta( $query->posts[0]->ID, '_aa_revision_version', true );
			return $last_version + 1;
		}

		return 1;
	}
}
