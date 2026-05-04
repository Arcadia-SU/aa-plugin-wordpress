<?php
/**
 * ACF canonical type coercion.
 *
 * ACF Pro stores meta values as LONGTEXT, so a GET round-trip surfaces
 * `true_false` as `"1"`/`"0"`, image IDs as numeric strings, etc. This
 * coercer rewrites schema-declared properties to their canonical PHP
 * types so identity-passthrough GET → PUT round-trips succeed without
 * manual casting on the agent side.
 *
 * Stateless. Caller instantiates and calls — no singleton needed.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_ACF_Coercer
 *
 * Pure-function coercion + type checking for ACF block properties.
 */
class Arcadia_ACF_Coercer {

	/**
	 * Coerce all schema-declared properties to their canonical PHP types.
	 *
	 * Walks the schema, finds each declared field in `$properties`, and
	 * rewrites the value to its canonical form. Repeaters recurse into rows
	 * using the field's `sub_fields` schema. Non-coercible values (e.g.
	 * `"banana"` for a `number` field) are left untouched so
	 * {@see check_field_type()} can flag them with full detail.
	 *
	 * @param array &$properties Block properties (mutated).
	 * @param array  $schema     Field schema list.
	 */
	public function coerce_properties_to_canonical( &$properties, $schema ) {
		if ( ! is_array( $schema ) ) {
			return;
		}

		foreach ( $schema as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = $field['name'] ?? null;
			$type = $field['type'] ?? null;
			if ( null === $name || null === $type ) {
				continue;
			}
			if ( ! array_key_exists( $name, $properties ) ) {
				continue;
			}

			if ( 'repeater' === $type ) {
				if ( is_array( $properties[ $name ] ) && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
					foreach ( $properties[ $name ] as &$row ) {
						if ( is_array( $row ) ) {
							$this->coerce_properties_to_canonical( $row, $field['sub_fields'] );
						}
					}
					unset( $row );
				}
				continue;
			}

			$properties[ $name ] = $this->coerce_field_to_canonical( $properties[ $name ], $type );
		}
	}

	/**
	 * Return the canonical PHP value for a single ACF field value.
	 *
	 * Coercion rules per ACF type:
	 * - true_false: bool ("0"/""/"false" → false ; "1"/"true" → true)
	 * - image / file: int (numeric string → int ; ""/null → 0)
	 * - gallery: array<int> (each element coerced through the image rule)
	 * - number: int|float (numeric string → int if integral, else float)
	 * - text/textarea/wysiwyg/url/email/select/radio: string (cast int/float)
	 * - relationship/post_object: int|array<int> (numeric strings → ints)
	 * - link: passthrough
	 *
	 * Anything that cannot be coerced is returned unchanged so downstream
	 * type validation can surface a precise error.
	 *
	 * @param mixed  $value      The field value.
	 * @param string $field_type ACF field type.
	 * @return mixed Canonical value (or unchanged if non-coercible).
	 */
	public function coerce_field_to_canonical( $value, $field_type ) {
		switch ( $field_type ) {
			case 'true_false':
				if ( is_bool( $value ) || is_int( $value ) ) {
					return $value;
				}
				if ( null === $value ) {
					return false;
				}
				if ( is_string( $value ) ) {
					$lower = strtolower( $value );
					if ( '' === $lower || '0' === $lower || 'false' === $lower ) {
						return false;
					}
					if ( '1' === $lower || 'true' === $lower ) {
						return true;
					}
				}
				return $value;

			case 'image':
			case 'file':
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( null === $value ) {
					return 0;
				}
				if ( is_string( $value ) ) {
					if ( '' === $value ) {
						return 0;
					}
					if ( ctype_digit( $value ) ) {
						return (int) $value;
					}
				}
				// URLs (non-numeric strings) and image objects (arrays) are
				// handled by the H1.2 sideload step. Pass through unchanged.
				return $value;

			case 'gallery':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$coerced = array();
				foreach ( $value as $item ) {
					$coerced[] = $this->coerce_field_to_canonical( $item, 'image' );
				}
				return $coerced;

			case 'number':
				if ( is_int( $value ) || is_float( $value ) ) {
					return $value;
				}
				if ( is_string( $value ) && is_numeric( $value ) ) {
					$float_val = (float) $value;
					$int_val   = (int) $value;
					if ( (float) $int_val === $float_val ) {
						return $int_val;
					}
					return $float_val;
				}
				return $value;

			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'url':
			case 'email':
			case 'select':
			case 'radio':
				if ( is_string( $value ) ) {
					return $value;
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return (string) $value;
				}
				return $value;

			case 'relationship':
			case 'post_object':
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( is_string( $value ) && ctype_digit( $value ) ) {
					return (int) $value;
				}
				if ( is_array( $value ) ) {
					$coerced = array();
					foreach ( $value as $item ) {
						if ( is_int( $item ) ) {
							$coerced[] = $item;
						} elseif ( is_string( $item ) && ctype_digit( $item ) ) {
							$coerced[] = (int) $item;
						} else {
							$coerced[] = $item;
						}
					}
					return $coerced;
				}
				return $value;

			default:
				return $value;
		}
	}

	/**
	 * Check a value against an expected ACF field type.
	 *
	 * @param mixed  $value         The field value.
	 * @param string $expected_type The ACF field type.
	 * @return array|null Error descriptor ('expected', 'got', 'suggestion') or null if valid.
	 */
	public function check_field_type( $value, $expected_type ) {
		switch ( $expected_type ) {
			case 'image':
				// 0 is valid: means "no image".
				if ( 0 === $value ) {
					break;
				}
				if ( ! is_int( $value ) || $value < 0 ) {
					return array(
						'expected'   => 'int (attachment ID) or 0 (no image)',
						'got'        => gettype( $value ) . ( is_string( $value ) ? ' (URL)' : '' ),
						'suggestion' => 'Upload via POST /media first, or use 0 for no image.',
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
}
