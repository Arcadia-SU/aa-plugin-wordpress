<?php
/**
 * Inline markdown parser.
 *
 * Pure-function utility extracted from Arcadia_Blocks (Phase D).
 *
 * @package ArcadiaAgents
 * @since   0.1.24
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Markdown_Parser
 *
 * Stateless converter from inline markdown to HTML. All public methods are static.
 */
final class Arcadia_Markdown_Parser {

	/**
	 * Parse inline markdown and convert to HTML.
	 *
	 * Supports: **bold**, *italic*, `code`, [link](url)
	 *
	 * Order matters:
	 * 1. Code (protect content from further parsing)
	 * 2. Bold (before italic to avoid ** matching as two *)
	 * 3. Italic
	 * 4. Links (last so link text can contain formatted content)
	 *
	 * @param string $text Text containing markdown.
	 * @return string Text with HTML formatting.
	 */
	public static function parse_markdown( $text ) {
		// 1. Code inline: `code` -> <code>code</code>
		// Process first to protect content inside backticks from further parsing.
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $matches ) {
				return '<code>' . esc_html( $matches[1] ) . '</code>';
			},
			$text
		);

		// 2. Bold: **text** -> <strong>text</strong>
		// Must be before italic to avoid matching ** as two *
		$text = preg_replace(
			'/\*\*([^*]+)\*\*/',
			'<strong>$1</strong>',
			$text
		);

		// 3. Italic: *text* -> <em>text</em>
		// Only match single * not preceded/followed by another *
		$text = preg_replace(
			'/(?<!\*)\*([^*]+)\*(?!\*)/',
			'<em>$1</em>',
			$text
		);

		// 4. Links: [text](url) -> <a href="url">text</a>
		// Process last so link text can contain <strong>, <em>, etc.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $matches ) {
				$link_text = $matches[1]; // Already may contain HTML from bold/italic.
				$url       = esc_url( $matches[2] );

				// Check if external link.
				$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
				$link_host = wp_parse_url( $url, PHP_URL_HOST );

				$target = '';
				$rel    = '';

				if ( $link_host && $link_host !== $site_host ) {
					$target = ' target="_blank"';
					$rel    = ' rel="noopener noreferrer"';
				}

				return sprintf( '<a href="%s"%s%s>%s</a>', $url, $target, $rel, $link_text );
			},
			$text
		);

		return $text;
	}
}
