<?php
/**
 * Block attribute serializer (comment-safe).
 *
 * Single source of truth for encoding block attributes into the JSON that
 * lives inside a `<!-- wp:... {ATTRS} -->` comment. Agent-supplied content can
 * contain comment-delimiter sequences (`-->`, `<!--`) which, if left raw,
 * break out of the block comment and corrupt the block (or inject markup).
 *
 * An HTML comment terminates only at `-->` (or the legacy `--!>`), both of
 * which require the `--` sequence. We therefore escape only `--` (rewritten as
 * the JSON `--` escape), which neutralises both `-->` breakout and a
 * forged `<!-- wp:` block (the forgery needs its own `-->`, whose `--` is also
 * escaped). Bare `<` / `>` do NOT terminate a comment, so we deliberately leave
 * them literal — this preserves legitimate HTML (e.g. wysiwyg field values) in
 * a readable form. We keep JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES so
 * URLs and accents are unchanged. The escape is lossless: json_decode() on the
 * parsed block recovers the original `--`.
 *
 * @package ArcadiaAgents
 * @since   0.1.30
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Block_Serializer
 *
 * Stateless serializer. All public methods are static.
 */
final class Arcadia_Block_Serializer {

	/**
	 * Encode block attributes to comment-safe JSON.
	 *
	 * @param array $attributes The block attributes (name/data/mode for ACF, or arbitrary props).
	 * @return string JSON string safe to embed inside an HTML block comment.
	 */
	public static function encode_attributes( $attributes ) {
		$json = wp_json_encode( $attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			// wp_json_encode failed (e.g. malformed UTF-8). Emit an empty object
			// rather than a broken comment; the caller's block degrades gracefully.
			return '{}';
		}

		// Neutralise the only comment-terminating sequence (`--`) so agent content
		// can't break out of `<!-- ... -->`. Lossless: json_decode() restores `--`.
		$json = preg_replace( '/--/', '\\u002d\\u002d', $json );

		return $json;
	}
}
