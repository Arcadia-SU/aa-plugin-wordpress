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
		$revision = $this->resolve_revision_for_article( $request );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		$revisions = Arcadia_Revisions::get_instance();

		// true = attach the before/after projection. This is the detail endpoint;
		// the listing above deliberately stays metadata-only (Phase 43.1).
		$result = $revisions->format_revision( $revision, true );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Withdraw a pending revision.
	 *
	 * POST /contents/{id}/revisions/{revision_id}/reject
	 *
	 * `reject` is exposed and `approve` is NOT, and the asymmetry is the whole
	 * design. Withdrawing a proposal never touches published content — the live
	 * post is exactly as it was. Approving does, and it is the one gate the client
	 * asked for when they turned the mechanism on; an agent approving its own
	 * revisions would walk straight around it. So this endpoint exists and its
	 * sibling will not.
	 *
	 * The cost it buys off is real: every e2e rehearsal used to leave a pending
	 * revision behind that only a human with wp-admin access could clear, and they
	 * accumulate on precisely the screen whose job is to be trustworthy.
	 *
	 * Behind its own scope, `revisions:write`, rather than `articles:write`. The
	 * two are different powers: one writes content, the other destroys a proposal
	 * a human may have been about to accept. On an existing site the scope arrives
	 * disabled, so no site gains this by upgrading.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_article_revision( $request ) {
		$revision = $this->resolve_revision_for_article( $request );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		$body  = $request->get_json_params();
		$notes = is_array( $body ) && isset( $body['decision_notes'] )
			? (string) $body['decision_notes']
			: '';

		// The JWT's `sub` identifies the SITE, not a person — there is no human
		// behind this call and the audit trail must not imply one. A fixed machine
		// identity keeps API withdrawals distinguishable from a click in wp-admin,
		// which is the question anyone reading the history will actually have.
		$result = Arcadia_Revisions::get_instance()->reject_revision(
			(int) $revision->ID,
			'arcadia-agents-api',
			$notes
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'rejected'    => true,
				'revision_id' => (int) $revision->ID,
			),
			200
		);
	}

	/**
	 * Resolve {revision_id} and prove it belongs to {id}.
	 *
	 * Shared by the detail and reject handlers so the ownership check cannot be
	 * present on one and forgotten on the other — without it, any revision ID
	 * could be addressed through any post's URL.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Post|WP_Error The revision, or the error to return.
	 */
	private function resolve_revision_for_article( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$revision_id = (int) $request->get_param( 'revision_id' );

		if ( ! get_post( $post_id ) ) {
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

		if ( (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'revision_not_found',
				'Revision does not belong to this article.',
				array( 'status' => 404 )
			);
		}

		return $revision;
	}
}
