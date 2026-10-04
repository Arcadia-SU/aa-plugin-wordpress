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

// Load shared helpers used by adapters (comment-safe serializer + SSRF guard).
require_once __DIR__ . '/class-block-serializer.php';
require_once __DIR__ . '/class-url-guard.php';

// Load adapter interface, implementations, and block processor.
require_once __DIR__ . '/adapters/interface-block-adapter.php';
require_once __DIR__ . '/adapters/class-adapter-gutenberg.php';
require_once __DIR__ . '/adapters/class-adapter-acf.php';
require_once __DIR__ . '/class-block-children.php';
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
	 * @param bool   $dry_run   If true, skip image sideload (no side-effects) so the
	 *                          render reflects what would be stored without persisting.
	 * @return string|WP_Error Block content for post_content, or WP_Error on validation failure.
	 */
	public function json_to_blocks( $json, $post_type = 'post', $dry_run = false ) {
		// Validate all blocks before rendering (fail fast).
		$validation = $this->validate_blocks( $json );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// ACF schema validation + image pre-processing (H1.1 + H1.2).
		// In dry-run the validator validates + coerces but skips sideload.
		if ( self::is_acf_available() ) {
			$acf_validator = Arcadia_ACF_Validator::get_instance();
			$acf_result    = $acf_validator->validate_and_preprocess( $json, $post_type, $dry_run );
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
	private function validate_block_recursive( $block, $in_roundtrip = false ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return true;
		}

		// Round-trip / WP-grammar content is reproduced verbatim on write
		// (read/write symmetry): the node itself is the site's own existing content,
		// not new generation to check against the registry (a registry check would
		// 422 legitimate third-party blocks and break the round-trip). But we DO
		// recurse its children so a structurally-broken nested node surfaces an
		// error instead of vanishing silently at render (review #5). Same
		// discriminator the renderer uses, so validation and rendering agree.
		if ( Arcadia_Block_Processor::is_roundtrip_block( $block ) ) {
			// Placeholders that disagree with the children in number cannot be
			// read: no position says which child is extra or missing, and the
			// renderer's guess would put one after the parent's closing tag — a
			// page broken in silence. Refuse instead (Phase 52).
			$mismatch = Arcadia_Block_Children::placeholder_mismatch( $block );
			if ( null !== $mismatch ) {
				return new WP_Error(
					'child_position_mismatch',
					sprintf(
						/* translators: 1: block type, 2: number of child blocks, 3: number of null placeholders */
						__( "Block '%1\$s' has %2\$d child blocks but its inner_content marks %3\$d positions for them.", 'arcadia-agents' ),
						$block['type'],
						$mismatch['children'],
						$mismatch['positions']
					),
					array(
						'status'     => 422,
						'block_type' => $block['type'],
						'children'   => $mismatch['children'],
						'positions'  => $mismatch['positions'],
					)
				);
			}
			return $this->validate_block_children( $block, true );
		}

		$type = $block['type'];

		// core/* blocks are always valid in post_content (native Gutenberg
		// pass-through, content-model.md §4). Accept without allowlist/property
		// checks — they must never 422 — but still recurse into children below so
		// a malformed nested block is still caught. This special-case must come
		// BEFORE any prefix normalization: stripping `core/` first would route the
		// short name (e.g. "quote") through the registered-types allowlist and
		// reject everything outside BUILTIN_BLOCKS (the historical 422 bug).
		//
		// Inside a round-trip subtree, a namespaced leaf (core/* OR third-party,
		// e.g. a self-closing acme/spacer with empty inner_content) is also the
		// site's own content: the renderer preserves it as native markup, so accept
		// it here too rather than 422 it (review #5). Outside round-trip, only
		// core/* skips the registry — acf/* generation blocks still get validated.
		$is_namespaced = is_string( $type ) && false !== strpos( $type, '/' );
		$skip_registry = Arcadia_Block_Registry::is_core_type( $type ) || ( $in_roundtrip && $is_namespaced );

		// ...but the exemption only covers the "is this type known?" question.
		// A type the registry DOES know is one the renderer will hand to
		// render_custom_block() (class-block-processor.php, default case), and
		// what the renderer will run, validation must check — nested or not.
		// Before Phase 48 a registered acf/* block inside any container skipped
		// its required-field check purely by position, so the same payload was
		// refused at the root and accepted one level down. The exemption still
		// holds for the case it was opened for: an UNREGISTERED third-party leaf
		// in a round-trip subtree is the site's own content, preserved as native
		// markup rather than 422'd (review #5, Phase 38).
		$known_to_registry = ! $skip_registry || $this->registry->is_registered( $type );

		if ( ! $skip_registry ) {
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

		}

		// Validate properties for any block the registry knows — see above.
		if ( $known_to_registry && ! empty( $block['properties'] ) && is_array( $block['properties'] ) ) {
			$validation = $this->registry->validate_properties( $type, $block['properties'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		// Recurse into children.
		return $this->validate_block_children( $block, $in_roundtrip );
	}

	/**
	 * Recurse validation into a block's children under any child key.
	 *
	 * Walks `children` (generation) plus `inner_blocks`/`innerBlocks` (round-trip),
	 * so a malformed node nested inside a round-trip container is still caught
	 * (review #5: never silent content loss). Children reached through an
	 * inner_blocks key — or any child of a round-trip block — inherit the
	 * round-trip context so their own namespaced leaves are accepted, not 422'd.
	 *
	 * @param array $block        The block data.
	 * @param bool  $in_roundtrip Whether $block is itself round-trip content.
	 * @return true|WP_Error True if all children valid, WP_Error otherwise.
	 */
	private function validate_block_children( $block, $in_roundtrip = false ) {
		foreach ( Arcadia_Block_Processor::CHILD_KEYS as $key ) {
			if ( empty( $block[ $key ] ) || ! is_array( $block[ $key ] ) ) {
				continue;
			}
			$child_roundtrip = $in_roundtrip || 'inner_blocks' === $key || 'innerBlocks' === $key;
			foreach ( $block[ $key ] as $child ) {
				$result = $this->validate_block_recursive( $child, $child_roundtrip );
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
