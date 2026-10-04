<?php
/**
 * Connection lifecycle API handlers.
 *
 * Handles the disconnect endpoint: Arcadia tells the plugin that the owner
 * removed the WordPress connection on its side.
 *
 * @package ArcadiaAgents
 * @since   0.11.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Connection_Handler
 *
 * Provides methods for handling connection lifecycle endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Connection_Handler {

	/**
	 * Permission gate of `POST /disconnect`.
	 *
	 * Connected: the caller must present a JWT valid for this connection —
	 * signature against the stored public key, `sub`/`iss` against the pins —
	 * and nothing more. No scope: the owner of a connection may always withdraw
	 * it, and a scope checkbox left unticked must not keep a dead connection
	 * looking alive in the admin.
	 *
	 * Not connected: there is no public key left to validate anything against,
	 * and the handler then changes nothing, so the call is let through. Answering
	 * 401 would only make AA log a failure for a site already in the state it
	 * asked for.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function check_disconnect_permission( $request ) {
		if ( ! $this->auth->is_connected() ) {
			return true;
		}

		$result = $this->auth->authenticate_request( $request, null );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Disconnect the site on Arcadia's request.
	 *
	 * Idempotent: `200 {"success": true}` whether or not the plugin was still
	 * connected. When it was not, NOTHING is cleared — the request reached here
	 * unauthenticated, so it must not be able to touch state, not even a pending
	 * connection key typed in the admin.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function disconnect_site( $request ) {
		if ( $this->auth->is_connected() ) {
			$this->auth->disconnect();
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
