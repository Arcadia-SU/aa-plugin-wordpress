<?php
/**
 * ACF fields API handlers.
 *
 * Provides discovery of ACF field groups per post type and
 * writing ACF field values via update_field().
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_ACF_Fields_Handler
 *
 * Provides methods for ACF field discovery and write operations.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_ACF_Fields_Handler {

	/**
	 * Get ACF field groups organized by post type.
	 *
	 * Queries all ACF field groups, filters those with location rules
	 * targeting specific post types, and returns their fields.
	 *
	 * @return array Associative array keyed by post type, each containing field groups.
	 */
	private function get_acf_field_groups_for_post_types() {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$all_groups = acf_get_field_groups();
		if ( ! is_array( $all_groups ) ) {
			return array();
		}

		$result = array();

		foreach ( $all_groups as $group ) {
			$post_types = $this->extract_post_types_from_location( $group );

			if ( empty( $post_types ) ) {
				continue;
			}

			$acf_fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $acf_fields ) ) {
				continue;
			}

			$fields = array();
			foreach ( $acf_fields as $acf_field ) {
				$field_descriptor = array(
					'name'     => $acf_field['name'],
					'type'     => $acf_field['type'],
					'required' => ! empty( $acf_field['required'] ),
					'label'    => $acf_field['label'] ?? $acf_field['name'],
				);

				// Add choices for select/radio/checkbox fields.
				if ( ! empty( $acf_field['choices'] ) && in_array( $acf_field['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
					$field_descriptor['choices'] = array_keys( $acf_field['choices'] );
				}

				// Add sub_fields for repeaters.
				if ( 'repeater' === $acf_field['type'] && ! empty( $acf_field['sub_fields'] ) ) {
					$sub_fields = array();
					foreach ( $acf_field['sub_fields'] as $sub ) {
						$sub_fields[] = array(
							'name' => $sub['name'],
							'type' => $sub['type'],
						);
					}
					$field_descriptor['sub_fields'] = $sub_fields;
				}

				$fields[] = $field_descriptor;
			}

			$group_descriptor = array(
				'title'  => $group['title'],
				'key'    => $group['key'],
				'fields' => $fields,
			);

			foreach ( $post_types as $pt ) {
				if ( ! isset( $result[ $pt ] ) ) {
					$result[ $pt ] = array();
				}
				$result[ $pt ][] = $group_descriptor;
			}
		}

		return $result;
	}

	/**
	 * Extract post types from an ACF field group's location rules.
	 *
	 * ACF location rules are structured as:
	 * [[{param, operator, value}, ...], ...]
	 * Each top-level array is an OR group, inner arrays are AND conditions.
	 * We look for rules where param === 'post_type' and operator === '=='.
	 *
	 * @param array $group The ACF field group.
	 * @return array List of post type slugs.
	 */
	private function extract_post_types_from_location( $group ) {
		$post_types = array();

		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return $post_types;
		}

		foreach ( $group['location'] as $or_group ) {
			if ( ! is_array( $or_group ) ) {
				continue;
			}
			foreach ( $or_group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				if (
					isset( $rule['param'], $rule['operator'], $rule['value'] )
					&& 'post_type' === $rule['param']
					&& '==' === $rule['operator']
				) {
					$post_types[] = $rule['value'];
				}
			}
		}

		return array_unique( $post_types );
	}

	/**
	 * Process ACF fields for a post.
	 *
	 * Iterates over the acf_fields payload, applies type-specific
	 * transformations, and calls update_field() for each.
	 *
	 * @param int    $post_id      The post ID.
	 * @param array  $acf_fields   Associative array of field_name => value.
	 * @param string $post_type    The post type slug.
	 * @param string $post_content The rendered post_content (for wysiwyg fallback).
	 * @return true|WP_Error True on success, WP_Error if ACF unavailable.
	 */
	private function process_acf_fields( $post_id, $acf_fields, $post_type, $post_content ) {
		if ( ! function_exists( 'update_field' ) ) {
			return new \WP_Error(
				'acf_unavailable',
				__( 'ACF is required to process acf_fields.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$type_map = $this->build_acf_field_type_map( $post_type );

		foreach ( $acf_fields as $field_name => $value ) {
			$field_type = $type_map[ $field_name ] ?? 'text';

			switch ( $field_type ) {
				case 'wysiwyg':
					if ( null === $value ) {
						// Copy rendered post_content into this wysiwyg field.
						$value = $post_content;
					}
					break;

				case 'image':
					if ( is_string( $value ) && ! empty( $value ) ) {
						$sideloaded = Arcadia_ACF_Adapter::sideload_image_field( $value, $post_id );
						if ( is_wp_error( $sideloaded ) ) {
							return $sideloaded;
						}
						$value = $sideloaded;
					} elseif ( is_array( $value ) && ! empty( $value['url'] ) ) {
						$sideloaded = Arcadia_ACF_Adapter::sideload_image_field(
							$value['url'],
							$post_id,
							$value['title'] ?? null,
							$value['alt'] ?? ''
						);
						if ( is_wp_error( $sideloaded ) ) {
							return $sideloaded;
						}
						$value = $sideloaded;
					}
					break;

				// Repeater, text, textarea, url, select, radio, etc.: passthrough.
			}

			update_field( $field_name, $value, $post_id );
		}

		return true;
	}

	/**
	 * Auto-populate ACF fields when no explicit acf_fields payload was provided.
	 *
	 * Safety net: creates ACF field references so get_fields() returns an
	 * associative array (not false), preventing fatal errors in themes that
	 * don't guard against false.
	 *
	 * Does NOT inject content — that is the responsibility of the explicit
	 * acf_fields mapping sent by the agent platform. This method only sets
	 * safe empty defaults and obvious metadata (title, featured image).
	 *
	 * @param int    $post_id   The post ID.
	 * @param string $post_type The post type slug.
	 */
	private function auto_populate_acf_fields( $post_id, $post_type ) {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		$type_map = $this->build_acf_field_type_map( $post_type );

		if ( empty( $type_map ) ) {
			return;
		}

		$post = get_post( $post_id );

		foreach ( $type_map as $field_name => $field_type ) {
			switch ( $field_type ) {
				case 'wysiwyg':
				case 'textarea':
					// Empty string — just creates the ACF reference.
					// Actual content is injected via explicit acf_fields mapping.
					update_field( $field_name, '', $post_id );
					break;

				case 'text':
					// If field name suggests a title, use post_title.
					if ( $post && preg_match( '/title|titre/i', $field_name ) ) {
						update_field( $field_name, $post->post_title, $post_id );
					}
					break;

				case 'image':
					// Use featured image if one was set.
					$thumbnail_id = get_post_thumbnail_id( $post_id );
					if ( $thumbnail_id ) {
						update_field( $field_name, $thumbnail_id, $post_id );
					}
					break;

				// Other types (select, repeater, etc.): skip.
				// We don't know what value to use, and setting empty
				// could break validation. ACF will use defaults.
			}
		}
	}

	/**
	 * Build a map of field_name to field_type for a given post type.
	 *
	 * Queries ACF for all field groups assigned to the post type,
	 * then collects each field's name and type.
	 *
	 * @param string $post_type The post type slug.
	 * @return array Associative array of field_name => field_type.
	 */
	private function build_acf_field_type_map( $post_type ) {
		$map = array();

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $map;
		}

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );

		if ( ! is_array( $groups ) ) {
			return $map;
		}

		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$map[ $field['name'] ] = $field['type'];
			}
		}

		return $map;
	}
}
