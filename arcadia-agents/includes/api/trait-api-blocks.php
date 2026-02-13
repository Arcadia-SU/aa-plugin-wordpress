<?php
/**
 * Blocks discovery API handler.
 *
 * Provides the GET /blocks endpoint for block type introspection.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Blocks_Handler
 *
 * Provides methods for handling the blocks discovery endpoint.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Blocks_Handler {

	/**
	 * Get available block types.
	 *
	 * Returns builtin and custom blocks with their field schemas.
	 * Custom blocks are discovered dynamically via ACF or Gutenberg introspection.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_blocks( $request ) {
		return new WP_REST_Response(
			array(
				'adapter' => $this->blocks->get_adapter_name(),
				'blocks'  => array(
					'builtin' => $this->registry->get_builtin_blocks(),
					'custom'  => $this->registry->get_custom_blocks(),
				),
			),
			200
		);
	}
}
