<?php
/**
 * REST API handler for pending revisions endpoints.
 *
 * Provides GET endpoints to list and retrieve revision details
 * for a given article.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Revisions_Handler
 *
 * Handles revision-related REST API endpoints.
 */
trait Arcadia_API_Revisions_Handler {

	/**
	 * List revisions for an article.
	 *
	 * GET /articles/{id}/revisions
	 * Supports ?status, ?page, ?per_page query params.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_article_revisions( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf( 'Post with ID %d not found.', $post_id ),
				array( 'status' => 404 )
			);
		}

		$args = array(
			'status'   => $request->get_param( 'status' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
		);

		$revisions = Arcadia_Revisions::get_instance();
		$result    = $revisions->get_revisions( $post_id, $args );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get a single revision detail.
	 *
	 * GET /articles/{id}/revisions/{revision_id}
	 * Validates that the revision belongs to the specified article.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_article_revision( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$revision_id = (int) $request->get_param( 'revision_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf( 'Post with ID %d not found.', $post_id ),
				array( 'status' => 404 )
			);
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error(
				'revision_not_found',
				'Revision not found.',
				array( 'status' => 404 )
			);
		}

		// Verify the revision belongs to this article.
		if ( (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'revision_not_found',
				'Revision does not belong to this article.',
				array( 'status' => 404 )
			);
		}

		$revisions = Arcadia_Revisions::get_instance();
		$result    = $revisions->format_revision( $revision );

		return new WP_REST_Response( $result, 200 );
	}
}
