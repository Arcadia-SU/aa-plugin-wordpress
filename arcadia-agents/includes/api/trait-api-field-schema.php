<?php
/**
 * Field schema API handlers.
 *
 * Handles GET/PUT /field-schema for calibration field mapping.
 *
 * @package ArcadiaAgents
 * @since   0.1.2
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Field_Schema_Handler
 *
 * Provides methods for field schema discovery and persistence.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Field_Schema_Handler {

	/**
	 * WP option key for stored field schema mappings.
	 *
	 * @var string
	 */
	private static $field_schema_option = 'aa_field_schema';

	/**
	 * The semantic sources a `type: mapping` entry may draw its value from.
	 *
	 * Single source of truth, read by BOTH ends of the feature: PUT
	 * /field-schema validates against it, and apply_field_schema_mappings()
	 * builds its value map from exactly these keys. Adding a source means
	 * adding it in both places in the same commit — a test asserts the two
	 * agree, so the drift that let unknown sources be stored and then ignored
	 * cannot come back (Phase 42.4).
	 *
	 * A method rather than a constant: constants in traits require PHP 8.2 and
	 * this plugin supports 8.0. A static property would be publicly mutable.
	 *
	 * @return string[]
	 */
	public static function mapping_sources() {
		return array(
			'excerpt',
			'h1',
			'meta_title',
			'meta_description',
			'featured_image_url',
		);
	}

	/**
	 * Get field schema for all post types.
	 *
	 * Returns ACF field groups per post type with their fields and
	 * any stored semantic mappings from calibration.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_field_schema( $request ) {
		$schema  = array();
		$stored  = get_option( self::$field_schema_option, array() );

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return new WP_REST_Response( $schema, 200 );
		}

		// Filter by post_type if provided, otherwise return all.
		$filter_post_type = $request->get_param( 'post_type' );

		// Get all public post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$excluded   = array( 'attachment' );

		foreach ( $post_types as $type ) {
			if ( in_array( $type->name, $excluded, true ) ) {
				continue;
			}

			// Skip if filtering by post_type and this isn't the requested one.
			if ( $filter_post_type && $type->name !== $filter_post_type ) {
				continue;
			}

			$groups = acf_get_field_groups( array( 'post_type' => $type->name ) );
			if ( empty( $groups ) ) {
				continue;
			}

			$fields = array();
			foreach ( $groups as $group ) {
				$group_key    = isset( $group['key'] ) ? $group['key'] : '';
				$group_fields = acf_get_fields( $group_key );

				if ( ! is_array( $group_fields ) ) {
					continue;
				}

				foreach ( $group_fields as $field ) {
					$name     = isset( $field['name'] ) ? $field['name'] : '';
					$semantic = null;

					// Lookup stored semantic mapping.
					if ( isset( $stored[ $type->name ][ $name ] ) ) {
						$semantic = $stored[ $type->name ][ $name ];
					}

					$fields[] = array(
						'name'     => $name,
						'type'     => isset( $field['type'] ) ? $field['type'] : 'text',
						'label'    => isset( $field['label'] ) ? $field['label'] : $name,
						'semantic' => $semantic,
					);
				}
			}

			if ( ! empty( $fields ) ) {
				$schema[ $type->name ] = array( 'fields' => $fields );
			}
		}

		return new WP_REST_Response( $schema, 200 );
	}

	/**
	 * Update field schema mappings (partial patch).
	 *
	 * Merges incoming mappings with existing stored schema.
	 * Unmentioned fields remain unchanged.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_field_schema( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Request body must be a JSON object with post type keys.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Validate structure: { "post_type": { "field_name": { "type": "mapping"|"generation", ... } } }
		// A null value is the de-calibration verb, not a mapping — see below.
		$allowed_types = array( 'mapping', 'generation' );

		foreach ( $body as $post_type => $fields ) {
			if ( ! is_array( $fields ) ) {
				return new WP_Error(
					'invalid_payload',
					sprintf(
						/* translators: %s: post type name */
						__( "Value for post type '%s' must be an object.", 'arcadia-agents' ),
						$post_type
					),
					array( 'status' => 400 )
				);
			}

			foreach ( $fields as $field_name => $mapping ) {
				// null = remove this field's calibration. The read side already
				// treats an empty mapping as "not calibrated" (apply_field_schema_
				// mappings), so this only adds the missing write verb — without it
				// the PUT is purely additive and a calibration can never be undone.
				if ( null === $mapping ) {
					continue;
				}

				if ( ! is_array( $mapping ) || empty( $mapping['type'] ) ) {
					return new WP_Error(
						'invalid_mapping',
						sprintf(
							/* translators: 1: field name, 2: post type */
							__( "Mapping for field '%1\$s' in '%2\$s' must include a 'type' key.", 'arcadia-agents' ),
							$field_name,
							$post_type
						),
						array( 'status' => 400 )
					);
				}

				if ( ! in_array( $mapping['type'], $allowed_types, true ) ) {
					return new WP_Error(
						'invalid_mapping_type',
						sprintf(
							/* translators: 1: received type, 2: allowed types */
							__( "Invalid mapping type '%1\$s'. Allowed: %2\$s.", 'arcadia-agents' ),
							$mapping['type'],
							implode( ', ', $allowed_types )
						),
						array( 'status' => 400 )
					);
				}

				// An unknown `source` used to be accepted, stored, then ignored in
				// silence at write time: the calibration agent believed it had
				// wired a field, nothing happened, and nothing said so (Phase 42.4).
				// Validated against the same list the write side reads, so the two
				// can never drift as sources are added.
				if ( 'mapping' === $mapping['type']
					&& ! empty( $mapping['source'] )
					&& ! in_array( $mapping['source'], self::mapping_sources(), true )
				) {
					return new WP_Error(
						'invalid_mapping_source',
						sprintf(
							/* translators: 1: received source, 2: field name, 3: allowed sources */
							__( "Unknown mapping source '%1\$s' for field '%2\$s'. Allowed: %3\$s.", 'arcadia-agents' ),
							$mapping['source'],
							$field_name,
							implode( ', ', self::mapping_sources() )
						),
						array(
							'status'          => 400,
							'allowed_sources' => self::mapping_sources(),
						)
					);
				}
			}
		}

		// Merge with existing schema (partial patch).
		$stored = get_option( self::$field_schema_option, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $body as $post_type => $fields ) {
			if ( ! isset( $stored[ $post_type ] ) ) {
				$stored[ $post_type ] = array();
			}
			foreach ( $fields as $field_name => $mapping ) {
				if ( null === $mapping ) {
					unset( $stored[ $post_type ][ $field_name ] );
					continue;
				}
				$stored[ $post_type ][ $field_name ] = $mapping;
			}
		}

		update_option( self::$field_schema_option, $stored );

		return new WP_REST_Response(
			array(
				'success' => true,
				'schema'  => $stored,
			),
			200
		);
	}

	/**
	 * Apply field schema mappings to a post after creation/update (FS-4).
	 *
	 * Reads the stored schema and writes mapped values via update_field().
	 *
	 * @param int    $post_id   The post ID.
	 * @param string $post_type The post type.
	 * @param array  $body      The original request body.
	 * @param array  $meta      The meta array from the request.
	 */
	public function apply_field_schema_mappings( $post_id, $post_type, $body, $meta ) {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		$stored = get_option( self::$field_schema_option, array() );
		if ( empty( $stored[ $post_type ] ) || ! is_array( $stored[ $post_type ] ) ) {
			return;
		}

		// Fields already handled by process_acf_fields() — don't overwrite.
		// process_acf_fields() handles type-specific logic (image sideload,
		// wysiwyg markdown parse, etc.) that we must not clobber with raw values.
		$explicit_acf = ! empty( $body['acf_fields'] ) ? array_keys( $body['acf_fields'] ) : array();

		// ACF field type map — needed to handle type-specific values (e.g. image sideload).
		$acf_type_map = $this->build_acf_field_type_map( $post_type );

		// Build source values map. Its keys MUST be exactly self::mapping_sources() —
		// asserted by a test, because a source added here without being declared
		// there would be rejected at PUT time and unreachable, while one declared
		// there without a value here would be accepted and then silently ignored:
		// the very defect Phase 42.4 closes.
		$sources = array(
			'excerpt'            => isset( $body['excerpt'] ) ? $body['excerpt'] : ( isset( $meta['description'] ) ? $meta['description'] : '' ),
			'h1'                 => isset( $body['title'] ) ? $body['title'] : '',
			'meta_title'         => isset( $meta['title'] ) ? $meta['title'] : '',
			'meta_description'   => isset( $meta['description'] ) ? $meta['description'] : '',
			'featured_image_url' => isset( $meta['featured_image_url'] ) ? $meta['featured_image_url'] : '',
		);

		foreach ( $stored[ $post_type ] as $field_name => $mapping ) {
			// Skip fields explicitly set via acf_fields — already processed
			// with proper type handling (sideload for images, etc.).
			if ( in_array( $field_name, $explicit_acf, true ) ) {
				continue;
			}
			// Skip null semantic (not calibrated).
			if ( empty( $mapping ) || ! is_array( $mapping ) ) {
				continue;
			}

			$type = isset( $mapping['type'] ) ? $mapping['type'] : '';

			if ( 'mapping' === $type && ! empty( $mapping['source'] ) ) {
				$source_key = $mapping['source'];
				if ( isset( $sources[ $source_key ] ) && '' !== $sources[ $source_key ] ) {
					$value = $sources[ $source_key ];

					// Type-aware: ACF image fields expect an attachment ID, not a URL.
					// Sideload the URL first, same as process_acf_fields() does.
					$acf_field_type = $acf_type_map[ $field_name ] ?? 'text';
					if ( 'image' === $acf_field_type && is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
						$sideloaded = Arcadia_ACF_Adapter::sideload_image_field( $value, $post_id );
						if ( is_wp_error( $sideloaded ) ) {
							// Log warning but don't fail the whole request. Sanitize the
							// agent-supplied field name first so it can't inject forged
							// log lines (CR/LF / control chars) into the PHP error log.
							error_log( sprintf(
								'[ArcadiaAgents] field_schema sideload failed for %s: %s',
								sanitize_key( $field_name ),
								$sideloaded->get_error_message()
							) );
							continue;
						}
						$value = $sideloaded;
					}

					update_field( $field_name, $value, $post_id );
				}
			}
			// 'generation' type: the agent passes the value in acf_fields directly.
			// No action needed here — handled by process_acf_fields().
		}
	}
}
