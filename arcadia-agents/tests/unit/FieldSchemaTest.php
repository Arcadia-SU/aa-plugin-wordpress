<?php
/**
 * Tests for Field Schema features (FS-1 through FS-4).
 *
 * Tests:
 * - FS-1: field_values in format_post() (ACF active vs inactive)
 * - FS-2: GET /field-schema returns schema with semantic mappings
 * - FS-3: PUT /field-schema validates and merges mappings
 * - FS-4: Auto-apply mappings at write time via update_field()
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';

/**
 * Helper class exposing trait methods for testing.
 */
class FieldSchemaHelper {
	use \Arcadia_API_Field_Schema_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;

	/**
	 * Blocks instance mock.
	 *
	 * @var object
	 */
	public $blocks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json, $post_type = 'post' ) {
				return '<!-- wp:paragraph --><p>test</p><!-- /wp:paragraph -->';
			}
		};
	}
}

/**
 * Test class for Field Schema features.
 */
class FieldSchemaTest extends TestCase {

	/**
	 * Helper instance.
	 *
	 * @var FieldSchemaHelper
	 */
	private $helper;

	protected function setUp(): void {
		$this->helper = new FieldSchemaHelper();

		// Reset global state.
		global $_test_options, $_test_acf_field_groups, $_test_acf_fields_by_group,
			   $_test_acf_update_field_calls, $_test_posts, $_test_post_meta,
			   $_test_post_categories, $_test_post_tags, $_test_get_fields_results;

		$_test_options                = array();
		$_test_acf_field_groups       = array();
		$_test_acf_fields_by_group    = array();
		$_test_acf_update_field_calls = array();
		$_test_posts                  = array();
		$_test_post_meta              = array();
		$_test_post_categories        = array();
		$_test_post_tags              = array();
		$_test_get_fields_results     = array();
	}

	// =========================================================================
	// FS-1: field_values in format_post()
	// =========================================================================

	/**
	 * Test format_post includes field_values when ACF has fields.
	 */
	public function test_format_post_includes_field_values(): void {
		global $_test_posts, $_test_post_meta, $_test_users, $_test_get_fields_results;

		$post_id = 42;
		$_test_posts[ $post_id ] = (object) array(
			'ID'            => $post_id,
			'post_title'    => 'Test Article',
			'post_name'     => 'test-article',
			'post_type'     => 'article',
			'post_status'   => 'publish',
			'post_content'  => '<p>Content</p>',
			'post_excerpt'  => 'Excerpt',
			'post_date'     => '2026-03-20 10:00:00',
			'post_modified' => '2026-03-20 10:00:00',
			'post_author'   => 1,
		);
		$_test_users[1] = (object) array( 'display_name' => 'Admin' );

		$_test_get_fields_results[ $post_id ] = array(
			'chapo_1'      => 'Intro text here',
			'image_hero'   => 12345,
		);

		$result = $this->callFormatPost( $post_id );

		$this->assertArrayHasKey( 'field_values', $result );
		$fv = (array) $result['field_values'];
		$this->assertEquals( 'Intro text here', $fv['chapo_1'] );
		$this->assertEquals( 12345, $fv['image_hero'] );
	}

	/**
	 * Test format_post returns empty object when no ACF fields.
	 */
	public function test_format_post_field_values_empty_when_no_fields(): void {
		global $_test_posts, $_test_users, $_test_get_fields_results;

		$post_id = 43;
		$_test_posts[ $post_id ] = (object) array(
			'ID'            => $post_id,
			'post_title'    => 'No Fields',
			'post_name'     => 'no-fields',
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_date'     => '2026-03-20 10:00:00',
			'post_modified' => '2026-03-20 10:00:00',
			'post_author'   => 1,
		);
		$_test_users[1] = (object) array( 'display_name' => 'Admin' );

		// get_fields returns false (no ACF fields).
		$_test_get_fields_results[ $post_id ] = false;

		$result = $this->callFormatPost( $post_id );

		$this->assertArrayHasKey( 'field_values', $result );
		$fv = (array) $result['field_values'];
		$this->assertEmpty( $fv );
	}

	// =========================================================================
	// FS-2: GET /field-schema
	// =========================================================================

	/**
	 * Test GET field-schema returns fields with semantic mappings.
	 */
	public function test_get_field_schema_returns_fields(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group, $_test_options;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_article',
				'title'    => 'Article Fields',
				'location' => array(
					array(
						array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
					),
				),
			),
		);

		$_test_acf_fields_by_group['group_article'] = array(
			array( 'name' => 'chapo_1', 'type' => 'textarea', 'label' => 'Chapô' ),
			array( 'name' => 'image_hero', 'type' => 'image', 'label' => 'Hero Image' ),
		);

		// Stored semantic mapping.
		$_test_options['aa_field_schema'] = array(
			'post' => array(
				'chapo_1' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
			),
		);

		$request  = new \WP_REST_Request();
		$response = $this->helper->get_field_schema( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'post', $data );
		$fields = $data['post']['fields'];
		$this->assertCount( 2, $fields );

		// chapo_1 has a stored mapping.
		$this->assertEquals( 'chapo_1', $fields[0]['name'] );
		$this->assertEquals( 'textarea', $fields[0]['type'] );
		$this->assertEquals( array( 'type' => 'mapping', 'source' => 'excerpt' ), $fields[0]['semantic'] );

		// image_hero has no mapping yet.
		$this->assertEquals( 'image_hero', $fields[1]['name'] );
		$this->assertNull( $fields[1]['semantic'] );
	}

	/**
	 * Test GET field-schema filters by post_type query param (aa-xp8).
	 */
	public function test_get_field_schema_filters_by_post_type(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group, $_test_options;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_article',
				'title'    => 'Article Fields',
				'location' => array(
					array(
						array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
					),
				),
			),
		);

		$_test_acf_fields_by_group['group_article'] = array(
			array( 'name' => 'chapo_1', 'type' => 'textarea', 'label' => 'Chapô' ),
		);

		// Without filter: returns both post and page (page gets the group too via stub).
		$request  = new \WP_REST_Request();
		$response = $this->helper->get_field_schema( $request );
		$data     = $response->get_data();

		// Should have multiple post types.
		$this->assertGreaterThan( 1, count( $data ), 'Without filter, should return multiple post types' );

		// With filter: only the requested post type.
		$request_filtered = new \WP_REST_Request();
		$request_filtered->set_param( 'post_type', 'post' );
		$response_filtered = $this->helper->get_field_schema( $request_filtered );
		$data_filtered     = $response_filtered->get_data();

		$this->assertCount( 1, $data_filtered, 'With post_type filter, should return only one post type' );
		$this->assertArrayHasKey( 'post', $data_filtered );
		$this->assertArrayNotHasKey( 'page', $data_filtered );
	}

	/**
	 * Test GET field-schema returns empty when no ACF.
	 */
	public function test_get_field_schema_empty_without_acf(): void {
		global $_test_acf_field_groups;
		$_test_acf_field_groups = array();

		$request  = new \WP_REST_Request();
		$response = $this->helper->get_field_schema( $request );
		$data     = $response->get_data();

		$this->assertEmpty( $data );
	}

	// =========================================================================
	// FS-3: PUT /field-schema
	// =========================================================================

	/**
	 * Test PUT field-schema stores mappings.
	 */
	public function test_update_field_schema_stores_mappings(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'article' => array(
				'chapo_1' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
				'points'  => array( 'type' => 'generation', 'instruction' => '3 key points' ),
			),
		) );

		$response = $this->helper->update_field_schema( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$stored = $data['schema'];
		$this->assertEquals( 'mapping', $stored['article']['chapo_1']['type'] );
		$this->assertEquals( 'excerpt', $stored['article']['chapo_1']['source'] );
		$this->assertEquals( 'generation', $stored['article']['points']['type'] );
	}

	/**
	 * Test PUT field-schema merges with existing data.
	 */
	public function test_update_field_schema_partial_merge(): void {
		global $_test_options;

		// Pre-existing mapping.
		$_test_options['aa_field_schema'] = array(
			'article' => array(
				'chapo_1' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
			),
		);

		// Add a new field, keep existing.
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'article' => array(
				'image_hero' => array( 'type' => 'mapping', 'source' => 'featured_image_url' ),
			),
		) );

		$response = $this->helper->update_field_schema( $request );
		$data     = $response->get_data();

		// Both fields should exist.
		$this->assertArrayHasKey( 'chapo_1', $data['schema']['article'] );
		$this->assertArrayHasKey( 'image_hero', $data['schema']['article'] );
		$this->assertEquals( 'excerpt', $data['schema']['article']['chapo_1']['source'] );
	}

	/**
	 * Test PUT field-schema rejects invalid mapping type.
	 */
	public function test_update_field_schema_rejects_invalid_type(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'article' => array(
				'chapo_1' => array( 'type' => 'invalid_type' ),
			),
		) );

		$response = $this->helper->update_field_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertEquals( 'invalid_mapping_type', $response->get_error_code() );
	}

	/**
	 * Test PUT field-schema rejects missing type key.
	 */
	public function test_update_field_schema_rejects_missing_type(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'article' => array(
				'chapo_1' => array( 'source' => 'excerpt' ),
			),
		) );

		$response = $this->helper->update_field_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertEquals( 'invalid_mapping', $response->get_error_code() );
	}

	/**
	 * Test PUT field-schema rejects empty body.
	 */
	public function test_update_field_schema_rejects_empty_body(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array() );

		$response = $this->helper->update_field_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertEquals( 'invalid_payload', $response->get_error_code() );
	}

	// =========================================================================
	// FS-4: Auto-apply mappings at write time
	// =========================================================================

	/**
	 * Test create_post auto-applies mapping type fields.
	 */
	public function test_create_post_applies_field_schema_mappings(): void {
		global $_test_options, $_test_acf_update_field_calls;

		$_test_options['aa_field_schema'] = array(
			'post' => array(
				'chapo_1' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
			),
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test Article',
			'excerpt' => 'This is the excerpt',
			'content' => 'Some content',
		) );

		$this->helper->create_post( $request );

		// Check that update_field was called with the mapped value.
		$mapped_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'chapo_1' === $call['field_name'];
		} );

		$this->assertNotEmpty( $mapped_calls, 'update_field should be called for mapped field chapo_1' );
		$call = array_values( $mapped_calls )[0];
		$this->assertEquals( 'This is the excerpt', $call['value'] );
	}

	/**
	 * Test auto-apply skips null semantic (not calibrated).
	 */
	public function test_auto_apply_skips_null_semantic(): void {
		global $_test_options, $_test_acf_update_field_calls;

		$_test_options['aa_field_schema'] = array(
			'post' => array(
				'unknown_field' => null,
			),
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test',
			'content' => 'Content',
		) );

		$_test_acf_update_field_calls = array();
		$this->helper->create_post( $request );

		// No calls for the null-semantic field.
		$mapped_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'unknown_field' === $call['field_name'];
		} );

		$this->assertEmpty( $mapped_calls, 'update_field should NOT be called for null semantic' );
	}

	/**
	 * Test auto-apply with no stored schema is a no-op.
	 */
	public function test_auto_apply_noop_without_schema(): void {
		global $_test_options, $_test_acf_update_field_calls;

		// No schema stored at all.
		unset( $_test_options['aa_field_schema'] );

		$_test_acf_update_field_calls = array();

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test',
			'excerpt' => 'Excerpt',
			'content' => 'Content',
		) );

		$this->helper->create_post( $request );

		// Only the standard ACF calls (from auto_populate / _acf_changed).
		$mapped_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'chapo_1' === $call['field_name'];
		} );

		$this->assertEmpty( $mapped_calls );
	}

	/**
	 * Test auto-apply maps h1 source from body.title.
	 */
	public function test_auto_apply_maps_h1_source(): void {
		global $_test_options, $_test_acf_update_field_calls;

		$_test_options['aa_field_schema'] = array(
			'post' => array(
				'subtitle' => array( 'type' => 'mapping', 'source' => 'h1' ),
			),
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'My H1 Title',
			'content' => 'Content',
		) );

		$_test_acf_update_field_calls = array();
		$this->helper->create_post( $request );

		$mapped_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'subtitle' === $call['field_name'];
		} );

		$this->assertNotEmpty( $mapped_calls );
		$call = array_values( $mapped_calls )[0];
		$this->assertEquals( 'My H1 Title', $call['value'] );
	}

	/**
	 * Test auto-apply skips fields already handled by acf_fields.
	 *
	 * When acf_fields includes a field (e.g. "image"), process_acf_fields()
	 * handles type-specific logic (sideload). apply_field_schema_mappings()
	 * must not overwrite it with the raw source value (URL string).
	 */
	public function test_auto_apply_skips_fields_in_acf_fields(): void {
		global $_test_options, $_test_acf_update_field_calls,
			$_test_acf_field_groups, $_test_acf_fields_by_group;

		// Register ACF field group so process_acf_fields() knows 'image' is an image field.
		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_hero',
				'title'    => 'Hero',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			),
		);
		$_test_acf_fields_by_group = array(
			'group_hero' => array(
				array( 'name' => 'image', 'type' => 'image', 'key' => 'field_image', 'required' => 0, 'label' => 'Image' ),
				array( 'name' => 'chapo_1', 'type' => 'wysiwyg', 'key' => 'field_chapo', 'required' => 0, 'label' => 'Chapo' ),
			),
		);

		$_test_options['aa_field_schema'] = array(
			'post' => array(
				'image'   => array( 'type' => 'mapping', 'source' => 'featured_image_url' ),
				'chapo_1' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
			),
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'      => 'Test Article',
			'excerpt'    => 'The excerpt',
			'content'    => 'Content',
			'meta'       => array(
				'featured_image_url' => 'https://example.com/photo.jpg',
			),
			'acf_fields' => array(
				'image' => 'https://example.com/photo.jpg',
			),
		) );

		$_test_acf_update_field_calls = array();
		$this->helper->create_post( $request );

		// process_acf_fields() sideloads the URL → calls update_field('image', 999).
		// apply_field_schema_mappings() must NOT overwrite with the raw URL string.
		// Count how many times 'image' was written — should be exactly once (from process_acf_fields).
		$image_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'image' === $call['field_name'];
		} );
		// All image calls should have the sideloaded int (999), not the URL string.
		foreach ( $image_calls as $call ) {
			$this->assertIsInt( $call['value'], 'image field should have sideloaded attachment ID, not raw URL' );
		}

		// 'chapo_1' should still be mapped (not in acf_fields).
		$chapo_calls = array_filter( $_test_acf_update_field_calls, function( $call ) {
			return 'chapo_1' === $call['field_name'];
		} );
		$this->assertNotEmpty( $chapo_calls, 'chapo_1 should still be mapped by apply_field_schema_mappings' );
		$this->assertEquals( 'The excerpt', array_values( $chapo_calls )[0]['value'] );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Call format_post via reflection (private method).
	 *
	 * @param int $post_id Post ID.
	 * @return array Formatted post.
	 */
	private function callFormatPost( $post_id ) {
		global $_test_posts;
		$post = $_test_posts[ $post_id ];

		$ref = new \ReflectionMethod( $this->helper, 'format_post' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->helper, $post );
	}
}
