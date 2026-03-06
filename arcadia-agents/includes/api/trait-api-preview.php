<?php
/**
 * Preview URL API handler.
 *
 * Generates preview URLs for draft/private posts via REST API.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Preview_Handler
 *
 * Provides the preview-url endpoint handler.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Preview_Handler {

	/**
	 * Get a preview URL for a post.
	 *
	 * Generates a time-limited token and returns the preview URL.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preview_url( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'arcadia-agents' ),
				array( 'status' => 404 )
			);
		}

		$preview = Arcadia_Preview::get_instance();
		$token   = $preview->generate_token( $post_id );

		$preview_url = add_query_arg(
			array(
				'p'          => $post_id,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		return new WP_REST_Response(
			array(
				'preview_url' => $preview_url,
				'expires_in'  => DAY_IN_SECONDS,
			),
			200
		);
	}
}
