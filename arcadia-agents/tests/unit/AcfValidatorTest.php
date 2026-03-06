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
	 */
	private function register_acf_block( $block_name, $fields ) {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$short_name = preg_replace( '/^acf\//', '', $block_name );

		$_test_acf_block_types[ $block_name ] = array(
			'title' => ucfirst( $short_name ) . ' Block',
		);

		$group_key = 'group_' . $short_name;

		$_test_acf_field_groups[] = array(
			'key'   => $group_key,
			'title' => ucfirst( $short_name ) . ' Fields',
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
}
