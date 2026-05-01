<?php
/**
 * Tests for ACF block validation at publish (Phase 11 — H1).
 *
 * Covers:
 * - H1.1: Schema validation (type checks against ACF field definitions)
 * - H1.2: Image URL auto-sideload (URL string → attachment ID)
 * - H1.3: Render test (render_block() each block, rollback on failure)
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required classes in dependency order.
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';

/**
 * Test class for Arcadia_ACF_Validator.
 */
class AcfValidatorTest extends TestCase {

	/**
	 * Set up test fixtures.
	 *
	 * Resets singletons and global stubs before each test.
	 */
	protected function setUp(): void {
		// Reset Block Registry singleton (clears custom_blocks_cache).
		$ref  = new \ReflectionClass( 'Arcadia_Block_Registry' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Reset ACF Validator singleton.
		$ref  = new \ReflectionClass( 'Arcadia_ACF_Validator' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Reset Arcadia_Blocks singleton (so adapter re-detects).
		$ref  = new \ReflectionClass( 'Arcadia_Blocks' );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Reset global stubs.
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
		global $_test_media_sideload_result, $_test_render_block_callback;
		global $_test_posts, $_test_parse_blocks_results;

		$_test_acf_block_types     = array();
		$_test_acf_field_groups    = array();
		$_test_acf_fields_by_group = array();
		$_test_media_sideload_result = null;
		$_test_render_block_callback = null;
		$_test_posts               = array();
		$_test_parse_blocks_results = array();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Register an ACF block with fields in the test stubs.
	 *
	 * Sets up the global stubs so Block Registry discovers the block
	 * with the given field schema.
	 *
	 * @param string $block_name Full block name (e.g. 'acf/image').
	 * @param array  $fields     Array of ACF field descriptors.
	 * @param array  $post_types Optional post_type restrictions. Empty = no restriction.
	 */
	private function register_acf_block( $block_name, $fields, $post_types = array() ) {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$short_name = preg_replace( '/^acf\//', '', $block_name );

		$_test_acf_block_types[ $block_name ] = array(
			'title' => ucfirst( $short_name ) . ' Block',
		);

		$group_key = 'group_' . $short_name;

		// Build location rules: always include a block rule.
		$location = array();
		if ( empty( $post_types ) ) {
			// Block-only rule (no post_type restriction).
			$location[] = array(
				array( 'param' => 'block', 'operator' => '==', 'value' => $block_name ),
			);
		} else {
			// One OR-group per post_type, each AND-ed with the block rule.
			foreach ( $post_types as $pt ) {
				$location[] = array(
					array( 'param' => 'block', 'operator' => '==', 'value' => $block_name ),
					array( 'param' => 'post_type', 'operator' => '==', 'value' => $pt ),
				);
			}
		}

		$_test_acf_field_groups[] = array(
			'key'      => $group_key,
			'title'    => ucfirst( $short_name ) . ' Fields',
			'location' => $location,
		);

		$_test_acf_fields_by_group[ $group_key ] = $fields;
	}

	/**
	 * Build a minimal block JSON structure with children.
	 *
	 * @param array $children Block children.
	 * @return array JSON structure.
	 */
	private function make_json( $children ) {
		return array( 'children' => $children );
	}

	// =========================================================================
	// H1.1 — Schema Validation Tests
	// =========================================================================

	/**
	 * Test: valid block with correct types passes validation.
	 */
	public function test_valid_block_passes_validation(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
			array( 'name' => 'image', 'type' => 'image', 'required' => false, 'label' => 'Image', 'key' => 'field_image' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(
					'title' => 'Hello World',
					'image' => 42,
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
	}

	/**
	 * Test: image field rejects string after failed sideload.
	 */
	public function test_image_field_rejects_string_after_failed_sideload(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = new \WP_Error( 'download_failed', 'Could not download image.' );

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'image', 'type' => 'image', 'required' => false, 'label' => 'Image', 'key' => 'field_img' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array( 'image' => 'https://example.com/photo.jpg' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'acf_validation_failed', $result->get_error_code() );

		$data   = $result->get_error_data();
		$errors = $data['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'image', $errors[0]['field'] );
		$this->assertEquals( 'int (attachment ID)', $errors[0]['expected'] );
		$this->assertStringContainsString( 'sideload failed', $errors[0]['suggestion'] );
	}

	/**
	 * Test: text field rejects non-string value.
	 */
	public function test_text_field_rejects_non_string(): void {
		$this->register_acf_block( 'acf/cta', array(
			array( 'name' => 'label', 'type' => 'text', 'required' => false, 'label' => 'Label', 'key' => 'field_label' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/cta',
				'properties' => array( 'label' => 123 ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'label', $errors[0]['field'] );
		$this->assertEquals( 'string', $errors[0]['expected'] );
		$this->assertEquals( 'integer', $errors[0]['got'] );
	}

	/**
	 * Test: number field rejects string value.
	 */
	public function test_number_field_rejects_string(): void {
		$this->register_acf_block( 'acf/counter', array(
			array( 'name' => 'count', 'type' => 'number', 'required' => false, 'label' => 'Count', 'key' => 'field_count' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/counter',
				'properties' => array( 'count' => 'ten' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$errors = $result->get_error_data()['errors'];
		$this->assertEquals( 'count', $errors[0]['field'] );
		$this->assertEquals( 'int|float', $errors[0]['expected'] );
	}

	/**
	 * Test: required field missing returns error.
	 */
	public function test_required_field_missing(): void {
		$this->register_acf_block( 'acf/cta', array(
			array( 'name' => 'label', 'type' => 'text', 'required' => true, 'label' => 'Label', 'key' => 'field_label' ),
			array( 'name' => 'url', 'type' => 'url', 'required' => true, 'label' => 'URL', 'key' => 'field_url' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/cta',
				'properties' => array( 'label' => 'Click me' ),
				// 'url' missing.
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'url', $errors[0]['field'] );
		$this->assertEquals( 'required', $errors[0]['expected'] );
		$this->assertEquals( 'missing', $errors[0]['got'] );
	}

	/**
	 * Test: error format matches api-contract spec.
	 */
	public function test_error_format_matches_spec(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array( 'title' => 999 ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 422, $result->get_error_data()['status'] );

		$error = $result->get_error_data()['errors'][0];
		$this->assertArrayHasKey( 'block_index', $error );
		$this->assertArrayHasKey( 'block_type', $error );
		$this->assertArrayHasKey( 'field', $error );
		$this->assertArrayHasKey( 'expected', $error );
		$this->assertArrayHasKey( 'got', $error );
		$this->assertArrayHasKey( 'suggestion', $error );
		$this->assertEquals( 'acf/hero', $error['block_type'] );
	}

	/**
	 * Test: collects multiple errors from different blocks.
	 */
	public function test_collects_multiple_errors(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
			array( 'name' => 'count', 'type' => 'number', 'required' => false, 'label' => 'Count', 'key' => 'field_c' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array( 'title' => 111 ),
			),
			array(
				'type'       => 'acf/hero',
				'properties' => array( 'count' => 'not-a-number' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 2, $errors );
		$this->assertEquals( 0, $errors[0]['block_index'] );
		$this->assertEquals( 1, $errors[1]['block_index'] );
	}

	/**
	 * Test: non-ACF blocks (core/paragraph, etc.) are skipped.
	 */
	public function test_skips_non_acf_blocks(): void {
		$json = $this->make_json( array(
			array( 'type' => 'paragraph', 'content' => 'Hello' ),
			array( 'type' => 'core/heading', 'content' => 'Title', 'level' => 2 ),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
	}

	/**
	 * Test: validation skipped when ACF block has no schema.
	 */
	public function test_validation_skipped_when_no_schema(): void {
		// Register block but with no fields.
		global $_test_acf_block_types;
		$_test_acf_block_types['acf/empty'] = array( 'title' => 'Empty Block' );
		// No field groups → no schema.

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/empty',
				'properties' => array( 'anything' => 'goes' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// H1.2 — Image URL Auto-Sideload Tests
	// =========================================================================

	/**
	 * Test: image URL is sideloaded and replaced with attachment ID.
	 */
	public function test_image_url_sideloaded_to_attachment_id(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 42;

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array( 'photo' => 'https://example.com/photo.jpg' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		// URL replaced with attachment ID.
		$this->assertEquals( 42, $json['children'][0]['properties']['photo'] );
	}

	/**
	 * Test: sideload failure returns 422 error.
	 */
	public function test_sideload_failure_returns_error(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = new \WP_Error( 'http_404', 'Not Found' );

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array( 'photo' => 'https://example.com/missing.jpg' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 422, $result->get_error_data()['status'] );
	}

	/**
	 * Test: image field with int value passes without sideload.
	 */
	public function test_image_int_passes_without_sideload(): void {
		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array( 'photo' => 99 ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		// Value unchanged.
		$this->assertEquals( 99, $json['children'][0]['properties']['photo'] );
	}

	/**
	 * Test: multiple image fields in the same block are all sideloaded.
	 */
	public function test_multiple_images_sideloaded(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 50;

		$this->register_acf_block( 'acf/gallery', array(
			array( 'name' => 'left', 'type' => 'image', 'required' => false, 'label' => 'Left', 'key' => 'field_left' ),
			array( 'name' => 'right', 'type' => 'image', 'required' => false, 'label' => 'Right', 'key' => 'field_right' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/gallery',
				'properties' => array(
					'left'  => 'https://example.com/a.jpg',
					'right' => 'https://example.com/b.jpg',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertEquals( 50, $json['children'][0]['properties']['left'] );
		$this->assertEquals( 50, $json['children'][0]['properties']['right'] );
	}

	/**
	 * Test: image in nested child block is sideloaded.
	 */
	public function test_sideload_in_nested_block(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 77;

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'     => 'section',
				'heading'  => 'Section',
				'children' => array(
					array(
						'type'       => 'acf/image',
						'properties' => array( 'photo' => 'https://example.com/nested.jpg' ),
					),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertEquals( 77, $json['children'][0]['children'][0]['properties']['photo'] );
	}

	// =========================================================================
	// H1.3 — Render Test Tests
	// =========================================================================

	/**
	 * Test: render success returns true.
	 */
	public function test_render_success_returns_true(): void {
		global $_test_posts, $_test_parse_blocks_results, $_test_render_block_callback;

		$content = '<!-- wp:acf/hero {"name":"acf/hero"} /-->';
		$_test_posts[100] = (object) array(
			'ID'           => 100,
			'post_content' => $content,
		);
		$_test_parse_blocks_results[ $content ] = array(
			array( 'blockName' => 'acf/hero', 'attrs' => array(), 'innerHTML' => '' ),
		);
		$_test_render_block_callback = function () {
			return '<div>Hero rendered OK</div>';
		};

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->render_test( 100 );

		$this->assertTrue( $result );
	}

	/**
	 * Test: render failure deletes post and returns 422.
	 */
	public function test_render_failure_rollbacks_post(): void {
		global $_test_posts, $_test_parse_blocks_results, $_test_render_block_callback;

		$content = '<!-- wp:acf/broken /-->';
		$_test_posts[200] = (object) array(
			'ID'           => 200,
			'post_content' => $content,
		);
		$_test_parse_blocks_results[ $content ] = array(
			array( 'blockName' => 'acf/broken', 'attrs' => array(), 'innerHTML' => '' ),
		);
		$_test_render_block_callback = function () {
			throw new \RuntimeException( '_get_img_tag() expects array, got string' );
		};

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->render_test( 200 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'render_test_failed', $result->get_error_code() );
		$this->assertEquals( 422, $result->get_error_data()['status'] );
	}

	/**
	 * Test: render test skipped when render_block() is unavailable.
	 *
	 * Since our bootstrap defines the stub, we test the graceful skip path
	 * indirectly: an empty post content skips all rendering.
	 */
	public function test_render_test_skipped_when_empty_content(): void {
		global $_test_posts;

		$_test_posts[300] = (object) array(
			'ID'           => 300,
			'post_content' => '',
		);

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->render_test( 300 );

		$this->assertTrue( $result );
	}

	/**
	 * Test: render test on non-existent post returns true (graceful skip).
	 */
	public function test_render_test_nonexistent_post(): void {
		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->render_test( 999999 );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// H1.2 — Image Object Format Tests
	// =========================================================================

	/**
	 * Test: image object with URL, alt, and title is sideloaded with metadata.
	 */
	public function test_image_object_sideloaded_with_metadata(): void {
		global $_test_media_sideload_result, $_test_post_meta;
		$_test_media_sideload_result = 101;

		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(
					'photo' => array(
						'url'   => 'https://example.com/hero.jpg',
						'alt'   => 'A beautiful sunset',
						'title' => 'Sunset Photo',
					),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertEquals( 101, $json['children'][0]['properties']['photo'] );
		$this->assertEquals(
			'A beautiful sunset',
			$_test_post_meta[101]['_wp_attachment_image_alt'] ?? ''
		);
	}

	/**
	 * Test: image object with only URL (no alt/title) sideloads OK.
	 */
	public function test_image_object_without_alt_sideloaded(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 102;

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array(
					'photo' => array( 'url' => 'https://example.com/minimal.jpg' ),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertEquals( 102, $json['children'][0]['properties']['photo'] );
	}

	/**
	 * Test: image object sideload failure returns 422 error.
	 */
	public function test_image_object_sideload_failure_returns_error(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = new \WP_Error( 'download_failed', 'Could not download image.' );

		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(
					'photo' => array(
						'url' => 'https://example.com/broken.jpg',
						'alt' => 'Broken',
					),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 422, $result->get_error_data()['status'] );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'photo', $errors[0]['field'] );
		$this->assertStringContainsString( 'object (URL + metadata)', $errors[0]['got'] );
		$this->assertStringContainsString( 'sideload failed', $errors[0]['suggestion'] );
	}

	/**
	 * Test: image object value is replaced with int in block properties.
	 */
	public function test_image_object_replaces_value_with_int(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 103;

		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'cover', 'type' => 'image', 'required' => false, 'label' => 'Cover', 'key' => 'field_cover' ),
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array(
					'cover' => array( 'url' => 'https://example.com/cover.jpg', 'alt' => 'Cover' ),
					'title' => 'Test Card',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertIsInt( $json['children'][0]['properties']['cover'] );
		$this->assertEquals( 103, $json['children'][0]['properties']['cover'] );
		$this->assertEquals( 'Test Card', $json['children'][0]['properties']['title'] );
	}

	/**
	 * Test: sideloaded IDs are tracked and cleared.
	 */
	public function test_sideloaded_ids_tracked(): void {
		global $_test_media_sideload_result;
		$_test_media_sideload_result = 200;

		$this->register_acf_block( 'acf/image', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/image',
				'properties' => array(
					'photo' => array( 'url' => 'https://example.com/tracked.jpg' ),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );

		$ids = $validator->get_and_clear_sideloaded_ids();
		$this->assertCount( 1, $ids );
		$this->assertEquals( 200, $ids[0] );

		$ids2 = $validator->get_and_clear_sideloaded_ids();
		$this->assertEmpty( $ids2 );
	}

	// =========================================================================
	// N1 — Empty Image Values (Postel's Law)
	// =========================================================================

	/**
	 * Test: empty string on image field → normalized to 0, no sideload.
	 */
	public function test_image_empty_string_normalized_to_zero(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'icon', 'type' => 'image', 'required' => false, 'label' => 'Icon', 'key' => 'field_icon' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array( 'icon' => '' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( 0, $json['children'][0]['properties']['icon'] );
	}

	/**
	 * Test: null on image field → normalized to 0, no sideload.
	 */
	public function test_image_null_normalized_to_zero(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'icon', 'type' => 'image', 'required' => false, 'label' => 'Icon', 'key' => 'field_icon' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array( 'icon' => null ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( 0, $json['children'][0]['properties']['icon'] );
	}

	/**
	 * Test: integer 0 on image field → accepted as "no image", no sideload.
	 */
	public function test_image_zero_accepted_as_no_image(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'icon', 'type' => 'image', 'required' => false, 'label' => 'Icon', 'key' => 'field_icon' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array( 'icon' => 0 ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( 0, $json['children'][0]['properties']['icon'] );
	}

	/**
	 * Test: string "0" on image field → normalized to 0, no sideload.
	 */
	public function test_image_string_zero_normalized(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'icon', 'type' => 'image', 'required' => false, 'label' => 'Icon', 'key' => 'field_icon' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array( 'icon' => '0' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( 0, $json['children'][0]['properties']['icon'] );
	}

	/**
	 * Test: absent image field → no error (not required).
	 */
	public function test_image_absent_no_error(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'icon', 'type' => 'image', 'required' => false, 'label' => 'Icon', 'key' => 'field_icon' ),
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array( 'title' => 'No icon' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// H1.1 — Post Type Filtering Tests
	// =========================================================================

	/**
	 * Test: block available for the target post type passes validation.
	 */
	public function test_block_available_for_post_type_passes(): void {
		$this->register_acf_block( 'acf/article-hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
		), array( 'article' ) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/article-hero',
				'properties' => array( 'title' => 'Hello' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'article' );

		$this->assertTrue( $result );
	}

	/**
	 * Test: block unavailable for the target post type returns 422 error.
	 */
	public function test_block_unavailable_for_post_type_returns_error(): void {
		$this->register_acf_block( 'acf/page-hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
		), array( 'page' ) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/page-hero',
				'properties' => array( 'title' => 'Hello' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 422, $result->get_error_data()['status'] );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'acf/page-hero', $errors[0]['block_type'] );
		$this->assertStringContainsString( 'not registered for this post_type', $errors[0]['got'] );
	}

	/**
	 * Test: block with no post_type restriction passes for any post type.
	 */
	public function test_block_with_no_post_type_restriction_passes(): void {
		$this->register_acf_block( 'acf/universal', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/universal',
				'properties' => array( 'title' => 'Works everywhere' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );
		$this->assertTrue( $result );

		$json2 = $this->make_json( array(
			array(
				'type'       => 'acf/universal',
				'properties' => array( 'title' => 'Still works' ),
			),
		) );
		$result2 = $validator->validate_and_preprocess( $json2, 'article' );
		$this->assertTrue( $result2 );
	}

	/**
	 * Test: post_type check error format matches spec.
	 */
	public function test_post_type_check_error_format(): void {
		$this->register_acf_block( 'acf/restricted', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_t' ),
		), array( 'page' ) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/restricted',
				'properties' => array( 'title' => 'Hello' ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$error = $result->get_error_data()['errors'][0];
		$this->assertArrayHasKey( 'block_index', $error );
		$this->assertArrayHasKey( 'block_type', $error );
		$this->assertArrayHasKey( 'expected', $error );
		$this->assertArrayHasKey( 'got', $error );
		$this->assertArrayHasKey( 'suggestion', $error );
		$this->assertEquals( 0, $error['block_index'] );
		$this->assertEquals( 'acf/restricted', $error['block_type'] );
		$this->assertStringContainsString( 'post_type', $error['expected'] );
	}

	// =========================================================================
	// N3 — Dry-run Validation (validate-content)
	// =========================================================================

	/**
	 * Test: dry-run mode skips sideload but accepts image URLs as valid.
	 */
	public function test_dry_run_accepts_image_url_without_sideload(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'photo', 'type' => 'image', 'required' => false, 'label' => 'Photo', 'key' => 'field_photo' ),
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(
					'photo' => 'https://example.com/photo.jpg',
					'title' => 'Hello',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post', true );

		$this->assertTrue( $result );
		// URL should NOT be replaced (no sideload in dry-run).
		$this->assertEquals( 'https://example.com/photo.jpg', $json['children'][0]['properties']['photo'] );
	}

	/**
	 * Test: dry-run mode still catches invalid types.
	 */
	public function test_dry_run_catches_type_errors(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => false, 'label' => 'Title', 'key' => 'field_title' ),
			array( 'name' => 'count', 'type' => 'number', 'required' => false, 'label' => 'Count', 'key' => 'field_count' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(
					'title' => 123,   // Should be string.
					'count' => 'abc', // Should be int.
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 2, $errors );
	}

	/**
	 * Test: dry-run mode still catches required field errors.
	 */
	public function test_dry_run_catches_required_field_missing(): void {
		$this->register_acf_block( 'acf/hero', array(
			array( 'name' => 'title', 'type' => 'text', 'required' => true, 'label' => 'Title', 'key' => 'field_title' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/hero',
				'properties' => array(),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'missing', $errors[0]['got'] );
	}

	/**
	 * Test: dry-run accepts image object format without sideload.
	 */
	public function test_dry_run_accepts_image_object(): void {
		$this->register_acf_block( 'acf/card', array(
			array( 'name' => 'cover', 'type' => 'image', 'required' => false, 'label' => 'Cover', 'key' => 'field_cover' ),
		) );

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/card',
				'properties' => array(
					'cover' => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Alt text' ),
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post', true );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// H1.3 — Render Test Tests
	// =========================================================================

	/**
	 * Test: render error contains block details.
	 */
	public function test_render_error_contains_block_details(): void {
		global $_test_posts, $_test_parse_blocks_results, $_test_render_block_callback;

		$content = '<!-- wp:acf/broken /-->';
		$_test_posts[400] = (object) array(
			'ID'           => 400,
			'post_content' => $content,
		);
		$_test_parse_blocks_results[ $content ] = array(
			array( 'blockName' => 'acf/broken', 'attrs' => array(), 'innerHTML' => '' ),
		);
		$_test_render_block_callback = function () {
			throw new \RuntimeException( 'Template error in acf/broken' );
		};

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->render_test( 400 );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertEquals( 'acf/broken', $errors[0]['block_type'] );
		$this->assertArrayHasKey( 'block_index', $errors[0] );
		$this->assertStringContainsString( 'Template error', $errors[0]['error'] );
	}

	// =========================================================================
	// Phase 27 — Flat-keys repeater expansion (GET → PUT symmetry)
	// =========================================================================

	/**
	 * Test: acf/numeric-list flat-keys → expand to array of rows.
	 */
	public function test_flat_repeater_numeric_list_expands(): void {
		$this->register_acf_block(
			'acf/numeric-list',
			array(
				array(
					'name'       => 'list',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'List',
					'key'        => 'field_list',
					'sub_fields' => array(
						array( 'name' => 'title', 'type' => 'text', 'key' => 'field_list_title' ),
						array( 'name' => 'text',  'type' => 'wysiwyg', 'key' => 'field_list_text' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/numeric-list',
				'properties' => array(
					'list'         => 2,
					'list_0_title' => 'Étape 1',
					'list_0_text'  => 'Texte un',
					'list_1_title' => 'Étape 2',
					'list_1_text'  => 'Texte deux',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result, 'Flat-keys repeater should pass validation after expansion.' );

		$props = $json['children'][0]['properties'];
		$this->assertIsArray( $props['list'] );
		$this->assertCount( 2, $props['list'] );
		$this->assertSame( 'Étape 1', $props['list'][0]['title'] );
		$this->assertSame( 'Texte un', $props['list'][0]['text'] );
		$this->assertSame( 'Étape 2', $props['list'][1]['title'] );
		$this->assertSame( 'Texte deux', $props['list'][1]['text'] );
		// Flat keys consumed.
		$this->assertArrayNotHasKey( 'list_0_title', $props );
		$this->assertArrayNotHasKey( 'list_1_text', $props );
	}

	/**
	 * Test: acf/faq flat-keys → expand to array of rows.
	 */
	public function test_flat_repeater_faq_expands(): void {
		$this->register_acf_block(
			'acf/faq',
			array(
				array(
					'name'       => 'faq',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'FAQ',
					'key'        => 'field_faq',
					'sub_fields' => array(
						array( 'name' => 'question', 'type' => 'text', 'key' => 'field_faq_question' ),
						array( 'name' => 'answer',   'type' => 'wysiwyg', 'key' => 'field_faq_answer' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/faq',
				'properties' => array(
					'faq'            => 1,
					'faq_0_question' => 'Q?',
					'faq_0_answer'   => 'A.',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['faq'];
		$this->assertSame( array( array( 'question' => 'Q?', 'answer' => 'A.' ) ), $rows );
	}

	/**
	 * Test: acf/pushs flat-keys → expand to array of rows.
	 */
	public function test_flat_repeater_pushs_expands(): void {
		$this->register_acf_block(
			'acf/pushs',
			array(
				array(
					'name'       => 'pushs',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'Pushs',
					'key'        => 'field_pushs',
					'sub_fields' => array(
						array( 'name' => 'title', 'type' => 'text', 'key' => 'field_pushs_title' ),
						array( 'name' => 'link',  'type' => 'url',  'key' => 'field_pushs_link' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/pushs',
				'properties' => array(
					'pushs'         => 3,
					'pushs_0_title' => 'A',
					'pushs_0_link'  => 'https://a',
					'pushs_1_title' => 'B',
					'pushs_1_link'  => 'https://b',
					'pushs_2_title' => 'C',
					'pushs_2_link'  => 'https://c',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['pushs'];
		$this->assertCount( 3, $rows );
		$this->assertSame( 'A', $rows[0]['title'] );
		$this->assertSame( 'https://b', $rows[1]['link'] );
		$this->assertSame( 'C', $rows[2]['title'] );
	}

	/**
	 * Test: acf/table flat-keys (nested repeater) → recursive expand.
	 *
	 * Outer repeater "table" has sub-field "cells" which is itself a repeater.
	 * Flat shape:  table = N, table_<i>_label = ..., table_<i>_cells = M,
	 *              table_<i>_cells_<j>_value = ...
	 */
	public function test_flat_repeater_table_nested_expands(): void {
		$this->register_acf_block(
			'acf/table',
			array(
				array(
					'name'       => 'table',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'Table',
					'key'        => 'field_table',
					'sub_fields' => array(
						array( 'name' => 'label', 'type' => 'text', 'key' => 'field_table_label' ),
						array(
							'name'       => 'cells',
							'type'       => 'repeater',
							'key'        => 'field_table_cells',
							'sub_fields' => array(
								array( 'name' => 'value', 'type' => 'text', 'key' => 'field_table_cells_value' ),
							),
						),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/table',
				'properties' => array(
					'table'                 => 2,
					'table_0_label'         => 'Row A',
					'table_0_cells'         => 2,
					'table_0_cells_0_value' => 'a1',
					'table_0_cells_1_value' => 'a2',
					'table_1_label'         => 'Row B',
					'table_1_cells'         => 1,
					'table_1_cells_0_value' => 'b1',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['table'];
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Row A', $rows[0]['label'] );
		$this->assertIsArray( $rows[0]['cells'] );
		$this->assertSame( 'a1', $rows[0]['cells'][0]['value'] );
		$this->assertSame( 'a2', $rows[0]['cells'][1]['value'] );
		$this->assertCount( 1, $rows[1]['cells'] );
		$this->assertSame( 'b1', $rows[1]['cells'][0]['value'] );
	}

	/**
	 * Test: synthetic repeater (arbitrary block name and sub-fields) — generic pattern.
	 */
	public function test_flat_repeater_synthetic_pattern(): void {
		$this->register_acf_block(
			'acf/synthetic',
			array(
				array(
					'name'       => 'items',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'Items',
					'key'        => 'field_items',
					'sub_fields' => array(
						array( 'name' => 'a', 'type' => 'text', 'key' => 'field_items_a' ),
						array( 'name' => 'b', 'type' => 'number', 'key' => 'field_items_b' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/synthetic',
				'properties' => array(
					'items'     => 2,
					'items_0_a' => 'x',
					'items_0_b' => 1,
					'items_1_a' => 'y',
					'items_1_b' => 2,
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$rows = $json['children'][0]['properties']['items'];
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'a' => 'x', 'b' => 1 ), $rows[0] );
		$this->assertSame( array( 'a' => 'y', 'b' => 2 ), $rows[1] );
	}

	/**
	 * Test: array-of-rows shape remains accepted (regression / backward compat).
	 */
	public function test_array_of_rows_remains_accepted(): void {
		$this->register_acf_block(
			'acf/numeric-list',
			array(
				array(
					'name'       => 'list',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'List',
					'key'        => 'field_list',
					'sub_fields' => array(
						array( 'name' => 'title', 'type' => 'text', 'key' => 'field_list_title' ),
					),
				),
			)
		);

		$rows = array(
			array( 'title' => 'One' ),
			array( 'title' => 'Two' ),
		);
		$json = $this->make_json( array(
			array(
				'type'       => 'acf/numeric-list',
				'properties' => array( 'list' => $rows ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		// Untouched.
		$this->assertSame( $rows, $json['children'][0]['properties']['list'] );
	}

	/**
	 * Test: flat-keys input strips ACF field-key references that the agent
	 * may have echoed from GET (`_<field>`, `_<field>_<n>_<sub>`).
	 */
	public function test_flat_repeater_strips_field_key_refs(): void {
		$this->register_acf_block(
			'acf/faq',
			array(
				array(
					'name'       => 'faq',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'FAQ',
					'key'        => 'field_faq',
					'sub_fields' => array(
						array( 'name' => 'question', 'type' => 'text', 'key' => 'field_faq_question' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/faq',
				'properties' => array(
					'faq'             => 1,
					'_faq'            => 'field_faq',
					'faq_0_question'  => 'Q?',
					'_faq_0_question' => 'field_faq_question',
				),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$props = $json['children'][0]['properties'];
		$this->assertArrayNotHasKey( '_faq', $props );
		$this->assertArrayNotHasKey( '_faq_0_question', $props );
		$this->assertSame( array( array( 'question' => 'Q?' ) ), $props['faq'] );
	}

	/**
	 * Test: empty repeater (count = 0) expands to empty array.
	 */
	public function test_flat_repeater_empty_count_expands_to_array(): void {
		$this->register_acf_block(
			'acf/faq',
			array(
				array(
					'name'       => 'faq',
					'type'       => 'repeater',
					'required'   => false,
					'label'      => 'FAQ',
					'key'        => 'field_faq',
					'sub_fields' => array(
						array( 'name' => 'question', 'type' => 'text', 'key' => 'field_faq_question' ),
					),
				),
			)
		);

		$json = $this->make_json( array(
			array(
				'type'       => 'acf/faq',
				'properties' => array( 'faq' => 0 ),
			),
		) );

		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'post' );

		$this->assertTrue( $result );
		$this->assertSame( array(), $json['children'][0]['properties']['faq'] );
	}
}
