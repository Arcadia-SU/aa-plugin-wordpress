<?php
/**
 * Redirects API handlers.
 *
 * Handles CRUD operations for redirect rules stored as
 * the arcadia_redirect Custom Post Type.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Redirects_Handler
 *
 * Provides methods for redirect CRUD endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Redirects_Handler {

	/**
	 * List redirects with pagination.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_redirects( $request ) {
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'arcadia_redirect',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$redirects = array();
		foreach ( $query->posts as $post ) {
			$redirects[] = $this->format_redirect( $post );
		}

		return new WP_REST_Response(
			array(
				'redirects' => $redirects,
				'total'     => (int) $query->found_posts,
				'page'      => $page,
				'per_page'  => $per_page,
			),
			200
		);
	}

	/**
	 * Create a redirect rule.
	 *
	 * @param WP_REST_Request $request The request (JSON body: source, target, type).
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_redirect( $request ) {
		$params = $request->get_json_params();

		$source = isset( $params['source'] ) ? sanitize_text_field( $params['source'] ) : '';
		$target = isset( $params['target'] ) ? esc_url_raw( $params['target'] ) : '';
		$type   = isset( $params['type'] ) ? (int) $params['type'] : 301;

		if ( empty( $source ) ) {
			return new WP_Error(
				'missing_source',
				__( 'Source URL is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $target ) ) {
			return new WP_Error(
				'missing_target',
				__( 'Target URL is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $type, array( 301, 302 ), true ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Redirect type must be 301 or 302.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Normalize source path (ensure leading slash, no trailing slash).
		$source = '/' . trim( $source, '/' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'arcadia_redirect',
				'post_title'  => $source,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_redirect_target', $target );
		update_post_meta( $post_id, '_redirect_type', $type );

		// Invalidate cache.
		Arcadia_Redirects::get_instance()->invalidate_cache();

		$post = get_post( $post_id );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'redirect' => $this->format_redirect( $post ),
			),
			201
		);
	}

	/**
	 * Delete a redirect rule.
	 *
	 * @param WP_REST_Request $request The request (URL param: id).
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_redirect( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'arcadia_redirect' !== $post->post_type ) {
			return new WP_Error(
				'redirect_not_found',
				__( 'Redirect not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		wp_delete_post( $id, true );

		// Invalidate cache.
		Arcadia_Redirects::get_instance()->invalidate_cache();

		return new WP_REST_Response(
			array(
				'success' => true,
				'deleted' => $id,
			),
			200
		);
	}

	/**
	 * Format a redirect post for API response.
	 *
	 * @param object $post The redirect post object.
	 * @return array Formatted redirect data.
	 */
	private function format_redirect( $post ) {
		return array(
			'id'         => (int) $post->ID,
			'source'     => $post->post_title,
			'target'     => get_post_meta( $post->ID, '_redirect_target', true ),
			'type'       => (int) get_post_meta( $post->ID, '_redirect_type', true ),
			'created_at' => $post->post_date,
		);
	}
}
