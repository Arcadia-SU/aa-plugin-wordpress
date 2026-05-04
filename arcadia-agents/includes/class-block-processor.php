<?php
/**
 * Block processor.
 *
 * Renders ADR-013 unified block model nodes via the active adapter.
 * Extracted from Arcadia_Blocks (Phase D) so block-rendering logic lives
 * separately from adapter detection and validation orchestration.
 *
 * @package ArcadiaAgents
 * @since   0.1.24
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Block_Processor
 *
 * Stateless renderer composed by Arcadia_Blocks. Constructor-injected with
 * the active adapter + registry; recurses through children to produce the
 * final post_content string.
 */
final class Arcadia_Block_Processor {

	/**
	 * Active block adapter.
	 *
	 * @var Arcadia_Block_Adapter
	 */
	private $adapter;

	/**
	 * Custom block registry.
	 *
	 * @var Arcadia_Block_Registry
	 */
	private $registry;

	/**
	 * @param Arcadia_Block_Adapter  $adapter  Active rendering adapter.
	 * @param Arcadia_Block_Registry $registry Custom block registry.
	 */
	public function __construct( Arcadia_Block_Adapter $adapter, Arcadia_Block_Registry $registry ) {
		$this->adapter  = $adapter;
		$this->registry = $registry;
	}

	/**
	 * Process a block recursively (ADR-013 unified model).
	 *
	 * @param array $block The block data.
	 * @return string Block content.
	 */
	public function process_block( $block ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return '';
		}

		// Normalize core/* prefix → strip to short name for builtin dispatch.
		// e.g. core/paragraph → paragraph, core/heading → heading.
		$type = $block['type'];
		if ( str_starts_with( $type, 'core/' ) ) {
			$type  = substr( $type, 5 );
			$block = array_merge( $block, array( 'type' => $type ) );
		}

		switch ( $block['type'] ) {
			case 'section':
				return $this->process_section_block( $block );

			case 'paragraph':
				return $this->adapter->paragraph( $block['content'] ?? '' );

			case 'text':
				// Text blocks are typically used in lists, but can appear standalone.
				return $this->adapter->paragraph( $block['content'] ?? '' );

			case 'image':
				return $this->adapter->image(
					$block['url'] ?? '',
					$block['alt'] ?? '',
					$block['caption'] ?? ''
				);

			case 'list':
				return $this->process_list_block( $block );

			case 'heading':
				// Standalone heading blocks.
				return $this->adapter->heading(
					$block['content'] ?? $block['text'] ?? '',
					$block['level'] ?? 2
				);

			default:
				// Check if this is a registered custom block.
				if ( $this->registry->is_registered( $block['type'] ) && ! empty( $block['properties'] ) ) {
					return $this->render_custom_block( $block );
				}
				// Fallback: treat unknown types as paragraphs if they have content.
				if ( ! empty( $block['content'] ) ) {
					return $this->adapter->paragraph( $block['content'] );
				}
				return '';
		}
	}

	/**
	 * Process a section block (container with heading).
	 *
	 * @param array $block The section block.
	 * @return string Block content.
	 */
	private function process_section_block( $block ) {
		$content = '';
		$level   = $block['level'] ?? 2;

		// Section heading if present.
		if ( ! empty( $block['heading'] ) ) {
			$content .= $this->adapter->heading( $block['heading'], $level );
		}

		// Process children recursively.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				$content .= $this->process_block( $child );
			}
		}

		return $content;
	}

	/**
	 * Process a list block.
	 *
	 * ADR-013: list items are `text` blocks in `children` array.
	 *
	 * @param array $block The list block.
	 * @return string Block content.
	 */
	private function process_list_block( $block ) {
		$ordered = $block['ordered'] ?? false;
		$items   = array();

		// Extract text content from children (ADR-013 format).
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				if ( isset( $child['type'] ) && 'text' === $child['type'] ) {
					$items[] = $child['content'] ?? '';
				} elseif ( isset( $child['type'] ) && 'list' === $child['type'] ) {
					// Nested list - for now, flatten it.
					$nested_items = $this->extract_list_items( $child );
					$items        = array_merge( $items, $nested_items );
				}
			}
		}

		return $this->adapter->listing( $items, $ordered );
	}

	/**
	 * Extract text items from a list block recursively.
	 *
	 * @param array $block The list block.
	 * @return array List of text items.
	 */
	private function extract_list_items( $block ) {
		$items = array();

		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				if ( isset( $child['type'] ) && 'text' === $child['type'] ) {
					$items[] = $child['content'] ?? '';
				}
			}
		}

		return $items;
	}

	/**
	 * Render a custom block via the adapter.
	 *
	 * Determines the full block name based on adapter type:
	 * - ACF adapter: prefixes with "acf/" if not already prefixed
	 * - Gutenberg adapter: uses the type as-is (should be namespace/name)
	 *
	 * @param array $block The block data with 'type' and 'properties'.
	 * @return string Block markup.
	 */
	private function render_custom_block( $block ) {
		$type       = $block['type'];
		$properties = $block['properties'];

		// For ACF adapter, prefix with "acf/" if not already.
		if ( $this->adapter instanceof Arcadia_ACF_Adapter && ! str_contains( $type, '/' ) ) {
			$type = 'acf/' . $type;
		}

		return $this->adapter->custom_block( $type, $properties );
	}
}
