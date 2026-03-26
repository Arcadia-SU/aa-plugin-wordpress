<?php
/**
 * ACF block adapter.
 *
 * Generates ACF (Advanced Custom Fields) blocks from semantic JSON content.
 * Standard content types (headings, paragraphs, images, lists) delegate to the
 * Gutenberg adapter for universal rendering. Only custom_block() uses the ACF
 * block format, which requires the theme to have registered specific ACF block types.
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
 * Generates blocks for ACF-enabled sites.
 *
 * Standard content types (heading, paragraph, image, list) use native Gutenberg
 * blocks via delegation — these render on ANY WordPress site regardless of theme.
 *
 * Custom blocks use ACF format with field key injection for proper ACF rendering.
 *
 * @see https://www.advancedcustomfields.com/resources/blocks/
 */
class Arcadia_ACF_Adapter implements Arcadia_Block_Adapter {

	/**
	 * Gutenberg adapter for standard block types.
	 *
	 * @var Arcadia_Gutenberg_Adapter
	 */
	private $gutenberg;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->gutenberg = new Arcadia_Gutenberg_Adapter();
	}

	/**
	 * Get the adapter name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'acf';
	}

	/**
	 * Convert a heading to native Gutenberg block format.
	 *
	 * Delegates to Gutenberg adapter for universal rendering.
	 *
	 * @param string $text  The heading text.
	 * @param int    $level The heading level (1-6).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 ) {
		return $this->gutenberg->heading( $text, $level );
	}

	/**
	 * Convert a paragraph to native Gutenberg block format.
	 *
	 * Delegates to Gutenberg adapter for universal rendering.
	 *
	 * @param string $text The paragraph text.
	 * @return string Block markup.
	 */
	public function paragraph( $text ) {
		return $this->gutenberg->paragraph( $text );
	}

	/**
	 * Convert an image to native Gutenberg block format.
	 *
	 * Delegates to Gutenberg adapter for universal rendering.
	 *
	 * @param string $url     The image URL.
	 * @param string $alt     The alt text.
	 * @param string $caption The caption (optional).
	 * @return string Block markup.
	 */
	public function image( $url, $alt = '', $caption = '' ) {
		return $this->gutenberg->image( $url, $alt, $caption );
	}

	/**
	 * Convert a list to native Gutenberg block format.
	 *
	 * Delegates to Gutenberg adapter for universal rendering.
	 *
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false ) {
		return $this->gutenberg->listing( $items, $ordered );
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
	 * Also injects ACF field key references (_field_name => field_key) when
	 * the registry provides them, ensuring ACF can map values to field definitions.
	 *
	 * @param string $block_name The full block name (e.g., 'acf/bouton').
	 * @param array  $properties The block properties (ACF field values).
	 * @return string Block markup.
	 */
	public function custom_block( $block_name, $properties ) {
		// Fallback: core/* blocks delegate to Gutenberg adapter (not ACF format).
		if ( str_starts_with( $block_name, 'core/' ) ) {
			return $this->gutenberg->custom_block( $block_name, $properties );
		}

		$data = array();

		// Get field schema from registry to determine types and keys.
		$registry   = Arcadia_Block_Registry::get_instance();
		$schema = $registry->get_block_schema( $block_name );

		// Build field type, key, and sub_fields lookups.
		$field_types      = array();
		$field_keys       = array();
		$field_sub_fields = array();
		if ( is_array( $schema ) ) {
			foreach ( $schema as $field ) {
				$field_types[ $field['name'] ] = $field['type'];
				if ( ! empty( $field['key'] ) ) {
					$field_keys[ $field['name'] ] = $field['key'];
				}
				if ( ! empty( $field['sub_fields'] ) ) {
					$field_sub_fields[ $field['name'] ] = $field['sub_fields'];
				}
			}
		}

		foreach ( $properties as $field_name => $value ) {
			$type = $field_types[ $field_name ] ?? 'text';

			switch ( $type ) {
				case 'image':
					// Empty values → no image. Store 0.
					if ( empty( $value ) || 0 === $value || '0' === $value ) {
						$data[ $field_name ] = 0;
						break;
					}
					// After H1.2 pre-processing, value is typically already an int.
					// Guard: only sideload if still a URL string or object.
					if ( is_string( $value ) ) {
						$sideloaded          = self::sideload_image_field( $value );
						$data[ $field_name ] = is_wp_error( $sideloaded ) ? 0 : $sideloaded;
					} elseif ( is_array( $value ) && ! empty( $value['url'] ) ) {
						$sideloaded          = self::sideload_image_field(
							$value['url'],
							0,
							$value['title'] ?? null,
							$value['alt'] ?? ''
						);
						$data[ $field_name ] = is_wp_error( $sideloaded ) ? 0 : $sideloaded;
					} else {
						$data[ $field_name ] = $value;
					}
					break;

				case 'repeater':
					// ACF block comments must use flat storage format with sub-field keys.
					// get_fields() in block render callbacks reads $block['data'], identifies
					// repeaters via _field → field_key, then looks for flat keys (field_0_sub).
					// Structured arrays break this — get_fields() returns false.
					$sub_fields = $field_sub_fields[ $field_name ] ?? array();
					$flat       = $this->flatten_repeater( $field_name, $value, $sub_fields );
					$data       = array_merge( $data, $flat );
					break;

				case 'wysiwyg':
					$data[ $field_name ] = Arcadia_Blocks::parse_markdown( $value );
					break;

				default:
					// Passthrough for text, textarea, url, select, radio.
					$data[ $field_name ] = $value;
					break;
			}

			// Inject ACF field key reference if available.
			// ACF uses _field_name => field_key pairs to map values to definitions.
			if ( isset( $field_keys[ $field_name ] ) ) {
				$data[ '_' . $field_name ] = $field_keys[ $field_name ];
			}
		}

		return $this->acf_block( $block_name, $data );
	}

	/**
	 * Sideload an image URL and return the attachment ID.
	 *
	 * Returns WP_Error on failure so callers can surface the error
	 * to the agent instead of silently falling back to a URL string.
	 *
	 * @param string      $url     The image URL.
	 * @param int         $post_id Parent post ID for the attachment (default 0).
	 * @param string|null $title   Attachment title (default null = derive from filename).
	 * @param string      $alt     Alt text to store as _wp_attachment_image_alt (default '').
	 * @return int|WP_Error Attachment ID or WP_Error on failure.
	 */
	public static function sideload_image_field( $url, $post_id = 0, $title = null, $alt = '' ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			return new WP_Error(
				'sideload_unavailable',
				__( 'media_sideload_image() is not available.', 'arcadia-agents' )
			);
		}

		$attachment_id = media_sideload_image( $url, $post_id, $title, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Store alt text if provided.
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return $attachment_id;
	}

	/**
	 * Flatten a repeater field array to ACF block comment format.
	 *
	 * ACF stores repeater data in block comments as:
	 * - field_name = row count (int)
	 * - field_name_0_subfield = value
	 * - _field_name_0_subfield = sub_field_key
	 * - field_name_1_subfield = value
	 * - _field_name_1_subfield = sub_field_key
	 *
	 * The sub-field key references (_key → field_key) are required for
	 * get_fields() to correctly identify and reconstruct the repeater
	 * from the flat data in $block['data'].
	 *
	 * @param string $field_name The repeater field name.
	 * @param array  $rows       Array of row objects.
	 * @param array  $sub_fields Sub-field schema from registry [{name, key, type, sub_fields?}].
	 * @return array Flattened key-value pairs with field key references.
	 */
	private function flatten_repeater( $field_name, $rows, $sub_fields = array() ) {
		$result                = array();
		$result[ $field_name ] = count( $rows );

		// Build sub-field key and type lookups.
		$sub_keys       = array();
		$sub_types      = array();
		$sub_sub_fields = array();
		foreach ( $sub_fields as $sf ) {
			$sub_keys[ $sf['name'] ]  = $sf['key'] ?? null;
			$sub_types[ $sf['name'] ] = $sf['type'] ?? 'text';
			if ( ! empty( $sf['sub_fields'] ) ) {
				$sub_sub_fields[ $sf['name'] ] = $sf['sub_fields'];
			}
		}

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $subfield => $value ) {
				$key      = sprintf( '%s_%d_%s', $field_name, $index, $subfield );
				$sub_type = $sub_types[ $subfield ] ?? 'text';

				// Nested repeater: recurse with nested sub-field schema.
				if ( 'repeater' === $sub_type && is_array( $value ) ) {
					$nested_schema = $sub_sub_fields[ $subfield ] ?? array();
					$nested        = $this->flatten_repeater( $key, $value, $nested_schema );
					$result        = array_merge( $result, $nested );
				} else {
					$result[ $key ] = $value;
				}

				// Inject sub-field key reference.
				if ( ! empty( $sub_keys[ $subfield ] ) ) {
					$result[ '_' . $key ] = $sub_keys[ $subfield ];
				}
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
