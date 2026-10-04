<?php
/**
 * Child placement — where each nested block goes inside its parent's markup.
 *
 * A round-trip block carries its children (`inner_blocks`) and its own markup
 * around them (`inner_content`). WordPress knows where each child goes only from
 * a `null` placeholder in `inner_content`, one per child:
 * `["<ul>", null, "\n\n", null, "</ul>"]`. Without placeholders the positions
 * must be read back from the markup.
 *
 * The rule is AA's (`app/domain/seo/value_objects/block_children.py`), ported
 * verbatim so both sides cut the same wrapper at the same place:
 *
 *  - an element with nothing but whitespace inside it is the slot the children
 *    were cut out of (`<ul>\n\n</ul>`, a group's inner `<div>`, a cover's inner
 *    container, a media-text's content column). When several are empty — a
 *    cover's background `<span>` before its inner container — the slot is the
 *    one holding the most whitespace, since WordPress leaves a separator between
 *    siblings; on a tie, the last one;
 *  - otherwise the children follow the parent's own text, before its closing
 *    tag (a list item holding a nested list: `<li>text</li>`), except that a
 *    trailing `<cite>` or `<figcaption>` stays last (a quote's citation).
 *
 * Before Phase 52 the plugin took the first chunk as the opening and appended
 * every child after it. A container read back from the site is a single chunk
 * (`<ul>\n\n</ul>`), so its children landed after the closing tag — 19 pages on
 * trottinette-tout-terrain.fr rendered an empty list with its items outside it.
 *
 * @package ArcadiaAgents
 * @since   0.11.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Block_Children
 *
 * Stateless helpers shared by the renderer (Arcadia_Block_Processor) and the
 * validation pass (Arcadia_Blocks), so the two can never disagree on where a
 * child goes or on which payload cannot be placed.
 */
final class Arcadia_Block_Children {

	/**
	 * Elements that never hold content, so an "empty" one is never a slot.
	 *
	 * @var string[]
	 */
	const VOID_ELEMENTS = array(
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	);

	/**
	 * Captions that stay after the children rather than before them.
	 *
	 * @var string[]
	 */
	const TRAILING_CAPTIONS = array( 'cite', 'figcaption' );

	/**
	 * An element holding nothing but whitespace (group 2 = that whitespace).
	 */
	const EMPTY_ELEMENT = '/<([A-Za-z][\w-]*)\b[^>]*>(\s*)<\/\1\s*>/';

	/**
	 * The closing tag that ends a wrapper.
	 */
	const CLOSING_TAG = '/<\/([A-Za-z][\w-]*)\s*>\s*$/';

	/**
	 * The child blocks of a round-trip node, under whichever key carries them.
	 *
	 * Same key precedence as the renderer: inner_blocks, innerBlocks, children.
	 *
	 * @param array $block Block data.
	 * @return array Child blocks (arrays only).
	 */
	public static function children_of( array $block ) {
		$children = $block['inner_blocks'] ?? $block['innerBlocks'] ?? $block['children'] ?? array();
		if ( ! is_array( $children ) ) {
			return array();
		}
		return array_values( array_filter( $children, 'is_array' ) );
	}

	/**
	 * The `inner_content` chunks of a node, under whichever key carries them.
	 *
	 * @param array $block Block data.
	 * @return array Chunks (strings and null placeholders).
	 */
	public static function chunks_of( array $block ) {
		$chunks = $block['inner_content'] ?? $block['innerContent'] ?? array();
		return is_array( $chunks ) ? $chunks : array();
	}

	/**
	 * Number of `null` child placeholders in a chunk list.
	 *
	 * @param array $chunks inner_content chunks.
	 * @return int
	 */
	public static function count_placeholders( array $chunks ) {
		$count = 0;
		foreach ( $chunks as $chunk ) {
			if ( null === $chunk ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether a node's placeholders exist but disagree with its children in number.
	 *
	 * No reading of the markup says which child an extra or a missing position
	 * belongs to, so such a node cannot be placed — it must be refused, not
	 * guessed (a guess here puts a child after the parent's closing tag).
	 * A node with no placeholder at all is not a mismatch: its positions are
	 * read from the markup by split_at_slot().
	 *
	 * @param array $block Block data.
	 * @return array{children:int, positions:int}|null The disagreement, or null.
	 */
	public static function placeholder_mismatch( array $block ) {
		$positions = self::count_placeholders( self::chunks_of( $block ) );
		$children  = count( self::children_of( $block ) );

		if ( 0 === $positions || $positions === $children ) {
			return null;
		}

		return array(
			'children'  => $children,
			'positions' => $positions,
		);
	}

	/**
	 * Cut a parent's markup where its children belong.
	 *
	 * @param string $wrapper The parent's own markup, children removed, trimmed.
	 * @return array{0:string, 1:string} [ opening, closing ].
	 */
	public static function split_at_slot( $wrapper ) {
		$wrapper = (string) $wrapper;

		if ( preg_match_all( self::EMPTY_ELEMENT, $wrapper, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$slot   = null;
			$widest = -1;
			foreach ( $matches as $match ) {
				if ( in_array( strtolower( $match[1][0] ), self::VOID_ELEMENTS, true ) ) {
					continue;
				}
				$width = strlen( $match[2][0] );
				// `>=`: on a tie the last one wins.
				if ( $width >= $widest ) {
					$widest = $width;
					$slot   = $match[2];
				}
			}
			if ( null !== $slot ) {
				$start = $slot[1];
				$end   = $start + strlen( $slot[0] );
				return array(
					rtrim( substr( $wrapper, 0, $start ) ),
					ltrim( substr( $wrapper, $end ) ),
				);
			}
		}

		if ( ! preg_match( self::CLOSING_TAG, $wrapper, $closing, PREG_OFFSET_CAPTURE ) ) {
			return array( $wrapper, '' );
		}

		$cut     = $closing[0][1];
		$caption = self::trailing_caption_start( substr( $wrapper, 0, $cut ) );
		if ( null !== $caption ) {
			$cut = $caption;
		}

		return array(
			rtrim( substr( $wrapper, 0, $cut ) ),
			ltrim( substr( $wrapper, $cut ) ),
		);
	}

	/**
	 * Where a `<cite>`/`<figcaption>` closing the parent's body starts.
	 *
	 * @param string $body The wrapper up to the parent's closing tag.
	 * @return int|null Byte offset of the caption's opening tag, or null.
	 */
	private static function trailing_caption_start( $body ) {
		$stripped = rtrim( $body );
		if ( ! preg_match( self::CLOSING_TAG, $stripped, $end ) ) {
			return null;
		}
		$name = strtolower( $end[1] );
		if ( ! in_array( $name, self::TRAILING_CAPTIONS, true ) ) {
			return null;
		}

		$last = null;
		if ( preg_match_all( '/<([A-Za-z][\w-]*)\b[^>]*>/', $stripped, $openings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $openings as $opening ) {
				if ( strtolower( $opening[1][0] ) === $name ) {
					$last = $opening[0][1];
				}
			}
		}
		return $last;
	}
}
