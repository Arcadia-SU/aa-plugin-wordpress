<?php
/**
 * Block registry for discovering and validating block types.
 *
 * Centralizes knowledge of builtin blocks (paragraph, heading, image, list)
 * and dynamically discovers custom blocks via ACF/Gutenberg introspection.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Block_Registry
 *
 * Singleton that provides block type discovery and validation.
 * Lazy-loads introspection data on first access.
 */
class Arcadia_Block_Registry {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Block_Registry|null
	 */
	private static $instance = null;

	/**
	 * Cached custom blocks data.
	 *
	 * @var array|null
	 */
	private $custom_blocks_cache = null;

	/**
	 * Builtin block types (handled by adapters directly).
	 *
	 * @var array
	 */
	private const BUILTIN_BLOCKS = array(
		'paragraph' => array(
			'type'        => 'paragraph',
			'description' => 'Text block',
		),
		'heading'   => array(
			'type'        => 'heading',
			'description' => 'H2/H3 heading',
		),
		'image'     => array(
			'type'        => 'image',
			'description' => 'Single image',
		),
		'list'      => array(
			'type'        => 'list',
			'description' => 'Ordered/unordered list',
		),
	);

	/**
	 * Block types handled by process_block() but not exposed as custom.
	 *
	 * @var array
	 */
	private const INTERNAL_TYPES = array( 'section', 'text' );

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Block_Registry
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
		// Introspection is lazy-loaded.
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Get the list of builtin block types.
	 *
	 * @return array Array of builtin block descriptors.
	 */
	public function get_builtin_blocks() {
		return array_values( self::BUILTIN_BLOCKS );
	}

	/**
	 * Get the list of custom block types available on this site.
	 *
	 * Discovers blocks dynamically via ACF or Gutenberg introspection.
	 *
	 * @return array Array of custom block descriptors with fields/attributes.
	 */
	public function get_custom_blocks() {
		if ( null === $this->custom_blocks_cache ) {
			$this->custom_blocks_cache = $this->discover_custom_blocks();
		}
		return $this->custom_blocks_cache;
	}

	/**
	 * Check if a block type is registered (builtin, internal, or custom).
	 *
	 * @param string $type The block type name.
	 * @return bool
	 */
	public function is_registered( $type ) {
		// Builtin blocks.
		if ( isset( self::BUILTIN_BLOCKS[ $type ] ) ) {
			return true;
		}

		// Internal types (section, text).
		if ( in_array( $type, self::INTERNAL_TYPES, true ) ) {
			return true;
		}

		// Custom blocks.
		$custom = $this->get_custom_blocks();
		foreach ( $custom as $block ) {
			if ( $block['type'] === $type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the field schema for a custom block type.
	 *
	 * Returns null for builtin blocks (they don't have custom fields).
	 *
	 * @param string $type The block type name.
	 * @return array|null Array of field descriptors, or null.
	 */
	public function get_block_schema( $type ) {
		// Builtins and internals don't have custom field schemas.
		if ( isset( self::BUILTIN_BLOCKS[ $type ] ) || in_array( $type, self::INTERNAL_TYPES, true ) ) {
			return null;
		}

		$custom = $this->get_custom_blocks();
		foreach ( $custom as $block ) {
			if ( $block['type'] === $type ) {
				return $block['fields'] ?? array();
			}
		}

		return null;
	}

	/**
	 * Validate properties against a custom block's field schema.
	 *
	 * @param string $type       The block type name.
	 * @param array  $properties The properties to validate.
	 * @return true|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_properties( $type, $properties ) {
		$schema = $this->get_block_schema( $type );

		if ( null === $schema ) {
			// Builtin blocks don't need property validation.
			return true;
		}

		// Check required fields.
		foreach ( $schema as $field ) {
			if ( ! empty( $field['required'] ) && ! isset( $properties[ $field['name'] ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf(
						/* translators: 1: field name, 2: block type */
						__( "Required field '%1\$s' is missing for block type '%2\$s'.", 'arcadia-agents' ),
						$field['name'],
						$type
					),
					array( 'status' => 422 )
				);
			}
		}

		return true;
	}

	/**
	 * Get the list of custom block type names.
	 *
	 * Used for error messages.
	 *
	 * @return array Array of type name strings.
	 */
	public function get_custom_block_names() {
		$custom = $this->get_custom_blocks();
		return array_column( $custom, 'type' );
	}

	// =========================================================================
	// Introspection
	// =========================================================================

	/**
	 * Discover custom blocks via ACF or Gutenberg introspection.
	 *
	 * @return array Array of custom block descriptors.
	 */
	private function discover_custom_blocks() {
		// Try ACF first.
		if ( Arcadia_Blocks::is_acf_available() ) {
			return $this->discover_acf_blocks();
		}

		// Fall back to Gutenberg registry.
		return $this->discover_gutenberg_blocks();
	}

	/**
	 * Discover custom blocks registered via ACF.
	 *
	 * @return array Array of custom block descriptors.
	 */
	private function discover_acf_blocks() {
		$blocks = array();

		if ( ! function_exists( 'acf_get_block_types' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $blocks;
		}

		$acf_blocks = acf_get_block_types();

		// ACF builtin block names that we handle via adapters.
		$skip_blocks = array( 'acf/title', 'acf/text', 'acf/image' );

		foreach ( $acf_blocks as $block_name => $block_config ) {
			if ( in_array( $block_name, $skip_blocks, true ) ) {
				continue;
			}

			// Extract short name from "acf/block-name".
			$short_name = preg_replace( '/^acf\//', '', $block_name );

			$block_descriptor = array(
				'type'  => $short_name,
				'title' => $block_config['title'] ?? $short_name,
			);

			// Get fields from associated field groups.
			$fields = $this->get_acf_block_fields( $block_name );
			if ( ! empty( $fields ) ) {
				$block_descriptor['fields'] = $fields;
			}

			$blocks[] = $block_descriptor;
		}

		return $blocks;
	}

	/**
	 * Get ACF fields associated with a block type.
	 *
	 * @param string $block_name The ACF block name (e.g., 'acf/bouton').
	 * @return array Array of field descriptors.
	 */
	private function get_acf_block_fields( $block_name ) {
		$fields = array();

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $fields;
		}

		// Find field groups that target this block.
		$field_groups = acf_get_field_groups(
			array(
				'block' => $block_name,
			)
		);

		foreach ( $field_groups as $group ) {
			$acf_fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $acf_fields ) ) {
				continue;
			}

			foreach ( $acf_fields as $acf_field ) {
				$field_descriptor = array(
					'name'     => $acf_field['name'],
					'type'     => $acf_field['type'],
					'required' => ! empty( $acf_field['required'] ),
					'label'    => $acf_field['label'] ?? $acf_field['name'],
				);

				// Add choices for select/radio fields.
				if ( ! empty( $acf_field['choices'] ) && in_array( $acf_field['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
					$field_descriptor['choices'] = array_keys( $acf_field['choices'] );
				}

				$fields[] = $field_descriptor;
			}
		}

		return $fields;
	}

	/**
	 * Discover custom blocks registered via Gutenberg (WP Block Type Registry).
	 *
	 * Only includes server-rendered (dynamic) blocks that have declared attributes.
	 *
	 * @return array Array of custom block descriptors.
	 */
	private function discover_gutenberg_blocks() {
		$blocks = array();

		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $blocks;
		}

		$registry    = \WP_Block_Type_Registry::get_instance();
		$all_blocks  = $registry->get_all_registered();

		// Core WP blocks we skip (handled by adapters or not relevant).
		$skip_prefixes = array( 'core/', 'core-embed/' );

		foreach ( $all_blocks as $block_name => $block_type ) {
			// Skip core blocks.
			$skip = false;
			foreach ( $skip_prefixes as $prefix ) {
				if ( str_starts_with( $block_name, $prefix ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			// Only include dynamic blocks (server-rendered, have render_callback).
			if ( empty( $block_type->render_callback ) ) {
				continue;
			}

			$block_descriptor = array(
				'type'  => $block_name,
				'title' => $block_type->title ?? $block_name,
			);

			// Extract attributes as fields.
			if ( ! empty( $block_type->attributes ) ) {
				$fields = array();
				foreach ( $block_type->attributes as $attr_name => $attr_config ) {
					$fields[] = array(
						'name'     => $attr_name,
						'type'     => $attr_config['type'] ?? 'string',
						'required' => false,
						'label'    => $attr_name,
					);
				}
				$block_descriptor['fields'] = $fields;
			}

			$blocks[] = $block_descriptor;
		}

		return $blocks;
	}
}
