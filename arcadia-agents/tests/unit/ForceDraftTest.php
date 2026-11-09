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
	 * Force Draft keeps a non-published update as a draft (cannot be published).
	 * A published post would instead become a revision — see RevisionsTest.
	 */
	public function test_update_post_forces_draft_when_enabled(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;

		// Create a draft post to update.
		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'draft',
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
	 * Make an existing post in the given status.
	 */
	private function seed_post( $status ): void {
		global $_test_posts;
		$_test_posts[42] = (object) array(
			'ID'                => 42,
			'post_type'         => 'post',
			'post_title'        => 'Existing',
			'post_status'       => $status,
			'post_content'      => '',
			'post_excerpt'      => '',
			'post_date'         => '2026-01-01 00:00:00',
			'post_modified'     => '2026-01-01 00:00:00',
			'post_modified_gmt' => '2026-01-01 00:00:00',
			'post_author'       => 1,
			'post_name'         => 'existing',
			'post_mime_type'    => '',
		);
	}

	private function update_42( array $body ) {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( $body );
		return $this->helper->update_post( $request );
	}

	/**
	 * C61: Force Draft never lowers the post's own status when the body sends
	 * none. Before 0.13.0 every update forced 'draft' — a scheduled, pending or
	 * private post silently went back to draft.
	 *
	 * @dataProvider kept_statuses
	 */
	public function test_update_without_status_keeps_a_non_live_status( $status ): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;
		$this->seed_post( $status );

		$result = $this->update_42( array( 'title' => 'Updated Title' ) );

		$this->assertSame( 200, $result->get_status() );
		$data = $result->get_data();
		$this->assertArrayNotHasKey( 'force_draft_applied', $data );
		$this->assertSame( $status, $_test_posts[42]->post_status );
		$this->assertSame( 'Updated Title', $_test_posts[42]->post_title );
	}

	public static function kept_statuses() {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * C61: a scheduled post goes live by itself, so its edit is held as a
	 * revision like a published one — and the post stays scheduled.
	 */
	public function test_update_scheduled_post_is_held_and_stays_scheduled(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;
		$this->seed_post( 'future' );

		$result = $this->update_42( array( 'title' => 'Updated Title' ) );

		$this->assertSame( 201, $result->get_status() );
		$data = $result->get_data();
		$this->assertTrue( $data['held'] );
		$this->assertSame( 'content.update', $data['action'] );
		$this->assertSame( 'future', $_test_posts[42]->post_status );
		$this->assertSame( 'Existing', $_test_posts[42]->post_title );
	}

	/**
	 * C03: a status equal to the live one asks for nothing — held, not 422.
	 */
	public function test_same_status_on_live_post_is_held_not_refused(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;
		$this->seed_post( 'publish' );

		$result = $this->update_42( array( 'title' => 'Updated Title', 'status' => 'publish' ) );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertTrue( $result->get_data()['held'] );
		$this->assertSame( 'publish', $_test_posts[42]->post_status );
	}

	/**
	 * A real status change on a held article is still refused (an approval
	 * cannot change a status).
	 */
	public function test_status_change_on_live_post_is_refused(): void {
		global $_test_options;
		$_test_options['aa_force_draft'] = true;
		$this->seed_post( 'publish' );

		$result = $this->update_42( array( 'title' => 'X', 'status' => 'draft' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'status_not_supported_for_revision', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * A non-live post asked to go live is capped to draft, and says so.
	 */
	public function test_publish_request_on_draft_is_capped(): void {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = true;
		$this->seed_post( 'pending' );

		$result = $this->update_42( array( 'title' => 'X', 'status' => 'publish' ) );

		$this->assertTrue( $result->get_data()['force_draft_applied'] );
		$this->assertSame( 'draft', $_test_posts[42]->post_status );
	}

	/**
	 * A trashed post is never edited.
	 */
	public function test_update_on_trashed_post_is_refused(): void {
		$this->seed_post( 'trash' );

		$result = $this->update_42( array( 'title' => 'X' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_trashed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Create without a status under Force Draft: draft, nothing was capped.
	 */
	public function test_create_without_status_is_not_reported_as_capped(): void {
		global $_test_options;
		$_test_options['aa_force_draft'] = true;

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'title' => 'T', 'content' => 'Hello' ) );
		$data = $this->helper->create_post( $request )->get_data();

		$this->assertSame( 'draft', $data['post']['status'] );
		$this->assertArrayNotHasKey( 'force_draft_applied', $data );
	}
}
