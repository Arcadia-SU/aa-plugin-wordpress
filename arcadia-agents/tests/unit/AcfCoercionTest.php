<?php
/**
 * Tests for ACF canonical coercion (Phase 28).
 *
 * ACF Pro stores meta values as LONGTEXT, so a GET round-trip surfaces
 * `true_false` as `"1"`/`"0"`, image IDs as numeric strings, etc. The
 * validator coerces these to canonical PHP types before sideload + type
 * validation, so identity-passthrough GET → PUT round-trips succeed
 * without manual casting on the agent side.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';

/**
 * Test class for canonical coercion in Arcadia_ACF_Validator.
 */
class AcfCoercionTest extends TestCase {

	/**
	 * Reset singletons + global stubs before each test.
	 */
	protected function setUp(): void {
		$ref  = new ReflectionClass( 'Arcadia_Block_Registry' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$ref  = new ReflectionClass( 'Arcadia_ACF_Validator' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$ref  = new ReflectionClass( 'Arcadia_Blocks' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
		global $_test_media_sideload_result;

		$_test_acf_block_types       = array();
		$_test_acf_field_groups      = array();
		$_test_acf_fields_by_group   = array();
		$_test_media_sideload_result = null;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Invoke Arcadia_ACF_Coercer::coerce_field_to_canonical() directly.
	 *
	 * Coercer is stateless — instantiated per call.
	 *
	 * @param mixed  $value      Field value.
	 * @param string $field_type ACF field type.
	 * @return mixed Canonical (or unchanged) value.
	 */
	private function coerce( $value, $field_type ) {
		return ( new \Arcadia_ACF_Coercer() )->coerce_field_to_canonical( $value, $field_type );
	}

	/**
	 * Register an ACF block with fields in the test stubs.
	 *
	 * @param string $block_name Block name (e.g. 'acf/text-image').
	 * @param array  $fields     Field schema.
	 */
	private function register_acf_block( $block_name, $fields ) {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$short_name = preg_replace( '/^acf\//', '', $block_name );

		$_test_acf_block_types[ $block_name ] = array(
			'title' => ucfirst( $short_name ) . ' Block',
		);

		$group_key = 'group_' . $short_name;

		$_test_acf_field_groups[] = array(
			'key'      => $group_key,
			'title'    => ucfirst( $short_name ) . ' Fields',
			'location' => array(
				array(
					array( 'param' => 'block', 'operator' => '==', 'value' => $block_name ),
				),
			),
		);

		$_test_acf_fields_by_group[ $group_key ] = $fields;
	}

	private function make_json( $children ) {
		return array( 'children' => $children );
	}

	// =========================================================================
	// Per-type unit tests — coerce_field_to_canonical()
	// =========================================================================

	/**
	 * true_false: every legacy/raw representation lands on a real bool.
	 */
	public function test_coerce_true_false(): void {
		$this->assertSame( true, $this->coerce( '1', 'true_false' ) );
		$this->assertSame( true, $this->coerce( 'true', 'true_false' ) );
		$this->assertSame( true, $this->coerce( 'TRUE', 'true_false' ) );

		$this->assertSame( false, $this->coerce( '0', 'true_false' ) );
		$this->assertSame( false, $this->coerce( '', 'true_false' ) );
		$this->assertSame( false, $this->coerce( 'false', 'true_false' ) );
		$this->assertSame( false, $this->coerce( 'False', 'true_false' ) );
		$this->assertSame( false, $this->coerce( null, 'true_false' ) );

		// Already canonical.
		$this->assertSame( true, $this->coerce( true, 'true_false' ) );
		$this->assertSame( false, $this->coerce( false, 'true_false' ) );
		$this->assertSame( 1, $this->coerce( 1, 'true_false' ) );
		$this->assertSame( 0, $this->coerce( 0, 'true_false' ) );

		// Non-coercible string passes through (validator will flag).
		$this->assertSame( 'banana', $this->coerce( 'banana', 'true_false' ) );
	}

	/**
	 * image: numeric strings → int; empty / null → 0; URL/object passthrough.
	 */
	public function test_coerce_image(): void {
		$this->assertSame( 42, $this->coerce( '42', 'image' ) );
		$this->assertSame( 0, $this->coerce( '0', 'image' ) );
		$this->assertSame( 0, $this->coerce( '', 'image' ) );
		$this->assertSame( 0, $this->coerce( null, 'image' ) );

		// Already canonical.
		$this->assertSame( 99, $this->coerce( 99, 'image' ) );
		$this->assertSame( 0, $this->coerce( 0, 'image' ) );

		// URL: passthrough — H1.2 sideload handles it.
		$this->assertSame( 'https://example.com/p.jpg', $this->coerce( 'https://example.com/p.jpg', 'image' ) );

		// Object: passthrough — H1.2 sideload handles it.
		$obj = array( 'url' => 'https://example.com/p.jpg', 'alt' => 'a' );
		$this->assertSame( $obj, $this->coerce( $obj, 'image' ) );
	}

	/**
	 * file: same coercion semantics as image.
	 */
	public function test_coerce_file(): void {
		$this->assertSame( 42, $this->coerce( '42', 'file' ) );
		$this->assertSame( 0, $this->coerce( '', 'file' ) );
		$this->assertSame( 0, $this->coerce( null, 'file' ) );
		$this->assertSame( 99, $this->coerce( 99, 'file' ) );
	}

	/**
	 * gallery: each element coerced through the image rule.
	 */
	public function test_coerce_gallery(): void {
		$this->assertSame(
			array( 1, 2, 3 ),
			$this->coerce( array( '1', '2', '3' ), 'gallery' )
		);
		$this->assertSame(
			array( 1, 2, 0 ),
			$this->coerce( array( 1, '2', '' ), 'gallery' )
		);
		$this->assertSame( array(), $this->coerce( array(), 'gallery' ) );

		// Non-array passes through.
		$this->assertSame( 'foo', $this->coerce( 'foo', 'gallery' ) );
	}

	/**
	 * number: numeric strings → int (if integral) or float; non-numeric passthrough.
	 */
	public function test_coerce_number(): void {
		$this->assertSame( 42, $this->coerce( '42', 'number' ) );
		$this->assertSame( 0, $this->coerce( '0', 'number' ) );
		$this->assertSame( -5, $this->coerce( '-5', 'number' ) );
		$this->assertSame( 1.5, $this->coerce( '1.5', 'number' ) );
		$this->assertSame( 3.14, $this->coerce( '3.14', 'number' ) );

		// Already canonical.
		$this->assertSame( 7, $this->coerce( 7, 'number' ) );
		$this->assertSame( 2.5, $this->coerce( 2.5, 'number' ) );

		// Non-numeric: passthrough so check_field_type can flag.
		$this->assertSame( 'banana', $this->coerce( 'banana', 'number' ) );
		$this->assertSame( '', $this->coerce( '', 'number' ) );
	}

	/**
	 * text-types: scalar (int/float) → string; bool/null/array passthrough.
	 */
	public function test_coerce_text_types(): void {
		foreach ( array( 'text', 'textarea', 'wysiwyg', 'url', 'email', 'select', 'radio' ) as $type ) {
			$this->assertSame( 'hello', $this->coerce( 'hello', $type ) );
			$this->assertSame( '', $this->coerce( '', $type ) );
			$this->assertSame( '42', $this->coerce( 42, $type ) );
			$this->assertSame( '1.5', $this->coerce( 1.5, $type ) );

			// Bool, null, array stay so check_field_type can flag genuine errors.
			$this->assertSame( true, $this->coerce( true, $type ) );
			$this->assertSame( null, $this->coerce( null, $type ) );
			$this->assertSame( array( 'x' ), $this->coerce( array( 'x' ), $type ) );
		}
	}

	/**
	 * relationship / post_object: numeric string(s) → int(s).
	 */
	public function test_coerce_relationship(): void {
		$this->assertSame( 42, $this->coerce( '42', 'relationship' ) );
		$this->assertSame( 99, $this->coerce( 99, 'post_object' ) );

		$this->assertSame(
			array( 1, 2, 3 ),
			$this->coerce( array( '1', 2, '3' ), 'relationship' )
		);
		$this->assertSame(
			array( 1, 2 ),
			$this->coerce( array( 1, '2' ), 'post_object' )
		);

		// Non-coercible elements left alone.
		$this->assertSame(
			array( 1, 'banana' ),
			$this->coerce( array( '1', 'banana' ), 'relationship' )
		);
	}

	/**
	 * Unknown field type: passthrough.
	 */
	public function test_coerce_unknown_type_passthrough(): void {
		$this->assertSame( 'foo', $this->coerce( 'foo', 'link' ) );
		$this->assertSame( array( 'a' ), $this->coerce( array( 'a' ), 'link' ) );
		$this->assertSame( 'foo', $this->coerce( 'foo', 'some_custom_type' ) );
	}

	// =========================================================================
	// Validator integration tests
	// =========================================================================

	/**
	 * iSelection regression: acf/text-image with `is_lightbox: "1"` (raw ACF Pro)
	 * passes after coercion, with $properties mutated to canonical bool.
	 */
	public function test_text_image_is_lightbox_string_one_passes(): void {
		$this->register_acf_block( 'acf/text-image', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
			array( 'name' => 'image', 'type' => 'image', 'required' => false, 'label' => 'Image', 'key' => 'field_image' ),
			array( 'name' => 'is_lightbox', 'type' => 'true_false', 'required' => false, 'label' => 'Lightbox', 'key' => 'field_lightbox' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/text-image',
				'properties' => array(
					'title'       => 'Hello',
					'image'       => '30225', // ACF Pro raw numeric string.
					'is_lightbox' => '1',     // ACF Pro raw bool string.
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result, 'iSelection round-trip payload should pass after coercion.' );

		$props = $json['children'][0]['properties'];
		$this->assertSame( true, $props['is_lightbox'] );
		$this->assertSame( 30225, $props['image'] );
		$this->assertSame( 'Hello', $props['title'] );
	}

	/**
	 * Coercion mutates $block['properties'] in place — what gets saved is
	 * canonical, so future GETs return canonical values too.
	 */
	public function test_coercion_mutates_properties_in_place(): void {
		$this->register_acf_block( 'acf/flag', array(
			array( 'name' => 'enabled', 'type' => 'true_false', 'required' => false, 'label' => 'Enabled', 'key' => 'field_e' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/flag',
				'properties' => array( 'enabled' => '0' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( false, $json['children'][0]['properties']['enabled'] );
	}

	/**
	 * Coercion runs on repeater rows too (recurses via sub_fields).
	 */
	public function test_coercion_recurses_into_repeater_rows(): void {
		$this->register_acf_block( 'acf/checklist', array(
			array(
				'name'       => 'items',
				'type'       => 'repeater',
				'required'   => false,
				'label'      => 'Items',
				'key'        => 'field_items',
				'sub_fields' => array(
					array( 'name' => 'label', 'type' => 'text', 'key' => 'field_items_label' ),
					array( 'name' => 'done', 'type' => 'true_false', 'key' => 'field_items_done' ),
				),
			),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/checklist',
				'properties' => array(
					'items' => array(
						array( 'label' => 'Buy milk', 'done' => '1' ),
						array( 'label' => 'Walk dog', 'done' => '0' ),
					),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['items'];
		$this->assertSame( true, $rows[0]['done'] );
		$this->assertSame( false, $rows[1]['done'] );
	}

	/**
	 * Coercion runs after expand_flat_repeaters, so flat-keys round-trips
	 * with raw scalars also succeed.
	 */
	public function test_coercion_after_flat_repeater_expansion(): void {
		$this->register_acf_block( 'acf/checklist', array(
			array(
				'name'       => 'items',
				'type'       => 'repeater',
				'required'   => false,
				'label'      => 'Items',
				'key'        => 'field_items',
				'sub_fields' => array(
					array( 'name' => 'label', 'type' => 'text', 'key' => 'field_items_label' ),
					array( 'name' => 'done', 'type' => 'true_false', 'key' => 'field_items_done' ),
				),
			),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/checklist',
				'properties' => array(
					'items'         => 2,
					'items_0_label' => 'A',
					'items_0_done'  => '1',
					'items_1_label' => 'B',
					'items_1_done'  => '0',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['items'];
		$this->assertSame( true, $rows[0]['done'] );
		$this->assertSame( false, $rows[1]['done'] );
	}

	/**
	 * Identity round-trip sentinel: validate twice, second pass succeeds with
	 * no further mutation. Catches any future asymmetry that would re-introduce
	 * the GET/PUT mismatch.
	 */
	public function test_identity_round_trip_sentinel(): void {
		$this->register_acf_block( 'acf/text-image', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
			array( 'name' => 'image', 'type' => 'image', 'required' => false, 'label' => 'Image', 'key' => 'field_image' ),
			array( 'name' => 'is_lightbox', 'type' => 'true_false', 'required' => false, 'label' => 'Lightbox', 'key' => 'field_lightbox' ),
			array( 'name' => 'caption', 'type' => 'wysiwyg', 'required' => false, 'label' => 'Caption', 'key' => 'field_caption' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/text-image',
				'properties' => array(
					'title'       => 'Hello',
					'image'       => '30225',
					'is_lightbox' => '1',
					'caption'     => 'A caption.',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();

		// First pass: legacy → canonical.
		$result1 = $validator->validate_and_preprocess( $json, 'post' );
		$this->assertTrue( $result1 );
		$snapshot1 = $json['children'][0]['properties'];

		// Second pass: canonical → canonical (sentinel — no mutation).
		$result2 = $validator->validate_and_preprocess( $json, 'post' );
		$this->assertTrue( $result2 );
		$snapshot2 = $json['children'][0]['properties'];

		$this->assertSame( $snapshot1, $snapshot2, 'Second pass mutated the canonical payload — round-trip is not idempotent.' );

		// Canonical types confirmed.
		$this->assertSame( true, $snapshot2['is_lightbox'] );
		$this->assertSame( 30225, $snapshot2['image'] );
		$this->assertSame( 'Hello', $snapshot2['title'] );
	}

	/**
	 * Negative coercion: non-coercible value is left alone so check_field_type
	 * surfaces a clean error with full detail.
	 */
	public function test_negative_coercion_leaves_value_for_validator(): void {
		$this->register_acf_block( 'acf/counter', array(
			array( 'name' => 'count', 'type' => 'number', 'required' => false, 'label' => 'Count', 'key' => 'field_count' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/counter',
				'properties' => array( 'count' => 'banana' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertSame( 'count', $errors[0]['field'] );
		$this->assertSame( 'int|float', $errors[0]['expected'] );
		$this->assertSame( 'string', $errors[0]['got'] );

		// And the value stayed 'banana' (not silently mangled).
		$this->assertSame( 'banana', $json['children'][0]['properties']['count'] );
	}

	/**
	 * Numeric-string image passes without going through sideload (no HTTP).
	 *
	 * Sideload is configured to fail; if coercion sent a numeric string
	 * through it (treating "30225" as a URL), this test would 422.
	 */
	public function test_numeric_string_image_skips_sideload(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = new \WP_Error( 'should_not_run', 'Sideload should not be invoked for numeric strings.' );

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array( 'photo' => '30225' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( 30225, $json['children'][0]['properties']['photo'] );
	}
}
