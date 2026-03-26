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
	 *
	 * Resets singletons to avoid state leakage from other test classes
	 * (e.g. AcfValidatorTest registers ACF block types that would persist).
	 */
	protected function setUp(): void {
		// Reset Block_Registry singleton.
		$ref = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Clear any ACF block types registered by other tests.
		global $_test_acf_block_types;
		$_test_acf_block_types = array();

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
		$this->assertContains( 'core/paragraph', $types );
		$this->assertContains( 'core/heading', $types );
		$this->assertContains( 'core/image', $types );
		$this->assertContains( 'core/list', $types );
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
	 * Test is_registered returns true for core/* block types.
	 */
	public function test_is_registered_core_prefix_returns_true(): void {
		$this->assertTrue( $this->registry->is_registered( 'core/paragraph' ) );
		$this->assertTrue( $this->registry->is_registered( 'core/heading' ) );
		$this->assertTrue( $this->registry->is_registered( 'core/image' ) );
		$this->assertTrue( $this->registry->is_registered( 'core/list' ) );
		$this->assertTrue( $this->registry->is_registered( 'core/unknown' ) );
		$this->assertTrue( $this->registry->is_registered( 'core/table' ) );
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
	public function test_acf_custom_block_repeater_kept_structured(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		$result = $adapter->custom_block( 'acf/faq', array(
			'items' => array(
				array( 'question' => 'Q1?', 'answer' => 'A1' ),
				array( 'question' => 'Q2?', 'answer' => 'A2' ),
			),
		) );

		// Block comment is valid.
		$this->assertStringContainsString( '<!-- wp:acf/faq', $result );
		$this->assertStringContainsString( '/-->', $result );

		// Data is structured (not flattened) — templates read $block['data'] directly.
		preg_match( '/<!-- wp:acf\/faq (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		$this->assertIsArray( $data['items'] );
		$this->assertCount( 2, $data['items'] );
		$this->assertEquals( 'Q1?', $data['items'][0]['question'] );
		$this->assertEquals( 'A2', $data['items'][1]['answer'] );
	}

	/**
	 * Test ACF adapter nested repeater kept structured in block comment (2 levels).
	 */
	public function test_acf_nested_repeater_kept_structured(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		$result = $adapter->custom_block( 'acf/table', array(
			'row' => array(
				array( 'cols' => array(
					array( 'cell' => 'Composant' ),
					array( 'cell' => 'Durée' ),
				) ),
				array( 'cols' => array(
					array( 'cell' => 'Gros œuvre' ),
					array( 'cell' => '30-50 ans' ),
				) ),
			),
		) );

		preg_match( '/<!-- wp:acf\/table (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches, 'Block comment should contain JSON data' );
		$data = json_decode( $matches[1], true )['data'];

		// Structured: row is an array of row objects, not a flat count.
		$this->assertIsArray( $data['row'] );
		$this->assertCount( 2, $data['row'] );
		$this->assertIsArray( $data['row'][0]['cols'] );
		$this->assertCount( 2, $data['row'][0]['cols'] );
		$this->assertEquals( 'Composant', $data['row'][0]['cols'][0]['cell'] );
		$this->assertEquals( '30-50 ans', $data['row'][1]['cols'][1]['cell'] );
	}

	/**
	 * Test ACF adapter 3-level nested repeater kept structured.
	 */
	public function test_acf_triple_nested_repeater_kept_structured(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		$result = $adapter->custom_block( 'acf/deep', array(
			'level1' => array(
				array( 'level2' => array(
					array( 'level3' => array(
						array( 'value' => 'deep-leaf' ),
					) ),
				) ),
			),
		) );

		preg_match( '/<!-- wp:acf\/deep (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		$this->assertIsArray( $data['level1'] );
		$this->assertEquals( 'deep-leaf', $data['level1'][0]['level2'][0]['level3'][0]['value'] );
	}

	// =========================================================================
	// accepted_formats tests (I1)
	// =========================================================================

	/**
	 * Test that image fields include accepted_formats in GET /blocks response.
	 */
	public function test_image_fields_include_accepted_formats(): void {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		// Register an ACF block with image and text fields.
		$_test_acf_block_types = array(
			'acf/hero' => array( 'name' => 'acf/hero', 'title' => 'Hero Section' ),
		);

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_hero',
				'title'    => 'Hero Fields',
				'location' => array(
					array(
						array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/hero' ),
					),
				),
			),
		);

		$_test_acf_fields_by_group = array(
			'group_hero' => array(
				array( 'name' => 'background', 'type' => 'image', 'required' => true, 'label' => 'Background Image', 'key' => 'field_bg' ),
				array( 'name' => 'title', 'type' => 'text', 'required' => true, 'label' => 'Title', 'key' => 'field_title' ),
				array( 'name' => 'logo', 'type' => 'image', 'required' => false, 'label' => 'Logo', 'key' => 'field_logo' ),
			),
		);

		// Reset singleton to pick up new blocks.
		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$registry = \Arcadia_Block_Registry::get_instance();
		$custom   = $registry->get_custom_blocks();

		$this->assertCount( 1, $custom );
		$fields = $custom[0]['fields'];
		$this->assertCount( 3, $fields );

		// Image field 'background' has accepted_formats.
		$bg = $fields[0];
		$this->assertEquals( 'image', $bg['type'] );
		$this->assertArrayHasKey( 'accepted_formats', $bg );
		$this->assertEquals( array( 'int', 'url', 'object' ), $bg['accepted_formats'] );

		// Text field 'title' does NOT have accepted_formats.
		$title = $fields[1];
		$this->assertEquals( 'text', $title['type'] );
		$this->assertArrayNotHasKey( 'accepted_formats', $title );

		// Image field 'logo' also has accepted_formats.
		$logo = $fields[2];
		$this->assertEquals( 'image', $logo['type'] );
		$this->assertArrayHasKey( 'accepted_formats', $logo );
		$this->assertEquals( array( 'int', 'url', 'object' ), $logo['accepted_formats'] );
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
