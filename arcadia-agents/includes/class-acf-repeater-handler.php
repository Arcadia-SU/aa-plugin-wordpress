<?php
/**
 * ACF Pro flat-keys repeater expansion (Phase 27).
 *
 * The plugin's GET endpoints return ACF Pro repeaters in flat block-comment
 * shape (`<field>: <count>`, `<field>_<n>_<sub>: <value>`). Agents that
 * round-trip GET → PUT send the same shape, but the validator/adapter
 * pipeline expects array-of-rows. This handler detects the flat pattern and
 * collapses it back to arrays before validation runs.
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
 * Class Arcadia_ACF_Repeater_Handler
 *
 * Detects flat-keys repeaters in block properties and rewrites them to
 * canonical array-of-rows shape, recursing into nested repeaters.
 */
class Arcadia_ACF_Repeater_Handler {

	/**
	 * Expand flat-keys repeater shape into array-of-rows.
	 *
	 * Detection is structural: integer count + indexed sub-keys = repeater.
	 * The schema is consulted only to scope the lookup to declared `repeater`
	 * fields, avoiding false positives on ordinary integer fields.
	 *
	 * @param array &$properties Block properties (mutated: flat keys consumed, rows materialized).
	 * @param array  $schema     Field schema list with optional `sub_fields`.
	 */
	public function expand_flat_repeaters( &$properties, $schema ) {
		if ( ! is_array( $schema ) ) {
			return;
		}

		foreach ( $schema as $field ) {
			if ( ! is_array( $field ) || ( $field['type'] ?? '' ) !== 'repeater' ) {
				continue;
			}
			$name = $field['name'] ?? null;
			if ( null === $name || ! array_key_exists( $name, $properties ) ) {
				continue;
			}

			$value = $properties[ $name ];

			// Already array-of-rows: leave it (backward compat).
			if ( is_array( $value ) ) {
				continue;
			}

			// Anything other than a non-negative int is not a flat-count — let
			// type validation flag it.
			if ( ! is_int( $value ) || $value < 0 ) {
				continue;
			}

			if ( 0 === $value ) {
				$properties[ $name ] = array();
				$this->strip_field_key_refs( $properties, $name );
				continue;
			}

			if ( ! $this->has_indexed_subkeys( $properties, $name, $value ) ) {
				continue; // Plain int field that happens to be > 0; no flat siblings.
			}

			$rows                = $this->collapse_flat_to_rows( $properties, $name, $value );
			$properties[ $name ] = $rows;
			$this->strip_field_key_refs( $properties, $name );

			// Recurse into nested repeaters using the row's sub_fields schema.
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				foreach ( $properties[ $name ] as &$row ) {
					if ( is_array( $row ) ) {
						$this->expand_flat_repeaters( $row, $field['sub_fields'] );
					}
				}
				unset( $row );
			}
		}
	}

	/**
	 * Detect whether $props contains at least one `<field>_<n>_<sub>` key for n in [0, count).
	 *
	 * @param array  $props Properties.
	 * @param string $field Field name.
	 * @param int    $count Expected row count.
	 * @return bool
	 */
	private function has_indexed_subkeys( $props, $field, $count ) {
		if ( $count < 1 ) {
			return false;
		}
		$prefix = $field . '_';
		foreach ( $props as $key => $_value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			$rest = substr( $key, strlen( $prefix ) );
			if ( ! preg_match( '/^(\d+)_/', $rest, $m ) ) {
				continue;
			}
			$n = (int) $m[1];
			if ( $n >= 0 && $n < $count ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collapse flat `<field>_<n>_<sub>` keys into an array of $count rows.
	 *
	 * Consumed flat keys are removed from $props. Nested repeaters within rows
	 * remain as flat sub-keys at this stage and are expanded by the recursive
	 * call in {@see expand_flat_repeaters()}.
	 *
	 * @param array &$props Properties (mutated).
	 * @param string $field Field name.
	 * @param int    $count Row count.
	 * @return array Indexed array of associative rows.
	 */
	private function collapse_flat_to_rows( &$props, $field, $count ) {
		$rows     = array_fill( 0, $count, array() );
		$prefix   = $field . '_';
		$consumed = array();

		foreach ( $props as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			$rest = substr( $key, strlen( $prefix ) );
			if ( ! preg_match( '/^(\d+)_(.+)$/', $rest, $m ) ) {
				continue;
			}
			$n   = (int) $m[1];
			$sub = $m[2];
			if ( $n < 0 || $n >= $count ) {
				continue;
			}
			$rows[ $n ][ $sub ] = $value;
			$consumed[]         = $key;
		}

		foreach ( $consumed as $k ) {
			unset( $props[ $k ] );
		}

		return $rows;
	}

	/**
	 * Strip ACF field-key references (`_<field>`, `_<field>_<n>_<sub>`) from $props.
	 *
	 * The adapter re-injects these from the schema, so any agent-supplied copies
	 * would either duplicate or shadow the canonical keys.
	 *
	 * @param array  &$props Properties (mutated).
	 * @param string  $field Field name.
	 */
	private function strip_field_key_refs( &$props, $field ) {
		unset( $props[ '_' . $field ] );
		$prefix = '_' . $field . '_';
		foreach ( array_keys( $props ) as $k ) {
			if ( is_string( $k ) && str_starts_with( $k, $prefix ) ) {
				unset( $props[ $k ] );
			}
		}
	}
}
