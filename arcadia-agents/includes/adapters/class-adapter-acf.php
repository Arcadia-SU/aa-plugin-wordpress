<?php
/**
 * ACF block adapter.
 *
 * Generates ACF (Advanced Custom Fields) blocks from semantic JSON content.
 * Use this adapter for sites that use ACF Pro with custom block types.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_ACF_Adapter
 *
 * Generates ACF blocks.
 * Assumes the theme has registered appropriate ACF block types:
 * - acf/title for headings
 * - acf/text for paragraphs and lists
 * - acf/image for images
 *
 * @see https://www.advancedcustomfields.com/resources/blocks/
 */
class Arcadia_ACF_Adapter implements Arcadia_Block_Adapter {

	/**
	 * Get the adapter name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'acf';
	}

	/**
	 * Convert a heading to ACF block format.
	 *
	 * @param string $text  The heading text.
	 * @param int    $level The heading level (1-6).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 ) {
		$level = max( 1, min( 6, (int) $level ) );
		$text  = Arcadia_Blocks::parse_markdown( $text );
		$text  = esc_html( $text );

		$data = array(
			'title' => sprintf( '<h%d>%s</h%d>', $level, $text, $level ),
			'level' => $level,
		);

		return $this->acf_block( 'acf/title', $data );
	}

	/**
	 * Convert a paragraph to ACF block format.
	 *
	 * @param string $text The paragraph text.
	 * @return string Block markup.
	 */
	public function paragraph( $text ) {
		$text = Arcadia_Blocks::parse_markdown( $text );

		$data = array(
			'text' => '<p>' . $text . '</p>',
		);

		return $this->acf_block( 'acf/text', $data );
	}

	/**
	 * Convert an image to ACF block format.
	 *
	 * @param string $url     The image URL.
	 * @param string $alt     The alt text.
	 * @param string $caption The caption (optional).
	 * @return string Block markup.
	 */
	public function image( $url, $alt = '', $caption = '' ) {
		$data = array(
			'image'   => array(
				'url' => esc_url( $url ),
				'alt' => esc_attr( $alt ),
			),
			'caption' => esc_html( $caption ),
		);

		return $this->acf_block( 'acf/image', $data );
	}

	/**
	 * Convert a list to ACF block format.
	 *
	 * Lists are rendered as HTML within an acf/text block.
	 *
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false ) {
		$tag = $ordered ? 'ol' : 'ul';

		$list_items = '';
		foreach ( $items as $item ) {
			$item        = Arcadia_Blocks::parse_markdown( $item );
			$list_items .= '<li>' . $item . '</li>';
		}

		$html = sprintf( '<%s>%s</%s>', $tag, $list_items, $tag );

		$data = array(
			'text' => $html,
		);

		return $this->acf_block( 'acf/text', $data );
	}

	/**
	 * Generate an ACF block comment.
	 *
	 * @param string $name Block name (e.g., 'acf/text').
	 * @param array  $data Block data.
	 * @return string Block markup.
	 */
	private function acf_block( $name, $data ) {
		$block = array(
			'name' => $name,
			'data' => $data,
			'mode' => 'preview',
		);

		return sprintf(
			'<!-- wp:%s %s /-->' . "\n\n",
			$name,
			wp_json_encode( $block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}
}
