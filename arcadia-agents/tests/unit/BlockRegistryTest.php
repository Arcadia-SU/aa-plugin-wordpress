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
	 * Test that builtin blocks list advertises every adapter-handled core block.
	 *
	 * Phase 37 #3: quote/separator/table render natively (process_block) and must
	 * also be advertised so GET /blocks lists them and a bare {type:"quote"} stops
	 * 422-ing.
	 */
	public function test_builtin_blocks_contains_all_core_types(): void {
		$builtins = $this->registry->get_builtin_blocks();

		$this->assertCount( 7, $builtins );

		$types = array_column( $builtins, 'type' );
		$this->assertContains( 'core/paragraph', $types );
		$this->assertContains( 'core/heading', $types );
		$this->assertContains( 'core/image', $types );
		$this->assertContains( 'core/list', $types );
		$this->assertContains( 'core/quote', $types );
		$this->assertContains( 'core/separator', $types );
		$this->assertContains( 'core/table', $types );
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
	 * Test ACF adapter flattens repeater with sub-field keys in block comment.
	 */
	public function test_acf_custom_block_repeater_flat_with_keys(): void {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_block_types = array(
			'acf/faq' => array( 'name' => 'acf/faq', 'title' => 'FAQ' ),
		);
		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_faq',
				'title'    => 'FAQ',
				'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/faq' ) ) ),
			),
		);
		$_test_acf_fields_by_group = array(
			'group_faq' => array(
				array(
					'name'       => 'faq',
					'type'       => 'repeater',
					'key'        => 'field_faq_rep',
					'required'   => 0,
					'label'      => 'FAQ',
					'sub_fields' => array(
						array( 'name' => 'title', 'type' => 'text', 'key' => 'field_faq_title' ),
						array( 'name' => 'text', 'type' => 'textarea', 'key' => 'field_faq_text' ),
					),
				),
			),
		);

		// Reset registry to pick up new stubs.
		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$adapter = new \Arcadia_ACF_Adapter();
		$result  = $adapter->custom_block( 'acf/faq', array(
			'faq' => array(
				array( 'title' => 'Question 1', 'text' => 'Réponse 1' ),
				array( 'title' => 'Question 2', 'text' => 'Réponse 2' ),
			),
		) );

		$this->assertStringContainsString( '<!-- wp:acf/faq', $result );

		preg_match( '/<!-- wp:acf\/faq (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		// Row count.
		$this->assertEquals( 2, $data['faq'] );
		// Flat values.
		$this->assertEquals( 'Question 1', $data['faq_0_title'] );
		$this->assertEquals( 'Réponse 1', $data['faq_0_text'] );
		$this->assertEquals( 'Question 2', $data['faq_1_title'] );
		$this->assertEquals( 'Réponse 2', $data['faq_1_text'] );
		// Sub-field key references.
		$this->assertEquals( 'field_faq_title', $data['_faq_0_title'] );
		$this->assertEquals( 'field_faq_text', $data['_faq_0_text'] );
		$this->assertEquals( 'field_faq_title', $data['_faq_1_title'] );
		// Parent field key reference.
		$this->assertEquals( 'field_faq_rep', $data['_faq'] );
	}

	/**
	 * Test ACF adapter nested repeater flattened with sub-field keys (2 levels).
	 */
	public function test_acf_nested_repeater_flat_with_keys(): void {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_block_types = array(
			'acf/table' => array( 'name' => 'acf/table', 'title' => 'Table' ),
		);
		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_table',
				'title'    => 'Table',
				'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/table' ) ) ),
			),
		);
		$_test_acf_fields_by_group = array(
			'group_table' => array(
				array(
					'name'       => 'row',
					'type'       => 'repeater',
					'key'        => 'field_row',
					'required'   => 0,
					'label'      => 'Row',
					'sub_fields' => array(
						array(
							'name'       => 'cols',
							'type'       => 'repeater',
							'key'        => 'field_cols',
							'sub_fields' => array(
								array( 'name' => 'cell', 'type' => 'text', 'key' => 'field_cell' ),
							),
						),
					),
				),
			),
		);

		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$adapter = new \Arcadia_ACF_Adapter();
		$result  = $adapter->custom_block( 'acf/table', array(
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
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		// Top-level row count.
		$this->assertEquals( 2, $data['row'] );
		// Nested cols count.
		$this->assertEquals( 2, $data['row_0_cols'] );
		// Flat values.
		$this->assertEquals( 'Composant', $data['row_0_cols_0_cell'] );
		$this->assertEquals( 'Durée', $data['row_0_cols_1_cell'] );
		$this->assertEquals( 'Gros œuvre', $data['row_1_cols_0_cell'] );
		$this->assertEquals( '30-50 ans', $data['row_1_cols_1_cell'] );
		// Sub-field key references.
		$this->assertEquals( 'field_cols', $data['_row_0_cols'] );
		$this->assertEquals( 'field_cell', $data['_row_0_cols_0_cell'] );
		$this->assertEquals( 'field_cell', $data['_row_1_cols_1_cell'] );
		// Parent field key reference.
		$this->assertEquals( 'field_row', $data['_row'] );
	}

	// =========================================================================
	// Phase 39 — markdown in repeater sub-fields
	// =========================================================================

	/**
	 * Register an `acf/table` block whose `row.cols.cell` leaf has the given type.
	 *
	 * Mirrors the iSelection schema: row (repeater) → cols (repeater) → cell.
	 *
	 * @param string $cell_type ACF type declared for the `cell` sub-field.
	 */
	private function register_table_block_with_cell_type( string $cell_type ): void {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_block_types = array(
			'acf/table' => array( 'name' => 'acf/table', 'title' => 'Table' ),
		);
		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_table',
				'title'    => 'Table',
				'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/table' ) ) ),
			),
		);
		$_test_acf_fields_by_group = array(
			'group_table' => array(
				array(
					'name'       => 'row',
					'type'       => 'repeater',
					'key'        => 'field_row',
					'required'   => 0,
					'label'      => 'Row',
					'sub_fields' => array(
						array(
							'name'       => 'cols',
							'type'       => 'repeater',
							'key'        => 'field_cols',
							'sub_fields' => array(
								array( 'name' => 'cell', 'type' => $cell_type, 'key' => 'field_cell' ),
							),
						),
					),
				),
			),
		);

		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Build an `acf/table` block from one row of cells and return its `data` payload.
	 *
	 * @param array $cells Cell values for a single row.
	 * @return array Decoded ACF block data.
	 */
	private function table_data_for_cells( array $cells ): array {
		$cols = array();
		foreach ( $cells as $cell ) {
			$cols[] = array( 'cell' => $cell );
		}

		$adapter = new \Arcadia_ACF_Adapter();
		$result  = $adapter->custom_block( 'acf/table', array(
			'row' => array( array( 'cols' => $cols ) ),
		) );

		preg_match( '/<!-- wp:acf\/table (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches, 'ACF block comment not produced' );

		return json_decode( $matches[1], true )['data'];
	}

	/**
	 * Phase 39: inline markdown in a wysiwyg repeater cell is converted to HTML.
	 *
	 * Regression guard for the live bug (iSelection WP#48869 / preprod WP#88200):
	 * `**gras**` was stored literally and rendered as asterisks on screen.
	 */
	public function test_wysiwyg_repeater_cell_converts_inline_markdown(): void {
		$this->register_table_block_with_cell_type( 'wysiwyg' );

		$data = $this->table_data_for_cells( array(
			'Gros **œuvre**',
			'[voir le guide](https://example.com/guide)',
			'valeur `brute`',
			'texte *penché*',
		) );

		$this->assertSame( 'Gros <strong>œuvre</strong>', $data['row_0_cols_0_cell'] );
		$this->assertStringContainsString( '<a href="https://example.com/guide"', $data['row_0_cols_1_cell'] );
		$this->assertStringContainsString( 'voir le guide</a>', $data['row_0_cols_1_cell'] );
		$this->assertSame( 'valeur <code>brute</code>', $data['row_0_cols_2_cell'] );
		$this->assertSame( 'texte <em>penché</em>', $data['row_0_cols_3_cell'] );
	}

	/**
	 * Phase 39: conversion is inline-only — no <p> wrapper, no block promotion.
	 *
	 * A repeater row already IS the structure; wrapping every cell in <p> would
	 * add margins inside each <td>, and a cell starting with "- " must not become
	 * a list. This is the documented divergence from the top-level wysiwyg path.
	 */
	public function test_wysiwyg_repeater_cell_conversion_is_inline_only(): void {
		$this->register_table_block_with_cell_type( 'wysiwyg' );

		$data = $this->table_data_for_cells( array(
			'Cellule simple',
			'- pas une liste',
			'## pas un titre',
		) );

		$this->assertSame( 'Cellule simple', $data['row_0_cols_0_cell'] );
		$this->assertStringNotContainsString( '<p>', $data['row_0_cols_0_cell'] );
		$this->assertStringNotContainsString( '<ul>', $data['row_0_cols_1_cell'] );
		$this->assertStringNotContainsString( '<h2>', $data['row_0_cols_2_cell'] );
	}

	/**
	 * Phase 39: falsy-but-present cell values survive the transform intact.
	 *
	 * "0" is a legitimate table value — it must not be swallowed by an empty()
	 * style guard (same trap as Phase 38 #9).
	 */
	public function test_wysiwyg_repeater_cell_preserves_empty_and_zero(): void {
		$this->register_table_block_with_cell_type( 'wysiwyg' );

		$data = $this->table_data_for_cells( array( '', '0', 'x' ) );

		$this->assertSame( '', $data['row_0_cols_0_cell'] );
		$this->assertSame( '0', $data['row_0_cols_1_cell'] );
		$this->assertSame( 'x', $data['row_0_cols_2_cell'] );
	}

	/**
	 * Phase 39: markdown conversion is scoped to wysiwyg — a `text` cell is untouched.
	 *
	 * ADR-013 contract: the plugin never injects HTML into a non-wysiwyg field,
	 * whose theme template may escape it (double-escape → visible tags).
	 */
	public function test_text_repeater_cell_is_not_converted(): void {
		$this->register_table_block_with_cell_type( 'text' );

		$data = $this->table_data_for_cells( array( 'Gros **œuvre**' ) );

		$this->assertSame( 'Gros **œuvre**', $data['row_0_cols_0_cell'] );
	}

	/**
	 * Phase 39: XSS in a wysiwyg cell is stripped by the inline allowlist.
	 */
	public function test_wysiwyg_repeater_cell_strips_xss(): void {
		$this->register_table_block_with_cell_type( 'wysiwyg' );

		$data = $this->table_data_for_cells( array( '**<img src=x onerror=alert(1)>**' ) );

		$this->assertStringNotContainsString( '<img', $data['row_0_cols_0_cell'] );
		$this->assertStringNotContainsString( 'onerror', $data['row_0_cols_0_cell'] );
		$this->assertStringContainsString( '<strong>', $data['row_0_cols_0_cell'] );
	}

	/**
	 * Phase 39: the fix is generic — a flat (non-nested) wysiwyg sub-field too.
	 *
	 * Guards against a table-only special case: an `acf/faq` answer gets the same
	 * treatment as a table cell.
	 */
	public function test_wysiwyg_subfield_converted_in_flat_repeater(): void {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_block_types = array(
			'acf/faq' => array( 'name' => 'acf/faq', 'title' => 'FAQ' ),
		);
		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_faq',
				'title'    => 'FAQ',
				'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/faq' ) ) ),
			),
		);
		$_test_acf_fields_by_group = array(
			'group_faq' => array(
				array(
					'name'       => 'faq',
					'type'       => 'repeater',
					'key'        => 'field_faq',
					'required'   => 0,
					'label'      => 'FAQ',
					'sub_fields' => array(
						array( 'name' => 'question', 'type' => 'text', 'key' => 'field_q' ),
						array( 'name' => 'answer', 'type' => 'wysiwyg', 'key' => 'field_a' ),
					),
				),
			),
		);

		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$adapter = new \Arcadia_ACF_Adapter();
		$result  = $adapter->custom_block( 'acf/faq', array(
			'faq' => array(
				array(
					'question' => 'Combien de **temps** ?',
					'answer'   => 'Environ **30 ans**.',
				),
			),
		) );

		preg_match( '/<!-- wp:acf\/faq (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		$this->assertSame( 'Environ <strong>30 ans</strong>.', $data['faq_0_answer'] );
		// text sub-field untouched.
		$this->assertSame( 'Combien de **temps** ?', $data['faq_0_question'] );
	}

	/**
	 * Test repeater without schema still flattens (no key injection).
	 */
	public function test_acf_repeater_without_schema_flattens(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		// No ACF block types registered → no schema → field_types all default to 'text'.
		// The repeater case only triggers for schema-typed repeaters.
		// Without schema, values pass through as-is via the default case.
		$result = $adapter->custom_block( 'acf/unknown', array(
			'items' => array(
				array( 'q' => 'Q1' ),
			),
		) );

		preg_match( '/<!-- wp:acf\/unknown (\{.*\}) \/-->/', $result, $matches );
		$this->assertNotEmpty( $matches );
		$data = json_decode( $matches[1], true )['data'];

		// Without schema, items passes through as-is (default case, not repeater case).
		$this->assertIsArray( $data['items'] );
		$this->assertEquals( 'Q1', $data['items'][0]['q'] );
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
