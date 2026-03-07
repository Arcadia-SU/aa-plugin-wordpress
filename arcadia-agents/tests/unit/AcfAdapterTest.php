<?php
/**
 * Tests for Arcadia_ACF_Adapter class.
 *
 * Validates that custom_block() correctly looks up field schemas
 * from the block registry and applies type-specific transformations
 * (wysiwyg → HTML, field key injection, passthrough for text/url/select).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load dependencies.
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';

/**
 * Test class for ACF adapter custom_block() behavior.
 */
class AcfAdapterTest extends TestCase {

	/**
	 * ACF adapter instance.
	 *
	 * @var \Arcadia_ACF_Adapter
	 */
	private $adapter;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		// Reset Block_Registry singleton to avoid state leakage.
		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Also reset the cache inside the new instance.
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
		$_test_acf_block_types     = array();
		$_test_acf_field_groups    = array();
		$_test_acf_fields_by_group = array();

		$this->adapter = new \Arcadia_ACF_Adapter();
	}

	// =========================================================================
	// Schema lookup fix (J1/J2 root cause)
	// =========================================================================

	/**
	 * Test that wysiwyg field in ACF block output contains HTML, not markdown.
	 *
	 * This validates the J1 fix: the schema lookup now matches the full
	 * block name (acf/hero) so field types are correctly identified and
	 * wysiwyg fields get markdown→HTML conversion.
	 */
	public function test_wysiwyg_field_in_acf_block_contains_html(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'contenu', 'type' => 'wysiwyg', 'key' => 'field_contenu' ),
		) );

		$result = $this->adapter->custom_block( 'acf/hero', array(
			'contenu' => 'This is **bold** text.',
		) );

		// Should contain HTML, not markdown.
		$this->assertStringContainsString( '<strong>bold</strong>', $result );
		$this->assertStringNotContainsString( '**bold**', $result );
	}

	/**
	 * Test that text/url/select fields in ACF block are NOT converted.
	 *
	 * These field types should pass through unchanged — no markdown conversion.
	 */
	public function test_text_url_select_fields_are_not_converted(): void {
		$this->register_acf_block( 'acf/bouton', array(
			array( 'name' => 'label', 'type' => 'text', 'key' => 'field_label' ),
			array( 'name' => 'lien', 'type' => 'url', 'key' => 'field_lien' ),
			array( 'name' => 'style', 'type' => 'select', 'key' => 'field_style' ),
		) );

		$result = $this->adapter->custom_block( 'acf/bouton', array(
			'label' => 'Click **here**',
			'lien'  => 'https://example.com',
			'style' => 'primary',
		) );

		// Text should NOT be converted (still contains **)
		$this->assertStringContainsString( 'Click **here**', $result );
		$this->assertStringContainsString( 'https://example.com', $result );
		$this->assertStringContainsString( 'primary', $result );
	}

	/**
	 * Test that ACF block output includes _field_name → field_key references.
	 *
	 * This validates the J2 fix: with the schema lookup working, field keys
	 * are now injected so ACF's get_field() can format values correctly.
	 */
	public function test_acf_block_includes_field_key_references(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'titre', 'type' => 'text', 'key' => 'field_titre_abc' ),
			array( 'name' => 'contenu', 'type' => 'wysiwyg', 'key' => 'field_contenu_xyz' ),
		) );

		$result = $this->adapter->custom_block( 'acf/hero', array(
			'titre'   => 'Hello',
			'contenu' => 'World',
		) );

		// Field key references should be present in the JSON.
		$this->assertStringContainsString( '"_titre":"field_titre_abc"', $result );
		$this->assertStringContainsString( '"_contenu":"field_contenu_xyz"', $result );
	}

	/**
	 * Test that image field in ACF block gets correct field key reference.
	 *
	 * Image fields are handled specially (sideloading), but field key
	 * injection should still work for them.
	 */
	public function test_image_field_gets_field_key_reference(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'photo', 'type' => 'image', 'key' => 'field_photo_123' ),
		) );

		// Pass an int (already sideloaded) to avoid hitting media_sideload_image.
		$result = $this->adapter->custom_block( 'acf/card', array(
			'photo' => 42,
		) );

		// Field key reference should be present.
		$this->assertStringContainsString( '"_photo":"field_photo_123"', $result );
		// Value should be the attachment ID.
		$this->assertStringContainsString( '"photo":42', $result );
	}

	/**
	 * Test that blocks without schema still work (passthrough).
	 *
	 * When the registry has no schema for a block, all fields should
	 * be passed through unchanged (graceful degradation).
	 */
	public function test_block_without_schema_passes_through(): void {
		// Don't register anything — schema will be null.
		$result = $this->adapter->custom_block( 'acf/unknown', array(
			'title' => 'Hello **world**',
		) );

		// Without schema, everything is passthrough (default case = text).
		$this->assertStringContainsString( 'Hello **world**', $result );
		// Should still produce valid ACF block markup.
		$this->assertStringContainsString( '<!-- wp:acf/unknown', $result );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Register a fake ACF block type with fields in the test stubs.
	 *
	 * Sets up both the block type registration (acf_get_block_types)
	 * and the field group (acf_get_field_groups + acf_get_fields).
	 *
	 * @param string $block_name Full block name (e.g., 'acf/hero').
	 * @param array  $fields     Array of field descriptors with name, type, key.
	 */
	private function register_acf_block( $block_name, $fields ) {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		// Register block type.
		$_test_acf_block_types[ $block_name ] = array(
			'name'  => $block_name,
			'title' => ucfirst( preg_replace( '/^acf\//', '', $block_name ) ),
		);

		// Create a field group targeting this block.
		$group_key = 'group_' . preg_replace( '/[^a-z0-9]/', '_', $block_name );

		$_test_acf_field_groups[] = array(
			'key'      => $group_key,
			'title'    => 'Fields for ' . $block_name,
			'location' => array(
				array(
					array(
						'param'    => 'block',
						'operator' => '==',
						'value'    => $block_name,
					),
				),
			),
		);

		// Register fields with required ACF structure.
		$acf_fields = array();
		foreach ( $fields as $field ) {
			$acf_fields[] = array(
				'name'     => $field['name'],
				'type'     => $field['type'],
				'key'      => $field['key'],
				'label'    => ucfirst( $field['name'] ),
				'required' => $field['required'] ?? false,
			);
		}

		$_test_acf_fields_by_group[ $group_key ] = $acf_fields;

		// Reset the registry singleton so it re-discovers blocks.
		$ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}
