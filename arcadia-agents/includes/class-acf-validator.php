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
	 * Attachment IDs created by H1.2 sideload during validation.
	 *
	 * @var int[]
	 */
	private $sideloaded_ids = array();

	/**
	 * Whether current validation is dry-run (no side-effects).
	 *
	 * @var bool
	 */
	private $dry_run = false;

	/**
	 * Coercer for canonical type rewriting + per-field type checks.
	 *
	 * @var Arcadia_ACF_Coercer
	 */
	private $coercer;

	/**
	 * Repeater handler for flat-keys → array-of-rows expansion.
	 *
	 * @var Arcadia_ACF_Repeater_Handler
	 */
	private $repeater_handler;

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
	private function __construct() {
		$this->coercer          = new Arcadia_ACF_Coercer();
		$this->repeater_handler = new Arcadia_ACF_Repeater_Handler();
	}

	/**
	 * Get and clear the list of sideloaded attachment IDs.
	 *
	 * Called after wp_insert_post/wp_update_post to re-attach orphaned
	 * attachments (sideloaded with post_parent=0 at validation time).
	 *
	 * @return int[] Attachment IDs sideloaded during the last validate_and_preprocess() call.
	 */
	public function get_and_clear_sideloaded_ids() {
		$ids                  = $this->sideloaded_ids;
		$this->sideloaded_ids = array();
		return $ids;
	}

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
	 * @param array  &$blocks_json Block content structure (mutated: URLs → IDs unless dry_run).
	 * @param string $post_type    Target post type (reserved for future use).
	 * @param bool   $dry_run      If true, skip sideload — validate only, no side-effects.
	 * @return true|WP_Error True if valid, WP_Error with 422 + structured errors if not.
	 */
	public function validate_and_preprocess( &$blocks_json, $post_type = 'post', $dry_run = false ) {
		$errors   = array();
		$registry = Arcadia_Block_Registry::get_instance();

		// Reset sideloaded IDs for this validation pass.
		$this->sideloaded_ids = array();
		$this->dry_run        = $dry_run;

		if ( ! empty( $blocks_json['children'] ) && is_array( $blocks_json['children'] ) ) {
			foreach ( $blocks_json['children'] as $index => &$block ) {
				$this->validate_block_recursive( $block, $index, $registry, $errors, $post_type );
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
	 * @param array                  &$block    Block data (mutated for sideloaded images).
	 * @param int                     $index     Block index in parent's children array.
	 * @param Arcadia_Block_Registry  $registry  Block registry instance.
	 * @param array                  &$errors    Collected errors (appended to).
	 * @param string                  $post_type Target post type for availability check.
	 */
	private function validate_block_recursive( &$block, $index, $registry, &$errors, $post_type = 'post' ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return;
		}

		$block_type = $block['type'];

		// Only validate ACF custom blocks (type starts with 'acf/').
		if ( str_contains( $block_type, '/' ) && str_starts_with( $block_type, 'acf/' ) ) {
			// H1.1: Check block availability for the target post type.
			if ( ! $this->is_block_available_for_post_type( $block_type, $post_type ) ) {
				$errors[] = array(
					'block_index' => $index,
					'block_type'  => $block_type,
					'field'       => '',
					'expected'    => sprintf( 'block available for post_type "%s"', $post_type ),
					'got'         => 'block not registered for this post_type',
					'suggestion'  => sprintf(
						'Block "%s" is not available for post_type "%s". Check ACF field group location rules.',
						$block_type,
						$post_type
					),
				);
				return; // Skip field validation for unavailable block.
			}

			$schema = $registry->get_block_schema( $block_type );

			if ( is_array( $schema ) && ! empty( $schema ) ) {
				$this->validate_acf_block( $block, $index, $schema, $errors );
			}
		}

		// Recurse into children.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child_index => &$child ) {
				$this->validate_block_recursive( $child, $child_index, $registry, $errors, $post_type );
			}
			unset( $child );
		}
	}

	/**
	 * Check if an ACF block is available for a given post type.
	 *
	 * @param string $block_name Full block name (e.g. 'acf/hero').
	 * @param string $post_type  Target post type slug.
	 * @return bool True if the block is available for this post type.
	 */
	private function is_block_available_for_post_type( $block_name, $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return true;
		}

		$groups = acf_get_field_groups( array( 'block' => $block_name ) );

		if ( empty( $groups ) ) {
			return true; // No field groups targeting this block — no restriction.
		}

		foreach ( $groups as $group ) {
			if ( $this->field_group_matches_post_type( $group, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a field group's location rules match a given post type.
	 *
	 * @param array  $group     ACF field group with location rules.
	 * @param string $post_type Target post type slug.
	 * @return bool True if the group matches.
	 */
	private function field_group_matches_post_type( $group, $post_type ) {
		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return true;
		}

		foreach ( $group['location'] as $or_group ) {
			if ( ! is_array( $or_group ) ) {
				continue;
			}

			$has_post_type_rule = false;
			$post_type_matches  = false;

			foreach ( $or_group as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['param'] ) ) {
					continue;
				}
				if ( 'post_type' === $rule['param'] ) {
					$has_post_type_rule = true;
					if ( '==' === ( $rule['operator'] ?? '' ) && $post_type === $rule['value'] ) {
						$post_type_matches = true;
					}
				}
			}

			if ( ! $has_post_type_rule ) {
				return true; // Block-only rule — available for all post types.
			}

			if ( $post_type_matches ) {
				return true;
			}
		}

		return false;
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

		// Expand GET-shape flat-keys repeaters (`<field>: N`, `<field>_<n>_<sub>: ...`) → array
		// of rows, so downstream type validation sees the expected `array` shape and the ACF
		// adapter's flatten_repeater() can re-emit clean block-comment storage.
		if ( isset( $block['properties'] ) && is_array( $block['properties'] ) ) {
			$this->repeater_handler->expand_flat_repeaters( $block['properties'], $schema );
		}

		// Coerce GET-shape scalar values (`"1"`, `"42"`, etc. — ACF Pro raw storage) to canonical
		// types before sideload + validation. This makes GET → store → PUT round-trips succeed
		// without manual casting on the agent side. Mutates in place; what gets saved is the
		// canonical type, so future GETs return canonical values too (self-heals).
		if ( isset( $block['properties'] ) && is_array( $block['properties'] ) ) {
			$this->coercer->coerce_properties_to_canonical( $block['properties'], $schema );
		}

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

		// ---- H1.2: Image URL auto-sideload (string URL or object with metadata) ----
		foreach ( $field_types as $field_name => $field_type ) {
			if ( 'image' !== $field_type ) {
				continue;
			}
			if ( ! array_key_exists( $field_name, $properties ) ) {
				continue;
			}

			$value = $properties[ $field_name ];

			// Empty values → no image. Normalize to 0 and skip sideload.
			if ( empty( $value ) || 0 === $value || '0' === $value ) {
				$block['properties'][ $field_name ] = 0;
				$properties[ $field_name ]          = 0;
				continue;
			}

			$url   = null;
			$title = null;
			$alt   = '';

			if ( is_string( $value ) ) {
				$url = $value;
			} elseif ( is_array( $value ) && ! empty( $value['url'] ) ) {
				$url   = $value['url'];
				$title = $value['title'] ?? null;
				$alt   = $value['alt'] ?? '';
			} else {
				continue; // int or unrecognized — skip sideload, let type validation handle.
			}

			// Dry-run: accept URL/object as valid image format, skip actual sideload.
			if ( $this->dry_run ) {
				// Mark as sideloaded so type validation doesn't reject the URL.
				$sideload_failed[] = $field_name;
				continue;
			}

			$attachment_id = Arcadia_ACF_Adapter::sideload_image_field( $url, 0, $title, $alt );

			if ( is_wp_error( $attachment_id ) ) {
				$sideload_failed[] = $field_name;
				$got_desc          = is_string( $value ) ? 'string (URL)' : 'object (URL + metadata)';
				$errors[]          = array(
					'block_index' => $index,
					'block_type'  => $block_type,
					'field'       => $field_name,
					'expected'    => 'int (attachment ID)',
					'got'         => $got_desc,
					'suggestion'  => 'Image sideload failed: ' . $attachment_id->get_error_message() . '. Upload via POST /media first.',
				);
			} else {
				// Mutate block data: replace URL/object with attachment ID.
				$block['properties'][ $field_name ] = $attachment_id;
				$properties[ $field_name ]          = $attachment_id;
				$this->sideloaded_ids[]             = $attachment_id;
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

			$type_error = $this->coercer->check_field_type( $value, $expected_type );
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
