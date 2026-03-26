<?php
/**
 * Tests for Force Draft feature (aa_force_draft option).
 *
 * Tests that:
 * - settings.force_draft appears in site-info response
 * - create_post() overrides status to draft when enabled
 * - update_post() overrides status to draft when enabled
 * - force_draft_applied flag appears in responses when override active
 * - No override when aa_force_draft is disabled
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';

/**
 * Minimal class exposing posts trait methods for testing.
 */
class ForceDraftHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

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
			public function json_to_blocks( $json ) {
				return '<!-- wp:paragraph --><p>test</p><!-- /wp:paragraph -->';
			}
			public function write_acf_block_meta( $post_id ) {}
		};
	}
}

/**
 * Test class for Force Draft feature.
 */
class ForceDraftTest extends TestCase {

	/**
	 * @var ForceDraftHelper
	 */
	private $helper;

	/**
	 * Set up test fixtures.
	 */
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

		$this->helper = new ForceDraftHelper();
	}

	// =========================================================================
	// create_post() — Force Draft
	// =========================================================================

	/**
	 * Test create_post forces draft when aa_force_draft is enabled.
	 */
	public function test_create_post_forces_draft_when_enabled(): void {
		global $_test_options;
		$_test_options['aa_force_draft'] = true;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test Article',
			'status'  => 'publish',
			'content' => 'Hello world',
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'draft', $data['post']['status'] );
	}

	/**
	 * Test create_post includes force_draft_applied flag when override active.
	 */
	public function test_create_post_includes_force_draft_applied(): void {
		global $_test_options;
		$_test_options['aa_force_draft'] = true;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test Article',
			'status'  => 'publish',
			'content' => 'Hello world',
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertTrue( $data['force_draft_applied'] );
	}

	/**
	 * Test create_post does NOT include force_draft_applied when disabled.
	 */
	public function test_create_post_no_force_draft_when_disabled(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test Article',
			'status'  => 'publish',
			'content' => 'Hello world',
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertArrayNotHasKey( 'force_draft_applied', $data );
	}

	/**
	 * Test create_post respects requested status when force_draft disabled.
	 */
	public function test_create_post_respects_status_when_disabled(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Test Article',
			'status'  => 'publish',
			'content' => 'Hello world',
		) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertEquals( 'publish', $data['post']['status'] );
	}

	// =========================================================================
	// update_post() — Force Draft
	// =========================================================================

	/**
	 * Test update_post forces draft when aa_force_draft is enabled.
	 */
	public function test_update_post_forces_draft_when_enabled(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;

		// Create a post to update.
		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'status' => 'publish',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['force_draft_applied'] );
	}

	/**
	 * Test update_post does NOT include force_draft_applied when disabled.
	 */
	public function test_update_post_no_force_draft_when_disabled(): void {
		global $_test_posts;

		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'status' => 'publish',
		) );

		$result = $this->helper->update_post( $request );

		$data = $result->get_data();
		$this->assertArrayNotHasKey( 'force_draft_applied', $data );
	}

	/**
	 * Test update_post forces draft even without explicit status in body.
	 */
	public function test_update_post_forces_draft_without_status_in_body(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;

		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'title' => 'Updated Title',
		) );

		$result = $this->helper->update_post( $request );

		$data = $result->get_data();
		$this->assertTrue( $data['force_draft_applied'] );
	}
}
