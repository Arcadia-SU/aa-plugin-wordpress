<?php
/**
 * Block generation handlers.
 *
 * @package ArcadiaAgents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Arcadia_Block_Adapter
 *
 * Defines the contract for block adapters.
 */
interface Arcadia_Block_Adapter {

	/**
	 * Convert a heading to block format.
	 *
	 * @param string $text  The heading text.
	 * @param int    $level The heading level (2 or 3).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 );

	/**
	 * Convert a paragraph to block format.
	 *
	 * @param string $text The paragraph text (may contain markdown links).
	 * @return string Block markup.
	 */
	public function paragraph( $text );

	/**
	 * Convert an image to block format.
	 *
	 * @param string $url     The image URL.
	 * @param string $alt     The alt text.
	 * @param string $caption The caption (optional).
	 * @return string Block markup.
	 */
	public function image( $url, $alt = '', $caption = '' );

	/**
	 * Convert a list to block format.
	 *
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false );

	/**
	 * Get the adapter name.
	 *
	 * @return string
	 */
	public function get_name();
}

/**
 * Class Arcadia_Gutenberg_Adapter
 *
 * Generates native Gutenberg blocks.
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
	 * @param int    $level The heading level (2 or 3).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 ) {
		$text = Arcadia_Blocks::parse_markdown_links( $text );
		$text = esc_html( $text );

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
		$text = Arcadia_Blocks::parse_markdown_links( $text );

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

		$content = sprintf(
			'<!-- wp:image -->' . "\n" .
			'<figure class="wp-block-image"><img src="%s" alt="%s"/>' .
			( $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '' ) .
			'</figure>' . "\n" .
			'<!-- /wp:image -->' . "\n\n",
			$url,
			$alt
		);

		return $content;
	}

	/**
	 * Convert a list to Gutenberg block format.
	 *
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false ) {
		$tag = $ordered ? 'ol' : 'ul';

		$list_items = '';
		foreach ( $items as $item ) {
			$item        = Arcadia_Blocks::parse_markdown_links( $item );
			$list_items .= '<li>' . $item . '</li>';
		}

		$attrs = $ordered ? '{"ordered":true}' : '{}';

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

/**
 * Class Arcadia_ACF_Adapter
 *
 * Generates ACF blocks.
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
	 * @param int    $level The heading level (2 or 3).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 ) {
		$text = Arcadia_Blocks::parse_markdown_links( $text );
		$text = esc_html( $text );

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
		$text = Arcadia_Blocks::parse_markdown_links( $text );

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
	 * @param array $items   The list items.
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false ) {
		$tag = $ordered ? 'ol' : 'ul';

		$list_items = '';
		foreach ( $items as $item ) {
			$item        = Arcadia_Blocks::parse_markdown_links( $item );
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

/**
 * Class Arcadia_Blocks
 *
 * Main class for block generation and adapter management.
 */
class Arcadia_Blocks {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Blocks|null
	 */
	private static $instance = null;

	/**
	 * The current adapter.
	 *
	 * @var Arcadia_Block_Adapter
	 */
	private $adapter;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Blocks
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->adapter = $this->detect_adapter();
	}

	/**
	 * Detect which adapter to use based on installed plugins.
	 *
	 * @return Arcadia_Block_Adapter
	 */
	private function detect_adapter() {
		// Check for user override.
		$override = get_option( 'arcadia_agents_block_adapter', '' );
		if ( 'acf' === $override ) {
			return new Arcadia_ACF_Adapter();
		}
		if ( 'gutenberg' === $override ) {
			return new Arcadia_Gutenberg_Adapter();
		}

		// Auto-detect: ACF Pro active and has registered blocks.
		if ( class_exists( 'ACF' ) && function_exists( 'acf_get_block_types' ) ) {
			$acf_blocks = acf_get_block_types();
			if ( ! empty( $acf_blocks ) ) {
				return new Arcadia_ACF_Adapter();
			}
		}

		// Default to Gutenberg native.
		return new Arcadia_Gutenberg_Adapter();
	}

	/**
	 * Get the current adapter.
	 *
	 * @return Arcadia_Block_Adapter
	 */
	public function get_adapter() {
		return $this->adapter;
	}

	/**
	 * Set a specific adapter.
	 *
	 * @param Arcadia_Block_Adapter $adapter The adapter to use.
	 */
	public function set_adapter( Arcadia_Block_Adapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * Get the current adapter name.
	 *
	 * @return string
	 */
	public function get_adapter_name() {
		return $this->adapter->get_name();
	}

	/**
	 * Convert JSON content structure to block content.
	 *
	 * @param array $json The JSON content structure from the agent.
	 * @return string Block content for post_content.
	 */
	public function json_to_blocks( $json ) {
		$content = '';

		// Process H1 if present.
		if ( ! empty( $json['h1'] ) ) {
			$content .= $this->adapter->heading( $json['h1'], 1 );
		}

		// Process sections.
		if ( ! empty( $json['sections'] ) && is_array( $json['sections'] ) ) {
			foreach ( $json['sections'] as $section ) {
				$content .= $this->process_section( $section );
			}
		}

		return $content;
	}

	/**
	 * Process a section (H2 level).
	 *
	 * @param array $section The section data.
	 * @return string Block content.
	 */
	private function process_section( $section ) {
		$content = '';

		// Section heading (H2).
		if ( ! empty( $section['heading'] ) ) {
			$content .= $this->adapter->heading( $section['heading'], 2 );
		}

		// Direct content in section.
		if ( ! empty( $section['content'] ) && is_array( $section['content'] ) ) {
			$content .= $this->process_content_array( $section['content'] );
		}

		// Subsections (H3 level).
		if ( ! empty( $section['subsections'] ) && is_array( $section['subsections'] ) ) {
			foreach ( $section['subsections'] as $subsection ) {
				$content .= $this->process_subsection( $subsection );
			}
		}

		return $content;
	}

	/**
	 * Process a subsection (H3 level).
	 *
	 * @param array $subsection The subsection data.
	 * @return string Block content.
	 */
	private function process_subsection( $subsection ) {
		$content = '';

		// Subsection heading (H3).
		if ( ! empty( $subsection['heading'] ) ) {
			$content .= $this->adapter->heading( $subsection['heading'], 3 );
		}

		// Content.
		if ( ! empty( $subsection['content'] ) && is_array( $subsection['content'] ) ) {
			$content .= $this->process_content_array( $subsection['content'] );
		}

		return $content;
	}

	/**
	 * Process an array of content items.
	 *
	 * @param array $content_array Array of content items.
	 * @return string Block content.
	 */
	private function process_content_array( $content_array ) {
		$content = '';

		foreach ( $content_array as $item ) {
			$content .= $this->process_content_item( $item );
		}

		return $content;
	}

	/**
	 * Process a single content item.
	 *
	 * @param array $item The content item.
	 * @return string Block content.
	 */
	private function process_content_item( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
			return '';
		}

		switch ( $item['type'] ) {
			case 'paragraph':
				return $this->adapter->paragraph( $item['text'] ?? '' );

			case 'image':
				return $this->adapter->image(
					$item['url'] ?? '',
					$item['alt'] ?? '',
					$item['caption'] ?? ''
				);

			case 'list':
				return $this->adapter->listing(
					$item['items'] ?? array(),
					$item['ordered'] ?? false
				);

			case 'heading':
				// In-content headings (rare, but support them).
				return $this->adapter->heading(
					$item['text'] ?? '',
					$item['level'] ?? 2
				);

			default:
				// Fallback: treat unknown types as paragraphs if they have text.
				if ( ! empty( $item['text'] ) ) {
					return $this->adapter->paragraph( $item['text'] );
				}
				return '';
		}
	}

	/**
	 * Parse markdown links in text and convert to HTML.
	 *
	 * @param string $text Text containing markdown links.
	 * @return string Text with HTML links.
	 */
	public static function parse_markdown_links( $text ) {
		// Pattern: [link text](url)
		$pattern = '/\[([^\]]+)\]\(([^)]+)\)/';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$link_text = esc_html( $matches[1] );
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
	}

	/**
	 * Check if ACF Pro is available.
	 *
	 * @return bool
	 */
	public static function is_acf_available() {
		return class_exists( 'ACF' ) && function_exists( 'acf_get_block_types' );
	}
}
