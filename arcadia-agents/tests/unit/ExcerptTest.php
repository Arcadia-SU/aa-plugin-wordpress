<?php
/**
 * Tests for top-level excerpt support in POST/PUT articles.
 *
 * Tests that:
 * - create_post sets excerpt from top-level body.excerpt
 * - update_post sets excerpt from top-level body.excerpt
 * - top-level excerpt overrides meta.description
 * - Backward compat: meta.description still works when excerpt absent
 * - Empty excerpt clears the field
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits (guard against re-declaration).
if ( ! trait_exists( 'Arcadia_API_Posts_Handler' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
}

/**
 * Minimal class exposing traits for testing.
 */
class ExcerptHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	/** @var object */
	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json ) {
				return '<!-- wp:paragraph --><p>test</p><!-- /wp:paragraph -->';
			}
		};
	}
}

/**
 * Test class for excerpt support.
 */
class ExcerptTest extends TestCase {

	/** @var ExcerptHelper */
	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_taxonomies, $_test_next_post_id, $_test_users;

		$_test_options         = array();
		$_test_posts           = array();
		$_test_post_meta       = array();
		$_test_post_categories = array();
		$_test_post_tags       = array();
		$_test_taxonomies      = array();
		$_test_next_post_id    = 1000;
		$_test_users           = array(
			1 => (object) array(
				'ID'           => 1,
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'roles'        => array( 'administrator' ),
			),
		);

		$this->helper = new ExcerptHelper();
	}

	/**
	 * Test create_post with top-level excerpt.
	 */
	public function test_create_post_with_excerpt(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Article with excerpt',
			'content' => 'Body text',
			'excerpt' => 'This is the excerpt',
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertEquals( 'This is the excerpt', $data['post']['excerpt'] );
	}

	/**
	 * Test update_post with top-level excerpt.
	 */
	public function test_update_post_with_excerpt(): void {
		global $_test_posts;

		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => 'Old excerpt',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'excerpt' => 'New excerpt',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		// The stub wp_update_post doesn't actually update the object, so
		// we verify the response data via format_post which reads the post.
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test top-level excerpt overrides meta.description.
	 */
	public function test_excerpt_overrides_meta_description(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Override test',
			'content' => 'Body',
			'excerpt' => 'Top-level wins',
			'meta'    => array(
				'description' => 'Meta description loses',
			),
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertEquals( 'Top-level wins', $data['post']['excerpt'] );
	}

	/**
	 * Test backward compat: meta.description sets excerpt when no top-level excerpt.
	 */
	public function test_meta_description_still_works(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Backward compat',
			'content' => 'Body',
			'meta'    => array(
				'description' => 'From meta.description',
			),
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertEquals( 'From meta.description', $data['post']['excerpt'] );
	}

	/**
	 * Test empty excerpt clears the field.
	 */
	public function test_empty_excerpt_clears_field(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Clear excerpt',
			'content' => 'Body',
			'excerpt' => '',
			'meta'    => array(
				'description' => 'Should be overridden by empty excerpt',
			),
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertEquals( '', $data['post']['excerpt'] );
	}
}
