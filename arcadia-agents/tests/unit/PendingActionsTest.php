<?php
/**
 * Held writes (Phase 55 / CH03): every path the write table holds, the 202 of
 * contract §3, approval through the same code as a direct write, staleness
 * (C04), supersede, withdraw, listing, and the trash rule of C63.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-redirects.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-redirects.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-site.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-pending-actions.php';

/**
 * Helper exposing every write trait plus the pending-actions trait.
 */
class PendingActionsHelper {
	use \Arcadia_API_Formatters;
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;
	use \Arcadia_API_Redirects_Handler;
	use \Arcadia_API_Pending_Actions_Handler;

	public $blocks;
	public $auth;

	/** @var string[] Scopes the fake auth refuses. */
	public $denied = array();

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json ) {
				return '<!-- wp:paragraph --><p>new</p><!-- /wp:paragraph -->';
			}
		};
		$helper     = $this;
		$this->auth = new class( $helper ) {
			private $helper;
			public function __construct( $helper ) {
				$this->helper = $helper;
			}
			public function check_scope( $scope ) {
				return in_array( $scope, $this->helper->denied, true )
					? new \WP_Error( 'scope_disabled', 'no', array( 'status' => 403 ) )
					: true;
			}
		};
	}
}

/**
 * Class PendingActionsTest
 */
class PendingActionsTest extends TestCase {

	/** @var PendingActionsHelper */
	private $helper;

	/** @var \Arcadia_Pending_Actions */
	private $queue;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_terms, $_test_next_post_id;
		$_test_options      = array( 'aa_force_draft' => true );
		$_test_posts        = array();
		$_test_post_meta    = array();
		$_test_terms        = array();
		$_test_next_post_id = 5000;

		$this->helper = new PendingActionsHelper();
		$this->queue  = \Arcadia_Pending_Actions::get_instance();
		$this->queue->set_executor( array( $this->helper, 'apply_held_action' ) );
	}

	protected function tearDown(): void {
		$this->queue->set_executor( null );
	}

	private function seed_post( $id, $status = 'publish', $type = 'post' ) {
		global $_test_posts, $_test_post_meta;
		$_test_posts[ $id ]     = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => 'Article ' . $id,
			'post_status'       => $status,
			'post_content'      => '<p>old</p>',
			'post_excerpt'      => '',
			'post_parent'       => 0,
			'post_date'         => '2026-01-01 00:00:00',
			'post_date_gmt'     => '2026-01-01 00:00:00',
			'post_modified'     => '2026-01-01 00:00:00',
			'post_modified_gmt' => '2026-01-01 00:00:00',
			'post_author'       => 1,
			'post_name'         => 'a' . $id,
			'post_mime_type'    => '',
		);
		$_test_post_meta[ $id ] = array();
	}

	private function request( $id, array $body = array(), array $params = array() ) {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', $id );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$request->set_json_params( $body );
		return $request;
	}

	private function assert_held( $response, $action ) {
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['held'] );
		$this->assertSame( $action, $data['action'] );
		$this->assertIsInt( $data['pending_action_id'] );
		$this->assertStringContainsString( 'page=arcadia-pending&action_id=' . $data['pending_action_id'], $data['review_url'] );
		$this->assertArrayHasKey( 'base_version', $data );
		$this->assertSame( array( 'kind', 'type', 'id' ), array_keys( $data['object'] ) );
		return $data;
	}

	// ─── C63: delete always trashes ────────────────────────────────────

	/**
	 * @dataProvider post_types
	 */
	public function test_delete_without_force_draft_trashes_any_type( $type ) {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = false;
		$this->seed_post( 42, 'publish', $type );

		$data = $this->helper->delete_post( $this->request( 42 ) )->get_data();

		$this->assertTrue( $data['trashed'] );
		$this->assertSame( 'trash', $_test_posts[42]->post_status, 'the post is in the trash, not gone' );
	}

	public static function post_types() {
		return array(
			'post' => array( 'post' ),
			'page' => array( 'page' ),
			'CPT'  => array( 'landing' ),
		);
	}

	public function test_force_on_a_live_post_still_trashes_first() {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = false;
		$this->seed_post( 42 );

		$this->helper->delete_post( $this->request( 42, array(), array( 'force' => true ) ) );

		$this->assertSame( 'trash', $_test_posts[42]->post_status );
	}

	public function test_second_delete_of_trashed_post_is_a_noop() {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = false;
		$this->seed_post( 42, 'trash' );

		$data = $this->helper->delete_post( $this->request( 42 ) )->get_data();

		$this->assertTrue( $data['already_trashed'] );
		$this->assertTrue( $data['trashed'] );
		$this->assertArrayHasKey( 42, $_test_posts );
	}

	public function test_force_on_trashed_post_deletes_for_good() {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = false;
		$this->seed_post( 42, 'trash' );

		$data = $this->helper->delete_post( $this->request( 42, array(), array( 'force' => true ) ) )->get_data();

		$this->assertFalse( $data['trashed'], 'read from the site: the post is gone, not trashed' );
		$this->assertArrayNotHasKey( 42, $_test_posts );
	}

	// ─── Held delete → approve / stale / block other writes ────────────

	public function test_delete_under_force_draft_is_held_then_applied_on_approval() {
		global $_test_posts;
		$this->seed_post( 42 );

		$data = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );
		$this->assertSame( 'publish', $_test_posts[42]->post_status, 'nothing changed on the site' );
		$this->assertSame( array( 'kind' => 'content', 'type' => 'post', 'id' => 42 ), $data['object'] );

		$result = $this->queue->approve( $data['pending_action_id'], 'editor' );

		$this->assertTrue( $result['approved'] );
		$this->assertSame( 'trash', $_test_posts[42]->post_status );
		$this->assertSame( 'approved', $_test_posts[ $data['pending_action_id'] ]->post_status );
	}

	public function test_delete_of_a_draft_is_held_too() {
		$this->seed_post( 42, 'draft' );
		$this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );
	}

	public function test_approval_refused_when_article_changed_since() {
		global $_test_posts;
		$this->seed_post( 42 );
		$data = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );

		// The client edits the article in WordPress meanwhile.
		$_test_posts[42]->post_modified_gmt = '2026-02-02 10:00:00';

		$result = $this->queue->approve( $data['pending_action_id'], 'editor' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pending_action_stale', $result->get_error_code() );
		$this->assertSame( 'publish', $_test_posts[42]->post_status, 'nothing applied' );
		$this->assertSame( 'stale', $_test_posts[ $data['pending_action_id'] ]->post_status );
	}

	public function test_a_pending_deletion_blocks_other_writes_on_the_article() {
		$this->seed_post( 42 );
		$held = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );

		$update = $this->helper->update_post( $this->request( 42, array( 'title' => 'New' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $update );
		$this->assertSame( 'pending_action_exists', $update->get_error_code() );
		$this->assertSame( 409, $update->get_error_data()['status'] );
		$this->assertSame( $held['pending_action_id'], $update->get_error_data()['pending_action_id'] );

		$image = $this->helper->set_featured_image( $this->request( 42, array( 'attachment_id' => 7 ) ) );
		$this->assertSame( 'pending_action_exists', $image->get_error_code() );
	}

	public function test_same_action_on_same_object_supersedes() {
		global $_test_posts;
		$this->seed_post( 42 );
		$first  = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );
		$second = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );

		$this->assertNotSame( $first['pending_action_id'], $second['pending_action_id'] );
		$this->assertSame( 'superseded', $_test_posts[ $first['pending_action_id'] ]->post_status );
		$this->assertSame( 'pending', $_test_posts[ $second['pending_action_id'] ]->post_status );
	}

	public function test_approved_deletion_marks_the_articles_other_proposals_stale() {
		global $_test_posts;
		$this->seed_post( 42 );
		$_test_posts[42]->post_status = 'publish';
		$revision = \Arcadia_Revisions::get_instance()->create_revision( 42, array( 'title' => 'X' ), array(), '<p>x</p>' );
		$delete   = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );

		$this->queue->approve( $delete['pending_action_id'], 'editor' );

		$this->assertSame( 'approved', $_test_posts[ $delete['pending_action_id'] ]->post_status, 'the deletion itself is not marked stale' );
		$this->assertSame( 'stale', $_test_posts[ $revision['revision_id'] ]->post_status );
	}

	// ─── Featured image ────────────────────────────────────────────────

	public function test_featured_image_on_live_post_is_held_then_applied() {
		global $_test_post_meta, $_test_attachment_is_image;
		$_test_attachment_is_image = true;
		$this->seed_post( 42 );

		$data = $this->assert_held( $this->helper->set_featured_image( $this->request( 42, array( 'attachment_id' => 7 ) ) ), 'content.featured_image' );
		$this->assertArrayNotHasKey( '_thumbnail_id', $_test_post_meta[42] );

		$this->queue->approve( $data['pending_action_id'], 'editor' );
		$this->assertSame( 7, $_test_post_meta[42]['_thumbnail_id'] );
	}

	public function test_featured_image_on_draft_applies_directly() {
		global $_test_post_meta, $_test_attachment_is_image;
		$_test_attachment_is_image = true;
		$this->seed_post( 42, 'draft' );

		$response = $this->helper->set_featured_image( $this->request( 42, array( 'attachment_id' => 7 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, $_test_post_meta[42]['_thumbnail_id'] );
	}

	// ─── Terms ─────────────────────────────────────────────────────────

	private function seed_term( $id, $taxonomy ) {
		global $_test_terms;
		$_test_terms[ $taxonomy . ':' . $id ] = (object) array(
			'term_id'     => $id,
			'name'        => 'Old',
			'slug'        => 'old',
			'description' => '',
			'parent'      => 0,
			'count'       => 0,
			'taxonomy'    => $taxonomy,
		);
	}

	public function test_category_rename_held_then_applied() {
		$this->seed_term( 3, 'category' );
		$data = $this->assert_held( $this->helper->update_category( $this->request( 3, array( 'name' => 'New' ) ) ), 'term.update' );
		$this->assertSame( array( 'kind' => 'term', 'type' => 'category', 'id' => 3 ), $data['object'] );

		$result = $this->queue->approve( $data['pending_action_id'], 'editor' );
		$this->assertTrue( $result['approved'] );
		$this->assertArrayHasKey( 'category', $result['response'] );
	}

	public function test_tag_delete_held_and_stale_if_renamed_meanwhile() {
		global $_test_terms;
		$this->seed_term( 4, 'post_tag' );
		$data = $this->assert_held( $this->helper->delete_tag( $this->request( 4 ) ), 'term.delete' );

		$_test_terms['post_tag:4']->name = 'Renamed by the client';

		$result = $this->queue->approve( $data['pending_action_id'], 'editor' );
		$this->assertSame( 'pending_action_stale', $result->get_error_code() );
	}

	public function test_category_create_is_never_held() {
		$this->seed_term( 99, 'category' ); // The id the wp_insert_term() stub returns.
		$response = $this->helper->create_category( $this->request( 0, array( 'name' => 'Fresh' ) ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'held', $response->get_data() );
	}

	public function test_term_writes_apply_without_force_draft() {
		global $_test_options;
		$_test_options['aa_force_draft'] = false;
		$this->seed_term( 3, 'category' );

		$this->assertSame( 200, $this->helper->update_category( $this->request( 3, array( 'name' => 'New' ) ) )->get_status() );
		$this->assertSame( 200, $this->helper->delete_category( $this->request( 3 ) )->get_status() );
	}

	// ─── Media ─────────────────────────────────────────────────────────

	public function test_media_update_held_then_applied() {
		global $_test_post_meta;
		$this->seed_post( 7, 'inherit', 'attachment' );

		$data = $this->assert_held( $this->helper->update_media( $this->request( 7, array( 'alt_text' => 'A cat' ) ) ), 'media.update' );
		$this->assertArrayNotHasKey( '_wp_attachment_image_alt', $_test_post_meta[7] );

		$this->queue->approve( $data['pending_action_id'], 'editor' );
		$this->assertSame( 'A cat', $_test_post_meta[7]['_wp_attachment_image_alt'] );
	}

	public function test_media_update_stale_when_alt_changed_meanwhile() {
		global $_test_post_meta;
		$this->seed_post( 7, 'inherit', 'attachment' );
		$data = $this->assert_held( $this->helper->update_media( $this->request( 7, array( 'alt_text' => 'A cat' ) ) ), 'media.update' );

		$_test_post_meta[7]['_wp_attachment_image_alt'] = 'Typed by the client';

		$this->assertSame( 'pending_action_stale', $this->queue->approve( $data['pending_action_id'], 'editor' )->get_error_code() );
	}

	public function test_media_delete_held() {
		$this->seed_post( 7, 'inherit', 'attachment' );
		$this->assert_held( $this->helper->delete_media( $this->request( 7 ) ), 'media.delete' );
	}

	// ─── Redirects ─────────────────────────────────────────────────────

	public function test_redirect_create_held_then_applied() {
		global $_test_posts;
		$data = $this->assert_held(
			$this->helper->create_redirect( $this->request( 0, array( 'source' => '/old', 'target' => 'https://example.com/new' ) ) ),
			'redirect.create'
		);
		$this->assertNull( $data['object']['id'] );
		$this->assertNull( $data['base_version'] );

		$before = count( array_filter( $_test_posts, fn( $p ) => 'arcadia_redirect' === $p->post_type ) );
		$this->queue->approve( $data['pending_action_id'], 'editor' );
		$after = count( array_filter( $_test_posts, fn( $p ) => 'arcadia_redirect' === $p->post_type ) );
		$this->assertSame( $before + 1, $after );
	}

	public function test_redirect_delete_held() {
		$this->seed_post( 9, 'publish', 'arcadia_redirect' );
		$this->assert_held( $this->helper->delete_redirect( $this->request( 9 ) ), 'redirect.delete' );
	}

	// ─── Article revision carries the common fields + C04 ───────────────

	public function test_revision_response_carries_common_held_fields() {
		$this->seed_post( 42 );
		$response = $this->helper->update_post( $this->request( 42, array( 'title' => 'New' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['held'] );
		$this->assertSame( 'content.update', $data['action'] );
		$this->assertSame( $data['revision_id'], $data['pending_action_id'] );
		$this->assertTrue( $data['revision_created'] );
		$this->assertNotNull( $data['base_version'] );
	}

	public function test_revision_approval_refused_when_article_changed_since() {
		global $_test_posts;
		$this->seed_post( 42 );
		$data = $this->helper->update_post( $this->request( 42, array( 'title' => 'Agent title' ) ) )->get_data();

		$_test_posts[42]->post_modified_gmt = '2026-03-03 09:00:00';
		$_test_posts[42]->post_title        = 'Client fix';

		$result = \Arcadia_Revisions::get_instance()->approve_revision( $data['revision_id'], 'editor' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pending_action_stale', $result->get_error_code() );
		$this->assertSame( 'Client fix', $_test_posts[42]->post_title, "the client's fix survives" );
		$this->assertSame( 'stale', $_test_posts[ $data['revision_id'] ]->post_status );
	}

	public function test_legacy_revision_without_base_is_stale_if_article_modified_after_it() {
		global $_test_posts, $_test_post_meta;
		$this->seed_post( 42 );
		$data = $this->helper->update_post( $this->request( 42, array( 'title' => 'Agent title' ) ) )->get_data();
		// A revision made before 0.13.0 has no base version.
		unset( $_test_post_meta[ $data['revision_id'] ]['_aa_revision_base_version'] );
		$_test_posts[ $data['revision_id'] ]->post_date = '2026-01-05 00:00:00';
		$_test_posts[42]->post_modified_gmt             = '2026-01-06 00:00:00';

		$result = \Arcadia_Revisions::get_instance()->approve_revision( $data['revision_id'], 'editor' );

		$this->assertSame( 'pending_action_stale', $result->get_error_code() );
	}

	// ─── Withdraw + listing ────────────────────────────────────────────

	public function test_withdraw_then_list() {
		global $_test_posts;
		$this->seed_post( 42 );
		$this->seed_term( 3, 'category' );
		$delete = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );
		$this->assert_held( $this->helper->update_category( $this->request( 3, array( 'name' => 'New' ) ) ), 'term.update' );

		$withdrawn = $this->helper->withdraw_pending_action( $this->request( $delete['pending_action_id'] ) );
		$this->assertSame( 'withdrawn', $withdrawn->get_data()['status'] );
		$this->assertSame( 'withdrawn', $_test_posts[ $delete['pending_action_id'] ]->post_status );

		$again = $this->helper->withdraw_pending_action( $this->request( $delete['pending_action_id'] ) );
		$this->assertSame( 'action_not_pending', $again->get_error_code() );

		$list = $this->helper->list_pending_actions( $this->request( 0, array(), array( 'status' => 'pending' ) ) )->get_data()['pending_actions'];
		$this->assertCount( 1, $list );
		$this->assertSame( 'term.update', $list[0]['action'] );
		$this->assertSame( 'pending', $list[0]['status'] );
	}

	public function test_list_skips_kinds_whose_read_scope_is_disabled() {
		$this->seed_post( 42 );
		$this->seed_term( 3, 'category' );
		$this->helper->delete_post( $this->request( 42 ) );
		$this->helper->update_category( $this->request( 3, array( 'name' => 'New' ) ) );
		$this->helper->denied = array( 'taxonomies:read' );

		$list = $this->helper->list_pending_actions( $this->request( 0 ) )->get_data()['pending_actions'];
		$this->assertSame( array( 'content.delete' ), array_column( $list, 'action' ) );

		$explicit = $this->helper->list_pending_actions( $this->request( 0, array(), array( 'object_kind' => 'term' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $explicit );
	}

	public function test_withdraw_needs_the_write_scope_of_the_kind() {
		$this->seed_post( 42 );
		$delete               = $this->assert_held( $this->helper->delete_post( $this->request( 42 ) ), 'content.delete' );
		$this->helper->denied = array( 'articles:write' );

		$this->assertInstanceOf( \WP_Error::class, $this->helper->withdraw_pending_action( $this->request( $delete['pending_action_id'] ) ) );
	}

	public function test_withdraw_unknown_id() {
		$this->assertSame( 'pending_action_not_found', $this->helper->withdraw_pending_action( $this->request( 123456 ) )->get_error_code() );
	}

	// ─── Nothing is held without Force Draft ───────────────────────────

	public function test_without_force_draft_nothing_is_queued() {
		global $_test_options, $_test_posts;
		$_test_options['aa_force_draft'] = false;
		$this->seed_post( 42 );
		$this->seed_post( 7, 'inherit', 'attachment' );
		$this->seed_term( 3, 'category' );

		$this->helper->delete_post( $this->request( 42 ) );
		$this->helper->update_media( $this->request( 7, array( 'title' => 'T' ) ) );
		$this->helper->update_category( $this->request( 3, array( 'name' => 'N' ) ) );
		$this->helper->create_redirect( $this->request( 0, array( 'source' => '/a', 'target' => 'https://example.com/b' ) ) );

		$queued = array_filter( $_test_posts, fn( $p ) => \Arcadia_Pending_Actions::POST_TYPE === $p->post_type );
		$this->assertCount( 0, $queued );
	}
}
