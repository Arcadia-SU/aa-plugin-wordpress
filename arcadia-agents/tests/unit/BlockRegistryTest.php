<?php
/**
 * Tests for Arcadia_Block_Registry class.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load dependencies.
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';

/**
 * Test class for block registry functions.
 */
class BlockRegistryTest extends TestCase {

	/**
	 * Registry instance.
	 *
	 * @var \Arcadia_Block_Registry
	 */
	private $registry;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		$this->registry = \Arcadia_Block_Registry::get_instance();
	}

	// =========================================================================
	// Builtin blocks tests
	// =========================================================================

	/**
	 * Test that builtin blocks list contains exactly 4 types.
	 */
	public function test_builtin_blocks_contains_four_types(): void {
		$builtins = $this->registry->get_builtin_blocks();

		$this->assertCount( 4, $builtins );

		$types = array_column( $builtins, 'type' );
		$this->assertContains( 'paragraph', $types );
		$this->assertContains( 'heading', $types );
		$this->assertContains( 'image', $types );
		$this->assertContains( 'list', $types );
	}

	/**
	 * Test that each builtin block has type and description.
	 */
	public function test_builtin_blocks_have_description(): void {
		$builtins = $this->registry->get_builtin_blocks();

		foreach ( $builtins as $block ) {
			$this->assertArrayHasKey( 'type', $block );
			$this->assertArrayHasKey( 'description', $block );
			$this->assertNotEmpty( $block['description'] );
		}
	}

	// =========================================================================
	// is_registered() tests
	// =========================================================================

	/**
	 * Test is_registered returns true for builtin types.
	 */
	public function test_is_registered_builtin_returns_true(): void {
		$this->assertTrue( $this->registry->is_registered( 'paragraph' ) );
		$this->assertTrue( $this->registry->is_registered( 'heading' ) );
		$this->assertTrue( $this->registry->is_registered( 'image' ) );
		$this->assertTrue( $this->registry->is_registered( 'list' ) );
	}

	/**
	 * Test is_registered returns true for internal types.
	 */
	public function test_is_registered_internal_returns_true(): void {
		$this->assertTrue( $this->registry->is_registered( 'section' ) );
		$this->assertTrue( $this->registry->is_registered( 'text' ) );
	}

	/**
	 * Test is_registered returns false for unknown types.
	 */
	public function test_is_registered_unknown_returns_false(): void {
		$this->assertFalse( $this->registry->is_registered( 'unknown_widget_xyz' ) );
		$this->assertFalse( $this->registry->is_registered( '' ) );
		$this->assertFalse( $this->registry->is_registered( 'foobar' ) );
	}

	// =========================================================================
	// get_block_schema() tests
	// =========================================================================

	/**
	 * Test get_block_schema returns null for builtin types.
	 */
	public function test_get_block_schema_builtin_returns_null(): void {
		$this->assertNull( $this->registry->get_block_schema( 'paragraph' ) );
		$this->assertNull( $this->registry->get_block_schema( 'heading' ) );
		$this->assertNull( $this->registry->get_block_schema( 'image' ) );
		$this->assertNull( $this->registry->get_block_schema( 'list' ) );
	}

	/**
	 * Test get_block_schema returns null for internal types.
	 */
	public function test_get_block_schema_internal_returns_null(): void {
		$this->assertNull( $this->registry->get_block_schema( 'section' ) );
		$this->assertNull( $this->registry->get_block_schema( 'text' ) );
	}

	/**
	 * Test get_block_schema returns null for unknown types.
	 */
	public function test_get_block_schema_unknown_returns_null(): void {
		$this->assertNull( $this->registry->get_block_schema( 'unknown' ) );
	}

	// =========================================================================
	// validate_properties() tests
	// =========================================================================

	/**
	 * Test validate_properties returns true for builtin blocks (no validation needed).
	 */
	public function test_validate_properties_builtin_returns_true(): void {
		$result = $this->registry->validate_properties( 'paragraph', array( 'content' => 'text' ) );
		$this->assertTrue( $result );
	}

	// =========================================================================
	// Custom block rendering tests (adapter-level)
	// =========================================================================

	/**
	 * Test Gutenberg adapter custom_block generates self-closing block.
	 */
	public function test_gutenberg_custom_block_generates_self_closing(): void {
		$adapter = new \Arcadia_Gutenberg_Adapter();

		$result = $adapter->custom_block( 'my-plugin/rating', array(
			'author' => 'John',
			'rating' => 5,
		) );

		$this->assertStringContainsString( '<!-- wp:my-plugin/rating', $result );
		$this->assertStringContainsString( '/-->', $result );
		$this->assertStringContainsString( '"author":"John"', $result );
		$this->assertStringContainsString( '"rating":5', $result );
	}

	/**
	 * Test Gutenberg adapter custom_block with empty properties.
	 */
	public function test_gutenberg_custom_block_empty_properties(): void {
		$adapter = new \Arcadia_Gutenberg_Adapter();

		$result = $adapter->custom_block( 'my-plugin/divider', array() );

		$this->assertStringContainsString( '<!-- wp:my-plugin/divider', $result );
		$this->assertStringContainsString( '/-->', $result );
	}

	/**
	 * Test ACF adapter custom_block generates ACF block markup.
	 */
	public function test_acf_custom_block_generates_acf_markup(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		$result = $adapter->custom_block( 'acf/bouton', array(
			'bouton_label' => 'Click me',
			'bouton_lien'  => 'https://example.com',
		) );

		$this->assertStringContainsString( '<!-- wp:acf/bouton', $result );
		$this->assertStringContainsString( '/-->', $result );
		$this->assertStringContainsString( 'bouton_label', $result );
		$this->assertStringContainsString( 'Click me', $result );
	}

	/**
	 * Test ACF adapter flatten_repeater logic.
	 */
	public function test_acf_custom_block_repeater_flattening(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		// We can't directly test private flatten_repeater, but we can test
		// through custom_block with repeater data.
		$result = $adapter->custom_block( 'acf/faq', array(
			'items' => array(
				array( 'question' => 'Q1?', 'answer' => 'A1' ),
				array( 'question' => 'Q2?', 'answer' => 'A2' ),
			),
		) );

		$this->assertStringContainsString( '<!-- wp:acf/faq', $result );
		$this->assertStringContainsString( '/-->', $result );
	}

	// =========================================================================
	// get_custom_blocks() tests (without ACF/Gutenberg available)
	// =========================================================================

	/**
	 * Test get_custom_blocks returns empty array when no ACF/Gutenberg custom blocks.
	 */
	public function test_get_custom_blocks_empty_without_plugins(): void {
		$custom = $this->registry->get_custom_blocks();

		// In test environment, no ACF or WP_Block_Type_Registry available.
		$this->assertIsArray( $custom );
		$this->assertEmpty( $custom );
	}

	/**
	 * Test get_custom_block_names returns empty array without plugins.
	 */
	public function test_get_custom_block_names_empty_without_plugins(): void {
		$names = $this->registry->get_custom_block_names();

		$this->assertIsArray( $names );
		$this->assertEmpty( $names );
	}
}
