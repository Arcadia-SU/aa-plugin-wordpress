<?php
/**
 * ACF block validation at publish time.
 *
 * Three validation layers (cheapest to heaviest):
 * - H1.1: ACF type validation — check field types against schema
 * - H1.2: Image URL auto-sideload — URL string → attachment ID
 * - H1.3: Render test — render_block() each saved block, catch errors
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_ACF_Validator
 *
 * Validates ACF block data before publishing. Catches type mismatches,
 * auto-sideloads image URLs, and tests rendering after save.
 */
class Arcadia_ACF_Validator {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_ACF_Validator|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_ACF_Validator
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

	// =========================================================================
	// H1.1 + H1.2 — Validate and Preprocess
	// =========================================================================

	/**
	 * Validate and preprocess ACF blocks in the content tree.
	 *
	 * Walks the block tree. For each ACF block (type starts with "acf/"):
	 * 1. H1.2: Sideloads image URL strings → attachment IDs (mutates in place)
	 * 2. H1.1: Validates field types against ACF schema
	 *
	 * Collects ALL errors (not fail-fast) so the agent can fix everything
	 * in one round-trip.
	 *
	 * @param array  &$blocks_json Block content structure (mutated: URLs → IDs).
	 * @param string $post_type    Target post type (reserved for future use).
	 * @return true|WP_Error True if valid, WP_Error with 422 + structured errors if not.
	 */
	public function validate_and_preprocess( &$blocks_json, $post_type = 'post' ) {
		$errors   = array();
		$registry = Arcadia_Block_Registry::get_instance();

		if ( ! empty( $blocks_json['children'] ) && is_array( $blocks_json['children'] ) ) {
			foreach ( $blocks_json['children'] as $index => &$block ) {
				$this->validate_block_recursive( $block, $index, $registry, $errors );
			}
			unset( $block );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'acf_validation_failed',
				__( 'ACF block validation failed.', 'arcadia-agents' ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}

		return true;
	}

	/**
	 * Validate a single block and recurse into children.
	 *
	 * @param array                  &$block   Block data (mutated for sideloaded images).
	 * @param int                     $index    Block index in parent's children array.
	 * @param Arcadia_Block_Registry  $registry Block registry instance.
	 * @param array                  &$errors   Collected errors (appended to).
	 */
	private function validate_block_recursive( &$block, $index, $registry, &$errors ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return;
		}

		$block_type = $block['type'];

		// Only validate ACF custom blocks (type starts with 'acf/').
		if ( str_contains( $block_type, '/' ) && str_starts_with( $block_type, 'acf/' ) ) {
			$schema = $registry->get_block_schema( $block_type );

			if ( is_array( $schema ) && ! empty( $schema ) ) {
				$this->validate_acf_block( $block, $index, $schema, $errors );
			}
		}

		// Recurse into children.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child_index => &$child ) {
				$this->validate_block_recursive( $child, $child_index, $registry, $errors );
			}
			unset( $child );
		}
	}

	/**
	 * Validate and preprocess a single ACF block against its schema.
	 *
	 * @param array &$block  Block data (mutated for sideloaded images).
	 * @param int    $index  Block index in parent's children array.
	 * @param array  $schema ACF field schema from registry.
	 * @param array &$errors Collected errors (appended to).
	 */
	private function validate_acf_block( &$block, $index, $schema, &$errors ) {
		$block_type = $block['type'];
		$properties = isset( $block['properties'] ) && is_array( $block['properties'] )
			? $block['properties']
			: array();

		// Build lookups from schema.
		$field_types     = array();
		$required_fields = array();

		foreach ( $schema as $field ) {
			$field_types[ $field['name'] ] = $field['type'];
			if ( ! empty( $field['required'] ) ) {
				$required_fields[] = $field['name'];
			}
		}

		// Track fields where sideload failed (skip type validation for those).
		$sideload_failed = array();

		// ---- H1.2: Image URL auto-sideload ----
		foreach ( $field_types as $field_name => $field_type ) {
			if ( 'image' !== $field_type ) {
				continue;
			}
			if ( ! isset( $properties[ $field_name ] ) || ! is_string( $properties[ $field_name ] ) ) {
				continue;
			}

			$url           = $properties[ $field_name ];
			$attachment_id = Arcadia_ACF_Adapter::sideload_image_field( $url );

			if ( is_wp_error( $attachment_id ) ) {
				$sideload_failed[] = $field_name;
				$errors[]          = array(
					'block_index' => $index,
					'block_type'  => $block_type,
					'field'       => $field_name,
					'expected'    => 'int (attachment ID)',
					'got'         => 'string (URL)',
					'suggestion'  => 'Image sideload failed: ' . $attachment_id->get_error_message() . '. Upload via POST /media first.',
				);
			} else {
				// Mutate block data: replace URL with attachment ID.
				$block['properties'][ $field_name ] = $attachment_id;
				$properties[ $field_name ]          = $attachment_id;
			}
		}

		// ---- H1.1: Type validation (after pre-processing) ----
		foreach ( $properties as $field_name => $value ) {
			// Skip fields where sideload already reported an error.
			if ( in_array( $field_name, $sideload_failed, true ) ) {
				continue;
			}

			$expected_type = $field_types[ $field_name ] ?? null;
			if ( null === $expected_type ) {
				continue; // Unknown field — skip (no schema entry).
			}

			$type_error = $this->check_field_type( $value, $expected_type );
			if ( null !== $type_error ) {
				$errors[] = array(
					'block_index' => $index,
					'block_type'  => $block_type,
					'field'       => $field_name,
					'expected'    => $type_error['expected'],
					'got'         => $type_error['got'],
					'suggestion'  => $type_error['suggestion'] ?? '',
				);
			}
		}

		// ---- Check required ACF fields ----
		foreach ( $required_fields as $req_field ) {
			if ( ! isset( $properties[ $req_field ] ) ) {
				$errors[] = array(
					'block_index' => $index,
					'block_type'  => $block_type,
					'field'       => $req_field,
					'expected'    => 'required',
					'got'         => 'missing',
					'suggestion'  => sprintf( "Add required field '%s'.", $req_field ),
				);
			}
		}
	}

	/**
	 * Check a value against an expected ACF field type.
	 *
	 * @param mixed  $value         The field value.
	 * @param string $expected_type The ACF field type.
	 * @return array|null Error descriptor ('expected', 'got', 'suggestion') or null if valid.
	 */
	private function check_field_type( $value, $expected_type ) {
		switch ( $expected_type ) {
			case 'image':
				if ( ! is_int( $value ) ) {
					return array(
						'expected'   => 'int (attachment ID)',
						'got'        => gettype( $value ) . ( is_string( $value ) ? ' (URL)' : '' ),
						'suggestion' => 'Upload via POST /media first.',
					);
				}
				break;

			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'url':
			case 'email':
				if ( ! is_string( $value ) ) {
					return array(
						'expected' => 'string',
						'got'      => gettype( $value ),
					);
				}
				break;

			case 'number':
				if ( ! is_int( $value ) && ! is_float( $value ) ) {
					return array(
						'expected' => 'int|float',
						'got'      => gettype( $value ),
					);
				}
				break;

			case 'select':
			case 'radio':
				if ( ! is_string( $value ) ) {
					return array(
						'expected' => 'string',
						'got'      => gettype( $value ),
					);
				}
				break;

			case 'repeater':
				if ( ! is_array( $value ) ) {
					return array(
						'expected' => 'array',
						'got'      => gettype( $value ),
					);
				}
				break;

			case 'true_false':
				if ( ! is_bool( $value ) && ! is_int( $value ) ) {
					return array(
						'expected' => 'bool|int',
						'got'      => gettype( $value ),
					);
				}
				break;
		}

		return null;
	}

	// =========================================================================
	// H1.3 — Render Test
	// =========================================================================

	/**
	 * Test rendering each block after save.
	 *
	 * Parses the saved post content, renders each block in an output buffer,
	 * and catches any Throwable errors. If any block fails to render, the
	 * post is deleted (rollback) and a 422 error is returned.
	 *
	 * @param int $post_id The saved post ID.
	 * @return true|WP_Error True if all blocks render OK, WP_Error on failure.
	 */
	public function render_test( $post_id ) {
		if ( ! function_exists( 'render_block' ) ) {
			return true; // Graceful skip (CLI, etc.).
		}

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return true;
		}

		$blocks        = parse_blocks( $post->post_content );
		$render_errors = array();

		foreach ( $blocks as $block_index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			ob_start();
			try {
				render_block( $block );
			} catch ( \Throwable $e ) {
				ob_end_clean();
				$render_errors[] = array(
					'block_index' => $block_index,
					'block_type'  => $block['blockName'],
					'error'       => $e->getMessage(),
				);
				continue;
			}
			ob_end_clean();
		}

		if ( ! empty( $render_errors ) ) {
			// Rollback: delete the post entirely.
			wp_delete_post( $post_id, true );

			// Build descriptive message listing each failed block.
			$details = array();
			foreach ( $render_errors as $err ) {
				$details[] = sprintf(
					'Block #%d (%s): %s',
					$err['block_index'],
					$err['block_type'],
					$err['error']
				);
			}
			$message = sprintf(
				'Block render test failed — %d block(s) threw errors. Post has been rolled back. %s',
				count( $render_errors ),
				implode( ' | ', $details )
			);

			return new WP_Error(
				'render_test_failed',
				$message,
				array(
					'status' => 422,
					'errors' => $render_errors,
				)
			);
		}

		return true;
	}
}
