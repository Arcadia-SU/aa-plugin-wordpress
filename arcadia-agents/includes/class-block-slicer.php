<?php
/**
 * Block slicer: the exact bytes of each top-level block in post_content.
 *
 * Read side of the `stored` contract (backend backlog 2026-11-05, P1).
 * GET /contents/{id}/blocks hands every top-level block its own slice of
 * post_content; a PUT that sends a block back unchanged returns that slice
 * under `stored`, and the write path copies it as-is instead of re-rendering
 * the block from its properties (see Arcadia_Blocks::json_to_blocks()).
 *
 * Why bytes and not a re-serialisation: rendering an ACF block back from its
 * data is lossy. On iSelection post 114236 a push that changed one figure in
 * two blocks rewrote six others the client had written by hand — `\r\n` became
 * `\n`, bare text gained a `<p>`, an `acf/title` was wrapped in one. Only the
 * stored bytes are certain to be what the site already holds.
 *
 * @package ArcadiaAgents
 * @since   0.12.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Block_Slicer
 *
 * Stateless. Tokenises block delimiters with WordPress's own regular
 * expression (WP_Block_Parser::next_token(), unchanged since WP 5.0), so the
 * blocks it finds are the blocks parse_blocks() finds.
 */
final class Arcadia_Block_Slicer {

	/**
	 * Block delimiter grammar, copied verbatim from WP_Block_Parser::next_token().
	 *
	 * Must stay identical to core: a delimiter core recognises and this does not
	 * (or the reverse) would shift every slice after it. The caller still checks
	 * the block names against parse_blocks() and drops all slices on any
	 * disagreement, so a drift costs the `stored` key, never a wrong byte.
	 */
	const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Cut post_content into one slice per top-level block.
	 *
	 * Slice i runs from the start of block i to the start of block i+1: the
	 * block itself plus whatever follows it up to the next block (separators,
	 * free HTML). The first slice also carries what precedes the first block,
	 * and the last one runs to the end. Concatenated, the slices are
	 * $content, byte for byte — the invariant the write side relies on.
	 *
	 * Returns null when the slices cannot be trusted: no block at all, a
	 * closer with no opener at the top level (core stops parsing there and
	 * turns the rest into freeform HTML), or a PCRE failure.
	 *
	 * @param string $content Raw post_content.
	 * @return array<int, array{name: string, markup: string}>|null
	 */
	public static function top_level_slices( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return null;
		}

		$starts = array();
		$names  = array();
		$depth  = 0;
		$offset = 0;

		while ( true ) {
			$found = preg_match( self::DELIMITER, $content, $m, PREG_OFFSET_CAPTURE, $offset );
			if ( false === $found ) {
				return null; // PCRE failure: no partial answer.
			}
			if ( 0 === $found ) {
				break;
			}

			$at        = $m[0][1];
			$offset    = $at + strlen( $m[0][0] );
			$is_closer = isset( $m['closer'] ) && -1 !== $m['closer'][1];
			$is_void   = isset( $m['void'] ) && -1 !== $m['void'][1];

			if ( $is_closer ) {
				if ( 0 === $depth ) {
					return null; // Stray closer: core's tree diverges from here on.
				}
				--$depth;
				continue;
			}

			if ( 0 === $depth ) {
				$namespace = ( isset( $m['namespace'] ) && -1 !== $m['namespace'][1] ) ? $m['namespace'][0] : 'core/';
				$starts[]  = $at;
				$names[]   = $namespace . $m['name'][0];
			}
			if ( ! $is_void ) {
				++$depth;
			}
		}

		if ( empty( $starts ) ) {
			return null;
		}

		$slices = array();
		$count  = count( $starts );
		for ( $i = 0; $i < $count; $i++ ) {
			$from     = 0 === $i ? 0 : $starts[ $i ];
			$to       = $i + 1 < $count ? $starts[ $i + 1 ] : strlen( $content );
			$slices[] = array(
				'name'   => $names[ $i ],
				'markup' => substr( $content, $from, $to - $from ),
			);
		}

		return $slices;
	}

	/**
	 * Attach `stored` to each formatted top-level block, or to none.
	 *
	 * All or nothing: the slices are attached only if they name exactly the
	 * blocks the formatter produced, in the same order. A partial attach would
	 * let a caller send back a slice under the wrong block.
	 *
	 * @param array  $blocks  Output of format_parsed_blocks() (top level).
	 * @param string $content The post_content those blocks were parsed from.
	 * @return array $blocks, each with `stored` when the slices line up.
	 */
	public static function attach_stored( array $blocks, $content ) {
		$slices = self::top_level_slices( $content );
		if ( null === $slices || count( $slices ) !== count( $blocks ) ) {
			return $blocks;
		}

		foreach ( $blocks as $i => $block ) {
			if ( ( $block['blockName'] ?? null ) !== $slices[ $i ]['name'] ) {
				return $blocks;
			}
		}

		foreach ( $blocks as $i => $block ) {
			$blocks[ $i ]['stored'] = $slices[ $i ]['markup'];
		}

		return $blocks;
	}
}
