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
	 * Max block-tree recursion depth before a subtree is dropped.
	 *
	 * Backstop against pathological / forged deeply-nested round-trip input
	 * driving process_block → passthrough_block recursion into PHP's stack limit
	 * (a 500/segfault DoS on the publish endpoint). Well-formed content never
	 * approaches this. Mirrors Arcadia_Markdown_Parser::MAX_BLOCK_DEPTH.
	 */
	const MAX_BLOCK_DEPTH = 16;

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
	 * Lazily-built native Gutenberg adapter for core/* block rendering.
	 *
	 * @var Arcadia_Gutenberg_Adapter|null
	 */
	private $native = null;

	/**
	 * @param Arcadia_Block_Adapter  $adapter  Active rendering adapter.
	 * @param Arcadia_Block_Registry $registry Custom block registry.
	 */
	public function __construct( Arcadia_Block_Adapter $adapter, Arcadia_Block_Registry $registry ) {
		$this->adapter  = $adapter;
		$this->registry = $registry;
	}

	/**
	 * Get a Gutenberg adapter for rendering native core/* blocks.
	 *
	 * Design rule: the Arcadia_Block_Adapter interface is the multi-builder seam
	 * (ACF vs Gutenberg vs future builders) for ASSEMBLY types (paragraph,
	 * heading, list, image, custom_block). native_gutenberg() is deliberately
	 * OUTSIDE that interface — it renders the core/* blocks (quote, separator,
	 * table, and the container passthrough) that are always native Gutenberg
	 * markup regardless of the active builder (content-model.md §4). Keeping it
	 * off the interface stops every adapter from having to reimplement core
	 * rendering, and gives an ACF site a vanilla net for stray core/* blocks.
	 *
	 * If the active adapter is already Gutenberg we reuse it; otherwise we build
	 * a Gutenberg adapter once so the block renders natively instead of crashing
	 * on a missing ACF method.
	 *
	 * @return Arcadia_Gutenberg_Adapter
	 */
	private function native_gutenberg() {
		if ( $this->adapter instanceof Arcadia_Gutenberg_Adapter ) {
			return $this->adapter;
		}
		if ( null === $this->native ) {
			$this->native = new Arcadia_Gutenberg_Adapter();
		}
		return $this->native;
	}

	/**
	 * Process a block recursively (ADR-013 unified model).
	 *
	 * @param array $block The block data.
	 * @param int   $depth Current recursion depth (DoS backstop; internal).
	 * @return string Block content.
	 */
	public function process_block( $block, $depth = 0 ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return '';
		}
		if ( $depth > self::MAX_BLOCK_DEPTH ) {
			return ''; // Pathological nesting backstop (review #11).
		}

		// Round-trip / WordPress-grammar passthrough (read/write symmetry).
		// A block read back from the site carries inner_content (rendered HTML
		// chunks) and/or inner_blocks (nested container children) — markers the
		// generation model never sets (it uses `content`/`children`). When present,
		// reproduce the stored markup VERBATIM instead of re-deriving it through the
		// leaf renderers, which would drop a container's wrapper or render an empty
		// node for a core/* leaf whose text lives in inner_content, not content.
		// Hard invariant (backend backlog): never silently empty a container; what
		// READ returns under inner_blocks, WRITE reconstructs (ADR-030 verbatim).
		if ( self::is_roundtrip_block( $block ) ) {
			return $this->passthrough_block( $block, $depth );
		}

		// Normalize core/* prefix → strip to short name for builtin dispatch.
		// e.g. core/paragraph → paragraph, core/heading → heading. Keep the
		// original (namespaced) type so an unrenderable namespaced leaf can still
		// be preserved as native markup in the default case (review #3/#5).
		$type          = $block['type'];
		$original_type = $type;
		$was_core      = Arcadia_Block_Registry::is_core_type( $type );
		if ( $was_core ) {
			$type  = Arcadia_Block_Registry::strip_core_prefix( $type );
			$block = array_merge( $block, array( 'type' => $type ) );
		}

		switch ( $block['type'] ) {
			case 'section':
				return $this->process_section_block( $block, $depth );

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

			case 'quote':
				// core/quote — text in `content` (inline markdown), no citation.
				return $this->native_gutenberg()->quote( $block['content'] ?? '' );

			case 'separator':
				// core/separator — no payload.
				return $this->native_gutenberg()->separator();

			case 'table':
				// core/table — properties.headers (nullable) + properties.rows.
				// Cells are inline-markdown strings (backend guarantees rectangular data).
				$props = isset( $block['properties'] ) && is_array( $block['properties'] )
					? $block['properties']
					: array();
				return $this->native_gutenberg()->table(
					$props['headers'] ?? null,
					$props['rows'] ?? array()
				);

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
				// Use an explicit empty-string test, not empty(): a legitimate
				// single-character content of "0" is falsy and would be dropped
				// (review #9 — empty('0') === true).
				if ( isset( $block['content'] ) && '' !== (string) $block['content'] ) {
					return $this->adapter->paragraph( $block['content'] );
				}
				// Never-empty guard: an unrecognised block that still carries
				// children must not vanish — render its children rather than
				// dropping the subtree (backlog: no silent content loss).
				if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
					$out = '';
					foreach ( $block['children'] as $child ) {
						$out .= $this->process_block( $child, $depth + 1 );
					}
					return $out;
				}
				// A namespaced block we have no explicit renderer for (a core/*
				// leaf such as core/spacer, or a third-party leaf) must not be
				// silently dropped: validation accepts it (core/* never 422s), so
				// preserve it as native Gutenberg markup (review #3/#5).
				if ( $was_core || ( is_string( $original_type ) && false !== strpos( $original_type, '/' ) ) ) {
					return $this->native_block_comment(
						$original_type,
						$block['properties'] ?? $block['attrs'] ?? array()
					);
				}
				return '';
		}
	}

	/**
	 * True when a block is round-trip / WordPress-grammar content.
	 *
	 * Such a block carries rendered text under inner_content / innerContent
	 * and/or nested children under inner_blocks / innerBlocks — keys the
	 * generation model (content / children) never sets. Public + static so the
	 * validation pass (Arcadia_Blocks::validate_block_recursive) shares the exact
	 * same discriminator (single source of truth).
	 *
	 * @param array $block The block data.
	 * @return bool
	 */
	public static function is_roundtrip_block( $block ) {
		if ( ! is_array( $block ) ) {
			return false;
		}
		// Generation `content` and round-trip inner_content are mutually exclusive
		// discriminators. A non-empty `content` means this is a generation block;
		// honour it (so its text is rendered, not dropped) even if a stray
		// inner_* key is also present in a malformed payload (review #13).
		if ( isset( $block['content'] ) && '' !== (string) $block['content'] ) {
			return false;
		}
		foreach ( array( 'inner_blocks', 'innerBlocks', 'inner_content', 'innerContent' ) as $key ) {
			if ( ! empty( $block[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reconstruct stored block markup from a round-trip (WP-grammar) block.
	 *
	 * AA's read serializer emits { type, properties, inner_blocks?, inner_content? }.
	 * inner_content is the rendered HTML in document order. In proper WP grammar each
	 * element is either a literal HTML string or a `null` placeholder marking the
	 * position of the next inner block; when those nulls are present we reconstruct
	 * faithfully by interleaving children at their placeholder positions (review #4).
	 * If the reader stripped the nulls (legacy), we fall back to wrapping all
	 * children between the first chunk (open) and the rest (close) — correct for the
	 * single-wrapper containers AA round-trips (core/group, core/columns, core/cover)
	 * and always lossless (no chunk is dropped). Recurses for nested containers.
	 *
	 * Security: although this is the site's own content being re-pushed, the plugin
	 * cannot distinguish a genuine round-trip from a forged payload, so the literal
	 * HTML chunks are passed through wp_kses_post() — the same boundary every other
	 * write path uses — to neutralise injected script/iframe tags, on* handlers and
	 * javascript: URLs (review #2),
	 * and the block name is reduced to its safe slug grammar before it reaches the
	 * comment delimiter (review #1). The block-delimiter comments themselves are
	 * built here from the sanitised name + encode_attributes()'d attrs, so they are
	 * never run through wp_kses_post (which would strip them).
	 *
	 * @param array $block The round-trip block data.
	 * @param int   $depth Current recursion depth.
	 * @return string Block markup.
	 */
	private function passthrough_block( $block, $depth = 0 ) {
		$type = isset( $block['type'] ) ? (string) $block['type'] : '';

		// Children under any key, each rendered individually so it can be placed at
		// its grammar position (null placeholder) when one is present.
		$children      = $block['inner_blocks'] ?? $block['innerBlocks'] ?? $block['children'] ?? array();
		$child_markups = array();
		if ( is_array( $children ) ) {
			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					$child_markups[] = $this->process_block( $child, $depth + 1 );
				}
			}
		}

		$chunks = $block['inner_content'] ?? $block['innerContent'] ?? array();
		$chunks = is_array( $chunks ) ? $chunks : array();
		$inner  = '';

		if ( self::has_null_placeholder( $chunks ) ) {
			// Faithful reconstruction: literal strings as-is (sanitised), each null
			// replaced by the next child in document order.
			$ci = 0;
			foreach ( $chunks as $chunk ) {
				if ( null === $chunk ) {
					if ( isset( $child_markups[ $ci ] ) ) {
						$inner .= $child_markups[ $ci ];
					}
					++$ci;
				} elseif ( is_string( $chunk ) ) {
					$inner .= wp_kses_post( $chunk );
				}
			}
			// Defensive: any children past the placeholders are appended, not lost.
			$count = count( $child_markups );
			for ( ; $ci < $count; $ci++ ) {
				$inner .= $child_markups[ $ci ];
			}
		} else {
			// Legacy fallback: nulls stripped, positions unknown. Wrap children
			// between the first chunk (open) and the remaining chunks (close).
			$string_chunks   = array_values( array_filter( $chunks, 'is_string' ) );
			$children_markup = implode( '', $child_markups );
			if ( empty( $string_chunks ) ) {
				$inner = $children_markup;
			} elseif ( '' === $children_markup ) {
				$inner = wp_kses_post( implode( '', $string_chunks ) );
			} else {
				$open  = wp_kses_post( (string) array_shift( $string_chunks ) );
				$close = wp_kses_post( implode( '', $string_chunks ) );
				$inner = $open . $children_markup . $close;
			}
		}

		// Block-comment wrapper. The core/ namespace is implicit in the comment.
		$name = $this->safe_block_name( $type );
		if ( '' === $name ) {
			// Forged/garbage type that sanitised to nothing — emit the inner HTML
			// alone rather than a broken `<!-- wp: -->` delimiter or dropping it.
			return '' === $inner ? '' : $inner . "\n\n";
		}
		$props = $block['properties'] ?? $block['attrs'] ?? array();
		$attrs = ( is_array( $props ) && ! empty( $props ) )
			? ' ' . Arcadia_Block_Serializer::encode_attributes( $props )
			: '';

		if ( '' === $inner ) {
			// Self-closing (e.g. a round-tripped core/separator with no content).
			return "<!-- wp:{$name}{$attrs} /-->\n\n";
		}

		return "<!-- wp:{$name}{$attrs} -->\n{$inner}\n<!-- /wp:{$name} -->\n\n";
	}

	/**
	 * Emit a self-closing native Gutenberg block comment for a namespaced leaf.
	 *
	 * Used to preserve a core/* or third-party block we have no explicit renderer
	 * for, instead of dropping it (review #3/#5). No inner HTML is available on the
	 * generation path, so it is always self-closing.
	 *
	 * @param string $type  The (possibly namespaced) block type.
	 * @param array  $props Block attributes/properties.
	 * @return string Block comment, or '' if the name sanitises to nothing.
	 */
	private function native_block_comment( $type, $props ) {
		$name = $this->safe_block_name( (string) $type );
		if ( '' === $name ) {
			return '';
		}
		$attrs = ( is_array( $props ) && ! empty( $props ) )
			? ' ' . Arcadia_Block_Serializer::encode_attributes( $props )
			: '';
		return "<!-- wp:{$name}{$attrs} /-->\n\n";
	}

	/**
	 * Reduce a block type to the safe slug interpolated into a block delimiter.
	 *
	 * Strips the core/ prefix via the registry helper (single source of truth) then
	 * keeps only the block-name alphabet — a real WP block name is a strict slug, so
	 * anything else (e.g. a forged `x -->\n<script>` type) is stripped before it can
	 * break out of the `<!-- wp:NAME -->` comment (review #1/#15). Residual `--`
	 * runs are removed too, mirroring encode_attributes' comment-safety on attrs.
	 *
	 * @param string $type The block type.
	 * @return string Safe block name (may be '').
	 */
	private function safe_block_name( $type ) {
		$name = Arcadia_Block_Registry::strip_core_prefix( (string) $type );
		$name = preg_replace( '/[^a-z0-9_\/-]/i', '', (string) $name );
		return str_replace( '--', '', (string) $name );
	}

	/**
	 * True when an inner_content array carries at least one null child placeholder.
	 *
	 * @param array $chunks inner_content array.
	 * @return bool
	 */
	private static function has_null_placeholder( array $chunks ) {
		foreach ( $chunks as $chunk ) {
			if ( null === $chunk ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Process a section block (container with heading).
	 *
	 * @param array $block The section block.
	 * @param int   $depth Current recursion depth.
	 * @return string Block content.
	 */
	private function process_section_block( $block, $depth = 0 ) {
		$content = '';
		$level   = $block['level'] ?? 2;

		// Section heading if present.
		if ( ! empty( $block['heading'] ) ) {
			$content .= $this->adapter->heading( $block['heading'], $level );
		}

		// Process children recursively.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				$content .= $this->process_block( $child, $depth + 1 );
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
