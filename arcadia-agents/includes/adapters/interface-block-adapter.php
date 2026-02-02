<?php
/**
 * Block adapter interface.
 *
 * Defines the contract that all block adapters must implement.
 * Each adapter converts semantic JSON content to a specific block format
 * (e.g., Gutenberg native blocks, ACF blocks, Elementor, etc.).
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Arcadia_Block_Adapter
 *
 * Defines the contract for block adapters.
 * Implementations must provide methods to convert semantic content
 * (headings, paragraphs, images, lists) to their specific block format.
 */
interface Arcadia_Block_Adapter {

	/**
	 * Convert a heading to block format.
	 *
	 * @param string $text  The heading text (may contain markdown).
	 * @param int    $level The heading level (1-6).
	 * @return string Block markup.
	 */
	public function heading( $text, $level = 2 );

	/**
	 * Convert a paragraph to block format.
	 *
	 * @param string $text The paragraph text (may contain markdown).
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
	 * @param array $items   The list items (may contain markdown).
	 * @param bool  $ordered Whether the list is ordered.
	 * @return string Block markup.
	 */
	public function listing( $items, $ordered = false );

	/**
	 * Get the adapter name.
	 *
	 * Used for logging, debugging, and API responses.
	 *
	 * @return string The adapter identifier (e.g., 'gutenberg', 'acf').
	 */
	public function get_name();
}
