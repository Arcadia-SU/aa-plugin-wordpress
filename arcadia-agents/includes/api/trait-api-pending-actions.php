<?php
/**
 * Pending actions API: list, withdraw, and the executor approvals run.
 *
 * Contract: docs/contracts/write-table.md §4 (Phase 55 / CH03).
 *
 * @package ArcadiaAgents
 * @since   0.13.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-pending-actions.php';

/**
 * Trait Arcadia_API_Pending_Actions_Handler
 */
trait Arcadia_API_Pending_Actions_Handler {

	/**
	 * GET /pending-actions — pending actions and revisions, newest first.
	 *
	 * Only kinds whose read scope is enabled are listed. Asking for one kind
	 * explicitly without its scope is refused, like reading it directly.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_pending_actions( $request ) {
		$kind = $request->get_param( 'object_kind' );
		$kind = null === $kind ? null : sanitize_key( (string) $kind );

		if ( null !== $kind && ! isset( Arcadia_Pending_Actions::KIND_SCOPES[ $kind ] ) ) {
			return new WP_Error(
				'invalid_object_kind',
				sprintf(
					/* translators: %s: allowed kinds */
					__( 'object_kind must be one of: %s.', 'arcadia-agents' ),
					implode( ', ', array_keys( Arcadia_Pending_Actions::KIND_SCOPES ) )
				),
				array( 'status' => 400 )
			);
		}

		$kinds = array();
		foreach ( Arcadia_Pending_Actions::KIND_SCOPES as $candidate => $scopes ) {
			if ( null !== $kind && $candidate !== $kind ) {
				continue;
			}
			$allowed = $this->auth->check_scope( $scopes[0] );
			if ( is_wp_error( $allowed ) ) {
				if ( null !== $kind ) {
					return $allowed;
				}
				continue;
			}
			$kinds[] = $candidate;
		}

		$ids = $this->parse_pending_action_ids( $request->get_param( 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$status = $request->get_param( 'status' );
		$result = Arcadia_Pending_Actions::get_instance()->list_entries(
			array(
				'status'      => null === $status ? null : sanitize_key( (string) $status ),
				'object_kind' => $kind,
				'object_id'   => $request->get_param( 'object_id' ) ? (int) $request->get_param( 'object_id' ) : null,
				'ids'         => $ids,
				'kinds'       => $kinds,
			)
		);

		$body = array(
			'pending_actions' => $result['entries'],
			'truncated'       => $result['truncated'],
		);
		if ( $ids ) {
			$body['not_found'] = $result['not_found'];
		}
		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * `ids` query parameter: "812,813" (or an array), 1 to IDS_MAX positive ids.
	 *
	 * @param mixed $raw Raw parameter.
	 * @return int[]|WP_Error Empty array when absent.
	 */
	private function parse_pending_action_ids( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids   = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( ! ctype_digit( $part ) || 0 === (int) $part ) {
				$ids = null;
				break;
			}
			$ids[] = (int) $part;
		}
		$ids = null === $ids ? null : array_values( array_unique( $ids ) );
		if ( null === $ids || count( $ids ) > Arcadia_Pending_Actions::IDS_MAX ) {
			return new WP_Error(
				'invalid_ids',
				sprintf(
					/* translators: %s: maximum number of ids */
					__( 'ids must be a comma-separated list of 1 to %s positive IDs.', 'arcadia-agents' ),
					Arcadia_Pending_Actions::IDS_MAX
				),
				array( 'status' => 400 )
			);
		}
		return $ids;
	}

	/**
	 * POST /pending-actions/{id}/withdraw — Arcadia takes its proposal back.
	 *
	 * Needs the write scope of the object's kind.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_pending_action( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( Arcadia_Pending_Actions::POST_TYPE, 'aa_revision' ), true ) ) {
			return Arcadia_Write_Table::refusal( 'pending_action_not_found' );
		}

		$entry   = Arcadia_Pending_Actions::get_instance()->format_entry( $post );
		$scopes  = Arcadia_Pending_Actions::KIND_SCOPES[ $entry['object']['kind'] ] ?? null;
		$allowed = null === $scopes ? new WP_Error( 'pending_action_corrupt', 'Unknown object kind.', array( 'status' => 500 ) ) : $this->auth->check_scope( $scopes[1] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = Arcadia_Pending_Actions::get_instance()->withdraw( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			Arcadia_Pending_Actions::get_instance()->format_entry( get_post( $id ) ),
			200
		);
	}

	/**
	 * Apply an approved action — the same code a direct write runs.
	 *
	 * @param string $action  Action.
	 * @param array  $object  {kind, type, id}.
	 * @param array  $payload Stored payload.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_held_action( $action, array $object, array $payload ) {
		$id = (int) ( $object['id'] ?? 0 );

		switch ( $action ) {
			case 'content.delete':
				return $this->apply_content_delete( $id, (string) ( $payload['mode'] ?? 'trash' ) );
			case 'content.featured_image':
				return $this->apply_featured_image( $id, (int) ( $payload['attachment_id'] ?? 0 ) );
			case 'term.update':
				return $this->apply_term_update( (string) $object['type'], $id, (array) ( $payload['args'] ?? array() ) );
			case 'term.delete':
				return $this->apply_term_delete( (string) $object['type'], $id );
			case 'media.update':
				return $this->apply_media_update( $id, (array) ( $payload['fields'] ?? array() ) );
			case 'media.delete':
				return $this->apply_media_delete( $id, ! empty( $payload['force'] ) );
			case 'redirect.create':
				return $this->apply_redirect_create( (string) ( $payload['source'] ?? '' ), (string) ( $payload['target'] ?? '' ), (int) ( $payload['type'] ?? 301 ) );
			case 'redirect.delete':
				return $this->apply_redirect_delete( $id );
		}

		return new WP_Error( 'unknown_action', 'Unknown pending action: ' . $action, array( 'status' => 500 ) );
	}
}
