<?php
/**
 * Block generation and adapter management.
 *
 * This class converts semantic JSON content (ADR-013 unified block model)
 * to WordPress block content using pluggable adapters.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load adapter interface, implementations, and block processor.
require_once __DIR__ . '/adapters/interface-block-adapter.php';
require_once __DIR__ . '/adapters/class-adapter-gutenberg.php';
require_once __DIR__ . '/adapters/class-adapter-acf.php';
require_once __DIR__ . '/class-block-processor.php';

/**
 * Class Arcadia_Blocks
 *
 * Main class for block generation and adapter management.
 * Processes ADR-013 unified block model (recursive children structure)
 * and delegates rendering to the appropriate adapter.
 */
class Arcadia_Blocks {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Blocks|null
	 */
	private static $instance = null;

	/**
	 * The current adapter.
	 *
	 * @var Arcadia_Block_Adapter
	 */
	private $adapter;

	/**
	 * The block registry.
	 *
	 * @var Arcadia_Block_Registry
	 */
	private $registry;

	/**
	 * Block processor (renders ADR-013 nodes via the active adapter).
	 *
	 * @var Arcadia_Block_Processor
	 */
	private $processor;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Blocks
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
		$this->adapter   = $this->detect_adapter();
		$this->registry  = Arcadia_Block_Registry::get_instance();
		$this->processor = new Arcadia_Block_Processor( $this->adapter, $this->registry );
	}

	/**
	 * Detect which adapter to use based on installed plugins and settings.
	 *
	 * Priority:
	 * 1. User override via option 'arcadia_agents_block_adapter'
	 * 2. Auto-detect: ACF Pro if active and has registered blocks
	 * 3. Default: Gutenberg native
	 *
	 * @return Arcadia_Block_Adapter
	 */
	private function detect_adapter() {
		// Check for user override.
		$override = get_option( 'arcadia_agents_block_adapter', '' );
		if ( 'acf' === $override ) {
			return new Arcadia_ACF_Adapter();
		}
		if ( 'gutenberg' === $override ) {
			return new Arcadia_Gutenberg_Adapter();
		}

		// Auto-detect: ACF Pro active and has registered blocks.
		if ( self::is_acf_available() ) {
			$acf_blocks = acf_get_block_types();
			if ( ! empty( $acf_blocks ) ) {
				return new Arcadia_ACF_Adapter();
			}
		}

		// Default to Gutenberg native.
		return new Arcadia_Gutenberg_Adapter();
	}

	/**
	 * Get the current adapter.
	 *
	 * @return Arcadia_Block_Adapter
	 */
	public function get_adapter() {
		return $this->adapter;
	}

	/**
	 * Set a specific adapter.
	 *
	 * Useful for testing or forcing a specific adapter.
	 *
	 * @param Arcadia_Block_Adapter $adapter The adapter to use.
	 */
	public function set_adapter( Arcadia_Block_Adapter $adapter ) {
		$this->adapter   = $adapter;
		$this->processor = new Arcadia_Block_Processor( $this->adapter, $this->registry );
	}

	/**
	 * Get the current adapter name.
	 *
	 * @return string
	 */
	public function get_adapter_name() {
		return $this->adapter->get_name();
	}

	// =========================================================================
	// JSON to Blocks Conversion (ADR-013)
	// =========================================================================

	/**
	 * Convert JSON content structure to block content.
	 *
	 * Supports ADR-013 unified block model:
	 * - Everything is a block with `type`
	 * - Container blocks use `children` for nesting
	 * - Leaf blocks use `content` for text
	 *
	 * Validates all blocks before rendering. Returns WP_Error (422)
	 * if an unknown block type or missing required field is detected.
	 *
	 * @param array  $json      The JSON content structure from the agent.
	 * @param string $post_type Target post type (passed to ACF validator).
	 * @return string|WP_Error Block content for post_content, or WP_Error on validation failure.
	 */
	public function json_to_blocks( $json, $post_type = 'post' ) {
		// Validate all blocks before rendering (fail fast).
		$validation = $this->validate_blocks( $json );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// ACF schema validation + image pre-processing (H1.1 + H1.2).
		if ( self::is_acf_available() ) {
			$acf_validator = Arcadia_ACF_Validator::get_instance();
			$acf_result    = $acf_validator->validate_and_preprocess( $json, $post_type );
			if ( is_wp_error( $acf_result ) ) {
				return $acf_result;
			}
		}

		$content = '';

		// Process H1 if present.
		if ( ! empty( $json['h1'] ) ) {
			$content .= $this->adapter->heading( $json['h1'], 1 );
		}

		// Process children (ADR-013 unified block model).
		if ( ! empty( $json['children'] ) && is_array( $json['children'] ) ) {
			foreach ( $json['children'] as $block ) {
				$content .= $this->processor->process_block( $block );
			}
		}

		return $content;
	}

	/**
	 * Dry-run validation: checks block types and ACF schema without side-effects.
	 *
	 * No sideloading, no database writes. Returns structured errors or true.
	 *
	 * @param array  $json      The JSON content structure.
	 * @param string $post_type Target post type.
	 * @return true|WP_Error True if valid, WP_Error with errors if not.
	 */
	public function validate_content( $json, $post_type = 'post' ) {
		// Block type + required fields validation.
		$validation = $this->validate_blocks( $json );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// ACF schema validation (dry-run: skip sideload).
		if ( self::is_acf_available() ) {
			$acf_validator = Arcadia_ACF_Validator::get_instance();
			$acf_result    = $acf_validator->validate_and_preprocess( $json, $post_type, true );
			if ( is_wp_error( $acf_result ) ) {
				return $acf_result;
			}
		}

		return true;
	}

	/**
	 * Validate all blocks recursively before rendering.
	 *
	 * Checks that every block type is registered and that custom blocks
	 * have all required fields present.
	 *
	 * @param array $json The JSON content structure.
	 * @return true|WP_Error True if valid, WP_Error if not.
	 */
	private function validate_blocks( $json ) {
		if ( ! empty( $json['children'] ) && is_array( $json['children'] ) ) {
			foreach ( $json['children'] as $block ) {
				$result = $this->validate_block_recursive( $block );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	}

	/**
	 * Validate a single block and its children recursively.
	 *
	 * @param array $block The block data.
	 * @return true|WP_Error True if valid, WP_Error if not.
	 */
	private function validate_block_recursive( $block ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return true;
		}

		// Normalize core/* prefix for validation (same as process_block).
		$type = $block['type'];
		if ( str_starts_with( $type, 'core/' ) ) {
			$type = substr( $type, 5 );
		}

		// Check if the block type is registered.
		if ( ! $this->registry->is_registered( $type ) ) {
			return new WP_Error(
				'unknown_block_type',
				sprintf(
					/* translators: %s: block type name */
					__( "Block type '%s' is not registered.", 'arcadia-agents' ),
					$type
				),
				array(
					'status'                  => 422,
					'block_type'              => $type,
					'available_custom_blocks' => $this->registry->get_custom_block_names(),
				)
			);
		}

		// Validate properties for custom blocks.
		if ( ! empty( $block['properties'] ) && is_array( $block['properties'] ) ) {
			$validation = $this->registry->validate_properties( $type, $block['properties'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		// Recurse into children.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				$result = $this->validate_block_recursive( $child );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	// =========================================================================
	// Utility Methods
	// =========================================================================

	/**
	 * Check if ACF Pro is available with block support.
	 *
	 * @return bool
	 */
	public static function is_acf_available() {
		return class_exists( 'ACF' ) && function_exists( 'acf_get_block_types' );
	}
}
