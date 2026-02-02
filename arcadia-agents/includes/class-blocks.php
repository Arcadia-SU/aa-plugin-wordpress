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
		$text = Arcadia_Blocks::parse_markdown( $text );
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
			$item        = Arcadia_Blocks::parse_markdown( $item );
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
		$text = Arcadia_Blocks::parse_markdown( $text );
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
	 * Supports ADR-013 unified block model:
	 * - Everything is a block with `type`
	 * - Container blocks use `children` for nesting
	 * - Leaf blocks use `content` for text
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

		// Process children (ADR-013 unified block model).
		if ( ! empty( $json['children'] ) && is_array( $json['children'] ) ) {
			foreach ( $json['children'] as $block ) {
				$content .= $this->process_block( $block );
			}
		}

		return $content;
	}

	/**
	 * Process a block recursively (ADR-013 unified model).
	 *
	 * @param array $block The block data.
	 * @return string Block content.
	 */
	private function process_block( $block ) {
		if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
			return '';
		}

		switch ( $block['type'] ) {
			case 'section':
				return $this->process_section_block( $block );

			case 'paragraph':
				return $this->adapter->paragraph( $block['content'] ?? '' );

			case 'text':
				// Text blocks are typically used in lists, but can appear standalone.
				return $this->adapter->paragraph( $block['content'] ?? '' );

			case 'image':
				return $this->adapter->image(
					$block['url'] ?? '',
					$block['alt'] ?? '',
					$block['caption'] ?? ''
				);

			case 'list':
				return $this->process_list_block( $block );

			case 'heading':
				// Standalone heading blocks.
				return $this->adapter->heading(
					$block['content'] ?? $block['text'] ?? '',
					$block['level'] ?? 2
				);

			default:
				// Fallback: treat unknown types as paragraphs if they have content.
				if ( ! empty( $block['content'] ) ) {
					return $this->adapter->paragraph( $block['content'] );
				}
				return '';
		}
	}

	/**
	 * Process a section block (H2 or H3).
	 *
	 * @param array $block The section block.
	 * @return string Block content.
	 */
	private function process_section_block( $block ) {
		$content = '';
		$level   = $block['level'] ?? 2;

		// Section heading if present.
		if ( ! empty( $block['heading'] ) ) {
			$content .= $this->adapter->heading( $block['heading'], $level );
		}

		// Process children recursively.
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				$content .= $this->process_block( $child );
			}
		}

		return $content;
	}

	/**
	 * Process a list block.
	 *
	 * ADR-013: list items are `text` blocks in `children` array.
	 *
	 * @param array $block The list block.
	 * @return string Block content.
	 */
	private function process_list_block( $block ) {
		$ordered = $block['ordered'] ?? false;
		$items   = array();

		// Extract text content from children (ADR-013 format).
		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				if ( isset( $child['type'] ) && 'text' === $child['type'] ) {
					$items[] = $child['content'] ?? '';
				} elseif ( isset( $child['type'] ) && 'list' === $child['type'] ) {
					// Nested list - for now, flatten it.
					$nested_items = $this->extract_list_items( $child );
					$items        = array_merge( $items, $nested_items );
				}
			}
		}

		return $this->adapter->listing( $items, $ordered );
	}

	/**
	 * Extract text items from a list block recursively.
	 *
	 * @param array $block The list block.
	 * @return array List of text items.
	 */
	private function extract_list_items( $block ) {
		$items = array();

		if ( ! empty( $block['children'] ) && is_array( $block['children'] ) ) {
			foreach ( $block['children'] as $child ) {
				if ( isset( $child['type'] ) && 'text' === $child['type'] ) {
					$items[] = $child['content'] ?? '';
				}
			}
		}

		return $items;
	}

	/**
	 * Parse inline markdown and convert to HTML.
	 *
	 * Supports: **bold**, *italic*, `code`, [link](url)
	 *
	 * @param string $text Text containing markdown.
	 * @return string Text with HTML formatting.
	 */
	public static function parse_markdown( $text ) {
		// 1. Code inline: `code` → <code>code</code>
		// Process first to protect content inside backticks from further parsing.
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $matches ) {
				return '<code>' . esc_html( $matches[1] ) . '</code>';
			},
			$text
		);

		// 2. Bold: **text** → <strong>text</strong>
		// Must be before italic to avoid matching ** as two *
		$text = preg_replace(
			'/\*\*([^*]+)\*\*/',
			'<strong>$1</strong>',
			$text
		);

		// 3. Italic: *text* → <em>text</em>
		// Only match single * not preceded/followed by another *
		$text = preg_replace(
			'/(?<!\*)\*([^*]+)\*(?!\*)/',
			'<em>$1</em>',
			$text
		);

		// 4. Links: [text](url) → <a href="url">text</a>
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

	/**
	 * Check if ACF Pro is available.
	 *
	 * @return bool
	 */
	public static function is_acf_available() {
		return class_exists( 'ACF' ) && function_exists( 'acf_get_block_types' );
	}
}
