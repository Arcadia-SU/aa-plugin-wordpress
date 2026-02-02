<?php
/**
 * Gutenberg block adapter.
 *
 * Generates native WordPress Gutenberg blocks from semantic JSON content.
 * This is the default adapter for sites using the standard block editor.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Gutenberg_Adapter
 *
 * Generates native Gutenberg blocks.
 * Output follows the WordPress block grammar specification.
 *
 * @see https://developer.wordpress.org/block-editor/explanations/architecture/key-concepts/
 */
class Arcadia_Gutenberg_Adapter implements Arcadia_Block_Adapter {

	/**
	 * Get the adapter name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'gutenberg';
	}

	/**
	 * Convert a heading to Gutenberg block format.
	 *
	 * @param string $text  The heading text.
	 * @param int    $level The heading level (1-6).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 ) {
		$level = max( 1, min( 6, (int) $level ) );
		$text  = Arcadia_Blocks::parse_markdown( $text );
		$text  = esc_html( $text );

		return sprintf(
			'<!-- wp:heading {"level":%d} -->' . "\n" .
			'<h%d class="wp-block-heading">%s</h%d>' . "\n" .
			'<!-- /wp:heading -->' . "\n\n",
			$level,
			$level,
			$text,
			$level
		);
	}

	/**
	 * Convert a paragraph to Gutenberg block format.
	 *
	 * @param string $text The paragraph text.
	 * @return string Block markup.
	 */
	public function paragraph( $text ) {
		$text = Arcadia_Blocks::parse_markdown( $text );

		return sprintf(
			'<!-- wp:paragraph -->' . "\n" .
			'<p>%s</p>' . "\n" .
			'<!-- /wp:paragraph -->' . "\n\n",
			$text
		);
	}

	/**
	 * Convert an image to Gutenberg block format.
	 *
	 * @param string $url     The image URL.
	 * @param string $alt     The alt text.
	 * @param string $caption The caption (optional).
	 * @return string Block markup.
	 */
	public function image( $url, $alt = '', $caption = '' ) {
		$url = esc_url( $url );
		$alt = esc_attr( $alt );

		$caption_html = '';
		if ( $caption ) {
			$caption_html = '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}

		return sprintf(
			'<!-- wp:image -->' . "\n" .
			'<figure class="wp-block-image"><img src="%s" alt="%s"/>%s</figure>' . "\n" .
			'<!-- /wp:image -->' . "\n\n",
			$url,
			$alt,
			$caption_html
		);
	}

	/**
	 * Convert a list to Gutenberg block format.
	 *
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false ) {
		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? '{"ordered":true}' : '{}';

		$list_items = '';
		foreach ( $items as $item ) {
			$item        = Arcadia_Blocks::parse_markdown( $item );
			$list_items .= '<li>' . $item . '</li>';
		}

		return sprintf(
			'<!-- wp:list %s -->' . "\n" .
			'<%s>%s</%s>' . "\n" .
			'<!-- /wp:list -->' . "\n\n",
			$attrs,
			$tag,
			$list_items,
			$tag
		);
	}
}
