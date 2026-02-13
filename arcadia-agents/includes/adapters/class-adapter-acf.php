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
	 * Convert a custom block to ACF block format.
	 *
	 * Transforms properties according to ACF field types:
	 * - image fields: sideload URL to get attachment ID
	 * - repeater fields: flatten to ACF storage format (field_0_subfield, field_1_subfield...)
	 * - select/radio: pass through (validation done upstream)
	 * - text/textarea/wysiwyg/url: pass through
	 *
	 * @param string $block_name The full block name (e.g., 'acf/bouton').
	 * @param array  $properties The block properties (ACF field values).
	 * @return string Block markup.
	 */
	public function custom_block( $block_name, $properties ) {
		$data = array();

		// Get field schema from registry to determine types.
		$registry = Arcadia_Block_Registry::get_instance();
		$short_name = preg_replace( '/^acf\//', '', $block_name );
		$schema = $registry->get_block_schema( $short_name );

		// Build field type lookup.
		$field_types = array();
		if ( is_array( $schema ) ) {
			foreach ( $schema as $field ) {
				$field_types[ $field['name'] ] = $field['type'];
			}
		}

		foreach ( $properties as $field_name => $value ) {
			$type = $field_types[ $field_name ] ?? 'text';

			switch ( $type ) {
				case 'image':
					// Sideload image URL to get attachment ID.
					$data[ $field_name ] = $this->sideload_image_field( $value );
					break;

				case 'repeater':
					// Flatten repeater array to ACF storage format.
					if ( is_array( $value ) ) {
						$flattened = $this->flatten_repeater( $field_name, $value );
						$data      = array_merge( $data, $flattened );
					}
					break;

				default:
					// Passthrough for text, textarea, wysiwyg, url, select, radio.
					$data[ $field_name ] = $value;
					break;
			}
		}

		return $this->acf_block( $block_name, $data );
	}

	/**
	 * Sideload an image URL and return the attachment ID.
	 *
	 * Falls back to the URL string if sideloading fails or
	 * required functions are not available.
	 *
	 * @param string $url The image URL.
	 * @return int|string Attachment ID or original URL.
	 */
	private function sideload_image_field( $url ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			return $url;
		}

		$attachment_id = media_sideload_image( $url, 0, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $url;
		}

		return $attachment_id;
	}

	/**
	 * Flatten a repeater field array to ACF storage format.
	 *
	 * ACF stores repeater data as:
	 * - field_name = row count
	 * - field_name_0_subfield = value
	 * - field_name_1_subfield = value
	 *
	 * @param string $field_name The repeater field name.
	 * @param array  $rows       Array of row objects.
	 * @return array Flattened key-value pairs.
	 */
	private function flatten_repeater( $field_name, $rows ) {
		$result = array();
		$result[ $field_name ] = count( $rows );

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $subfield => $value ) {
				$key = sprintf( '%s_%d_%s', $field_name, $index, $subfield );
				$result[ $key ] = $value;
			}
		}

		return $result;
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
