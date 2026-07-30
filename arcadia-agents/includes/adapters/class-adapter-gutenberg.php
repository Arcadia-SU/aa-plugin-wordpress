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
		$text  = Arcadia_Markdown_Parser::parse_markdown( $text );
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
		$text = Arcadia_Markdown_Parser::parse_markdown( $text );

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
	 * Convert a custom block to Gutenberg block format.
	 *
	 * Generates a self-closing dynamic block comment.
	 * Properties are passed directly as block attributes.
	 *
	 * @param string $block_name The full block name (e.g., 'my-plugin/rating').
	 * @param array  $properties The block attributes.
	 * @return string Block markup.
	 */
	public function custom_block( $block_name, $properties ) {
		$attrs = ! empty( $properties )
			? ' ' . Arcadia_Block_Serializer::encode_attributes( $properties )
			: '';

		return sprintf(
			'<!-- wp:%s%s /-->' . "\n\n",
			$block_name,
			$attrs
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
			$item        = Arcadia_Markdown_Parser::parse_markdown( $item );
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

	/**
	 * Convert a quote to a native Gutenberg quote block.
	 *
	 * Renders the core/quote block: a wp-block-quote blockquote wrapping an
	 * inner paragraph block (so the quote text keeps inline markdown formatting).
	 * Not part of Arcadia_Block_Adapter — core blocks are always native Gutenberg
	 * regardless of the site's active builder.
	 *
	 * @param string $content The quote text (may contain inline markdown).
	 * @return string Block markup.
	 */
	public function quote( $content ) {
		// Scalar guard: a non-scalar payload (array/object) would stringify to
		// "Array" + a PHP warning. Degrade to empty content instead.
		$content = is_scalar( $content ) ? (string) $content : '';

		return '<!-- wp:quote -->' . "\n" .
			'<blockquote class="wp-block-quote">' . "\n" .
			$this->paragraph( $content ) .
			'</blockquote>' . "\n" .
			'<!-- /wp:quote -->' . "\n\n";
	}

	/**
	 * Convert a separator to a native Gutenberg separator block.
	 *
	 * @return string Block markup.
	 */
	public function separator() {
		return '<!-- wp:separator -->' . "\n" .
			'<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" .
			'<!-- /wp:separator -->' . "\n\n";
	}

	/**
	 * Convert tabular data to a native Gutenberg table block.
	 *
	 * Cells carry inline markdown (not raw HTML), so each cell is run through
	 * Arcadia_Markdown_Parser::parse_markdown() — which already wp_kses() its
	 * output to the inline allowlist, so cells are NOT additionally escaped
	 * (that would double-escape the legitimate <strong>/<em>/<a> markup).
	 *
	 * @param array|null $headers Header cells, or null/empty for a headerless table.
	 * @param array      $rows    Body rows, each an array of cell strings.
	 * @return string Block markup.
	 */
	public function table( $headers, $rows ) {
		$thead = '';
		if ( ! empty( $headers ) && is_array( $headers ) ) {
			$cells = '';
			foreach ( $headers as $cell ) {
				$cell   = is_scalar( $cell ) ? (string) $cell : '';
				$cells .= '<th>' . Arcadia_Markdown_Parser::parse_markdown( $cell ) . '</th>';
			}
			$thead = '<thead><tr>' . $cells . '</tr></thead>';
		}

		$body = '';
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$cells = '';
				foreach ( $row as $cell ) {
					// Scalar guard: a non-scalar cell would stringify to "Array".
					$cell   = is_scalar( $cell ) ? (string) $cell : '';
					$cells .= '<td>' . Arcadia_Markdown_Parser::parse_markdown( $cell ) . '</td>';
				}
				$body .= '<tr>' . $cells . '</tr>';
			}
		}

		return sprintf(
			'<!-- wp:table -->' . "\n" .
			'<figure class="wp-block-table"><table>%s<tbody>%s</tbody></table></figure>' . "\n" .
			'<!-- /wp:table -->' . "\n\n",
			$thead,
			$body
		);
	}
}
