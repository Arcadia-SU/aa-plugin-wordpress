<?php
/**
 * Tests for Pending Revisions feature (Phase 25).
 *
 * Tests the Arcadia_Revisions class (CPT CRUD), the update_post()
 * interception, force_draft coexistence, and the revision API endpoints.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits for the update_post helper.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-revisions.php';

/**
 * Minimal class exposing posts trait methods for testing.
 */
class RevisionsHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;
	use \Arcadia_API_Revisions_Handler;

	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json, $post_type = 'post' ) {
				return '<!-- wp:paragraph --><p>revision content</p><!-- /wp:paragraph -->';
			}
		};
	}
}

/**
 * Test class for Pending Revisions.
 */
class RevisionsTest extends TestCase {

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

		\WP_Query::reset();

		$this->helper = new RevisionsHelper();
	}

	/**
	 * Create a test post in the global store.
	 */
	private function create_test_post( $id, $status = 'publish' ) {
		global $_test_posts, $_test_post_meta;
		$_test_posts[ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => 'post',
			'post_parent'    => 0,
			'post_title'     => 'Original Title',
			'post_status'    => $status,
			'post_content'   => '<p>Original content</p>',
			'post_excerpt'   => 'Original excerpt',
			'post_date'      => '2026-04-01 10:00:00',
			'post_modified'  => '2026-04-01 10:00:00',
			'post_author'    => 1,
			'post_name'      => 'original-title',
			'post_mime_type' => '',
		);
		$_test_post_meta[ $id ] = array();
	}

	// =========================================================================
	// Arcadia_Revisions — create_revision()
	// =========================================================================

	public function test_create_revision_success(): void {
		$this->create_test_post( 42 );

		// set_next_result for get_pending_revision (returns empty — no existing pending).
		\WP_Query::set_next_result( array() );

		// set_next_result for get_next_version (returns empty — first version).
		\WP_Query::set_next_result( array() );

		$revisions = \Arcadia_Revisions::get_instance();
		$body = array(
			'title'          => 'New Title',
			'revision_notes' => 'SEO reoptimization v1',
			'children'       => array(),
		);
		$meta = array( 'title' => 'New SEO Title' );

		$result = $revisions->create_revision( 42, $body, $meta, '<p>New content</p>' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'revision_id', $result );
		$this->assertArrayHasKey( 'revision_version', $result );
		$this->assertArrayHasKey( 'preview_url', $result );
		$this->assertEquals( 1, $result['revision_version'] );

		// Verify the revision CPT was created.
		global $_test_posts, $_test_post_meta;
		$rev_id  = $result['revision_id'];
		$rev_post = $_test_posts[ $rev_id ];
		$this->assertEquals( 'aa_revision', $rev_post->post_type );
		$this->assertEquals( 42, $rev_post->post_parent );
		$this->assertEquals( 'pending', $rev_post->post_status );
		$this->assertEquals( 'New Title', $rev_post->post_title );
		$this->assertEquals( '<p>New content</p>', $rev_post->post_content );

		// Verify metadata.
		$this->assertEquals( 1, $_test_post_meta[ $rev_id ]['_aa_revision_version'] );
		$this->assertEquals( 'arcadia_agent', $_test_post_meta[ $rev_id ]['_aa_revision_created_by'] );
		$this->assertEquals( 'SEO reoptimization v1', $_test_post_meta[ $rev_id ]['_aa_revision_notes'] );

		// Verify stored payload.
		$stored = json_decode( $_test_post_meta[ $rev_id ]['_aa_revision_meta'], true );
		$this->assertEquals( 'New Title', $stored['body']['title'] );
		$this->assertEquals( 'New SEO Title', $stored['meta']['title'] );

		// Verify preview token.
		$this->assertNotEmpty( $_test_post_meta[ $rev_id ]['_aa_preview_token'] );
	}

	// =========================================================================
	// Arcadia_Revisions — approve_revision()
	// =========================================================================

	public function test_approve_revision_updates_live_post(): void {
		global $_test_posts, $_test_post_meta;

		$this->create_test_post( 42 );

		// Create a revision manually in the store.
		$rev_id = 1001;
		$_test_posts[ $rev_id ] = (object) array(
			'ID'             => $rev_id,
			'post_type'      => 'aa_revision',
			'post_parent'    => 42,
			'post_title'     => 'Updated Title',
			'post_status'    => 'pending',
			'post_content'   => '<p>Updated content</p>',
			'post_excerpt'   => '',
			'post_date'      => '2026-04-05 14:00:00',
			'post_modified'  => '2026-04-05 14:00:00',
			'post_author'    => 1,
			'post_name'      => '',
			'post_mime_type' => '',
		);

		$payload = array(
			'body' => array(
				'title'   => 'Updated Title',
				'excerpt' => 'Updated excerpt',
			),
			'meta' => array(
				'title'       => 'Updated SEO Title',
				'description' => 'Updated meta desc',
			),
		);
		$_test_post_meta[ $rev_id ] = array(
			'_aa_revision_version' => 1,
			'_aa_revision_meta'    => wp_json_encode( $payload ),
		);

		$revisions = \Arcadia_Revisions::get_instance();
		$result    = $revisions->approve_revision( $rev_id, 'admin' );

		$this->assertTrue( $result );

		// Live post should be updated.
		$this->assertEquals( 'Updated Title', $_test_posts[42]->post_title );
		$this->assertEquals( '<p>Updated content</p>', $_test_posts[42]->post_content );

		// SEO meta should be set.
		$this->assertEquals( 'Updated SEO Title', $_test_post_meta[42]['_yoast_wpseo_title'] );
		$this->assertEquals( 'Updated meta desc', $_test_post_meta[42]['_yoast_wpseo_metadesc'] );

		// Revision should be marked approved.
		$this->assertEquals( 'approved', $_test_posts[ $rev_id ]->post_status );
		$this->assertEquals( 'admin', $_test_post_meta[ $rev_id ]['_aa_revision_decided_by'] );
		$this->assertNotEmpty( $_test_post_meta[ $rev_id ]['_aa_revision_decided_at'] );
	}

	// =========================================================================
	// Arcadia_Revisions — reject_revision()
	// =========================================================================

	public function test_reject_revision_with_notes(): void {
		global $_test_posts, $_test_post_meta;

		$rev_id = 1001;
		$_test_posts[ $rev_id ] = (object) array(
			'ID'             => $rev_id,
			'post_type'      => 'aa_revision',
			'post_parent'    => 42,
			'post_title'     => 'Some Title',
			'post_status'    => 'pending',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-04-05 14:00:00',
			'post_modified'  => '2026-04-05 14:00:00',
			'post_author'    => 1,
			'post_name'      => '',
			'post_mime_type' => '',
		);
		$_test_post_meta[ $rev_id ] = array();

		$revisions = \Arcadia_Revisions::get_instance();
		$result    = $revisions->reject_revision( $rev_id, 'editor', 'Content quality insufficient' );

		$this->assertTrue( $result );
		$this->assertEquals( 'rejected', $_test_posts[ $rev_id ]->post_status );
		$this->assertEquals( 'editor', $_test_post_meta[ $rev_id ]['_aa_revision_decided_by'] );
		$this->assertEquals( 'Content quality insufficient', $_test_post_meta[ $rev_id ]['_aa_revision_decision_notes'] );
	}

	public function test_reject_non_pending_revision_fails(): void {
		global $_test_posts, $_test_post_meta;

		$rev_id = 1001;
		$_test_posts[ $rev_id ] = (object) array(
			'ID'             => $rev_id,
			'post_type'      => 'aa_revision',
			'post_parent'    => 42,
			'post_title'     => 'Some Title',
			'post_status'    => 'approved',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-04-05 14:00:00',
			'post_modified'  => '2026-04-05 14:00:00',
			'post_author'    => 1,
			'post_name'      => '',
			'post_mime_type' => '',
		);
		$_test_post_meta[ $rev_id ] = array();

		$revisions = \Arcadia_Revisions::get_instance();
		$result    = $revisions->reject_revision( $rev_id, 'editor' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'revision_not_pending', $result->get_error_code() );
	}

	// =========================================================================
	// update_post() — pending_revision interception
	// =========================================================================

	public function test_update_post_creates_revision_when_enabled(): void {
		global $_test_options;
		$_test_options['aa_pending_revisions'] = true;

		$this->create_test_post( 42, 'publish' );

		// WP_Query for get_pending_revision (no existing).
		\WP_Query::set_next_result( array() );
		// WP_Query for get_next_version (no existing).
		\WP_Query::set_next_result( array() );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'pending_revision' => true,
			'title'            => 'Proposed New Title',
			'children'         => array( array( 'type' => 'paragraph', 'content' => 'test' ) ),
			'revision_notes'   => 'SEO update v1',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 201, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['revision_created'] );
		$this->assertArrayHasKey( 'revision_id', $data );
		$this->assertEquals( 1, $data['revision_version'] );
		$this->assertEquals( 'publish', $data['original_post_status'] );
		$this->assertStringContainsString( 'aa_preview', $data['preview_url'] );

		// Live post should be UNCHANGED.
		global $_test_posts;
		$this->assertEquals( 'Original Title', $_test_posts[42]->post_title );
		$this->assertEquals( '<p>Original content</p>', $_test_posts[42]->post_content );
	}

	public function test_pending_revision_ignored_on_draft_post(): void {
		global $_test_options;
		$_test_options['aa_pending_revisions'] = true;

		$this->create_test_post( 42, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'pending_revision' => true,
			'title'            => 'Updated Title',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		// Should be a normal update, not a revision.
		$this->assertArrayNotHasKey( 'revision_created', $data );
		$this->assertTrue( $data['success'] );
	}

	public function test_pending_revision_ignored_when_setting_disabled(): void {
		// aa_pending_revisions NOT set (defaults to false).
		$this->create_test_post( 42, 'publish' );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'pending_revision' => true,
			'title'            => 'Updated Title',
		) );

		$result = $this->helper->update_post( $request );

		$data = $result->get_data();
		$this->assertArrayNotHasKey( 'revision_created', $data );
		$this->assertTrue( $data['success'] );
	}

	public function test_pending_revision_takes_priority_over_force_draft(): void {
		global $_test_options;
		$_test_options['aa_pending_revisions'] = true;
		$_test_options['aa_force_draft']       = true;

		$this->create_test_post( 42, 'publish' );

		// WP_Query mocks for revision creation.
		\WP_Query::set_next_result( array() ); // get_pending_revision
		\WP_Query::set_next_result( array() ); // get_next_version

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'pending_revision' => true,
			'title'            => 'Proposed Title',
			'children'         => array(),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertEquals( 201, $result->get_status() );
		$data = $result->get_data();
		$this->assertTrue( $data['revision_created'] );

		// Live post should still be publish, NOT draft.
		global $_test_posts;
		$this->assertEquals( 'publish', $_test_posts[42]->post_status );
	}

	// =========================================================================
	// Auto-supersede
	// =========================================================================

	public function test_auto_supersede_existing_pending_revision(): void {
		global $_test_posts, $_test_post_meta;

		$this->create_test_post( 42 );

		// Create an existing pending revision.
		$old_rev_id = 900;
		$_test_posts[ $old_rev_id ] = (object) array(
			'ID'             => $old_rev_id,
			'post_type'      => 'aa_revision',
			'post_parent'    => 42,
			'post_title'     => 'Old Proposal',
			'post_status'    => 'pending',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-04-03 10:00:00',
			'post_modified'  => '2026-04-03 10:00:00',
			'post_author'    => 1,
			'post_name'      => '',
			'post_mime_type' => '',
		);
		$_test_post_meta[ $old_rev_id ] = array( '_aa_revision_version' => 1 );

		// get_pending_revision returns the existing one.
		\WP_Query::set_next_result( array( $_test_posts[ $old_rev_id ] ) );
		// get_next_version returns the existing one (version 1).
		\WP_Query::set_next_result( array( $_test_posts[ $old_rev_id ] ) );

		$revisions = \Arcadia_Revisions::get_instance();
		$result = $revisions->create_revision( 42, array( 'title' => 'New Proposal' ), array(), '<p>New</p>' );

		$this->assertIsArray( $result );
		$this->assertEquals( 2, $result['revision_version'] );

		// Old revision should be superseded.
		$this->assertEquals( 'superseded', $_test_posts[ $old_rev_id ]->post_status );
		$this->assertStringContainsString( 'Superseded', $_test_post_meta[ $old_rev_id ]['_aa_revision_decision_notes'] );
	}

	// =========================================================================
	// API endpoints
	// =========================================================================

	public function test_get_article_revisions_list(): void {
		global $_test_posts, $_test_post_meta;

		$this->create_test_post( 42 );

		$rev1 = (object) array(
			'ID'          => 1001,
			'post_type'   => 'aa_revision',
			'post_parent' => 42,
			'post_title'  => 'Rev 1',
			'post_status' => 'approved',
			'post_date'   => '2026-04-01 10:00:00',
		);
		$rev2 = (object) array(
			'ID'          => 1002,
			'post_type'   => 'aa_revision',
			'post_parent' => 42,
			'post_title'  => 'Rev 2',
			'post_status' => 'pending',
			'post_date'   => '2026-04-05 14:00:00',
		);
		$_test_posts[1001] = $rev1;
		$_test_posts[1002] = $rev2;
		$_test_post_meta[1001] = array( '_aa_revision_version' => 1 );
		$_test_post_meta[1002] = array( '_aa_revision_version' => 2, '_aa_preview_token' => 'abc123', '_aa_preview_expires' => time() + 86400 );

		// Mock get_revisions WP_Query.
		\WP_Query::set_next_result( array( $rev2, $rev1 ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );

		$result = $this->helper->get_article_revisions( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 200, $result->get_status() );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'revisions', $data );
		$this->assertCount( 2, $data['revisions'] );
		$this->assertEquals( 2, $data['total'] );
	}

	public function test_get_article_revision_detail(): void {
		global $_test_posts, $_test_post_meta;

		$this->create_test_post( 42 );

		$_test_posts[1001] = (object) array(
			'ID'          => 1001,
			'post_type'   => 'aa_revision',
			'post_parent' => 42,
			'post_title'  => 'Rev 1',
			'post_status' => 'pending',
			'post_date'   => '2026-04-05 14:00:00',
		);
		$_test_post_meta[1001] = array(
			'_aa_revision_version'    => 1,
			'_aa_revision_created_by' => 'arcadia_agent',
			'_aa_revision_notes'      => 'Test notes',
			'_aa_preview_token'       => 'tok123',
			'_aa_preview_expires'     => time() + 86400,
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_param( 'revision_id', 1001 );

		$result = $this->helper->get_article_revision( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertEquals( 1001, $data['revision_id'] );
		$this->assertEquals( 1, $data['revision_version'] );
		$this->assertEquals( 'pending', $data['status'] );
		$this->assertEquals( 'arcadia_agent', $data['created_by'] );
		$this->assertEquals( 'Test notes', $data['revision_notes'] );
		$this->assertStringContainsString( 'tok123', $data['preview_url'] );
	}

	public function test_get_article_revision_wrong_parent_returns_404(): void {
		global $_test_posts, $_test_post_meta;

		$this->create_test_post( 42 );
		$this->create_test_post( 99 );

		// Revision belongs to post 99, not 42.
		$_test_posts[1001] = (object) array(
			'ID'          => 1001,
			'post_type'   => 'aa_revision',
			'post_parent' => 99,
			'post_title'  => 'Rev 1',
			'post_status' => 'pending',
			'post_date'   => '2026-04-05 14:00:00',
		);
		$_test_post_meta[1001] = array();

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_param( 'revision_id', 1001 );

		$result = $this->helper->get_article_revision( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'revision_not_found', $result->get_error_code() );
	}

	// =========================================================================
	// site-info setting
	// =========================================================================

	public function test_site_info_includes_pending_revisions_setting(): void {
		global $_test_options;
		$_test_options['aa_pending_revisions'] = true;

		$this->assertTrue( (bool) get_option( 'aa_pending_revisions', false ) );

		$_test_options['aa_pending_revisions'] = false;
		$this->assertFalse( (bool) get_option( 'aa_pending_revisions', false ) );
	}
}
