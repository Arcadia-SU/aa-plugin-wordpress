<?php
/**
 * Tests for the dry_run flag on article write endpoints (Phase 32).
 *
 * POST /articles?dry_run=true and PUT /articles/{id}?dry_run=true run the full
 * validation + normalization pipeline but persist nothing, returning the blocks
 * that would be stored. Validation failures mirror a real write (WP_Error / 422).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-post-builder.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';

/**
 * Configurable block-generator mock. json_to_blocks() returns whatever is set
 * in $next_result (a rendered string, or a WP_Error to simulate validation).
 */
class DryRunBlocksMock {

	/**
	 * Value returned by json_to_blocks().
	 *
	 * @var string|\WP_Error
	 */
	public $next_result = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';

	/**
	 * Mimic Arcadia_Blocks::json_to_blocks() ($dry_run is accepted, ignored).
	 *
	 * @param array  $json      Content structure.
	 * @param string $post_type Post type.
	 * @param bool   $dry_run   Dry-run flag.
	 * @return string|\WP_Error
	 */
	public function json_to_blocks( $json, $post_type = 'post', $dry_run = false ) {
		return $this->next_result;
	}
}

/**
 * Minimal class exposing the posts trait methods for testing.
 */
class DryRunHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	/**
	 * Block generator mock.
	 *
	 * @var DryRunBlocksMock
	 */
	public $blocks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->blocks = new DryRunBlocksMock();
	}
}

/**
 * Test class for the dry_run write path.
 */
class ArticleDryRunTest extends TestCase {

	/**
	 * @var DryRunHelper
	 */
	private $helper;

	/**
	 * Set up fixtures.
	 */
	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_taxonomies, $_test_next_post_id, $_test_users,
			$_test_parse_blocks_results, $_test_acf_block_types, $_test_acf_field_groups,
			$_test_acf_fields_by_group, $_test_get_fields_results;

		$_test_options              = array();
		$_test_posts                = array();
		$_test_post_meta            = array();
		$_test_post_categories      = array();
		$_test_post_tags            = array();
		$_test_taxonomies           = array();
		$_test_next_post_id         = 1000;
		$_test_users                = array(
			1 => (object) array(
				'ID'           => 1,
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'roles'        => array( 'administrator' ),
			),
		);
		$_test_parse_blocks_results = array();
		$_test_acf_block_types      = array();
		$_test_acf_field_groups     = array();
		$_test_acf_fields_by_group  = array();
		$_test_get_fields_results   = array();

		// Reset block singletons so the registry rebuilds cleanly.
		foreach ( array( 'Arcadia_Block_Registry', 'Arcadia_Blocks', 'Arcadia_ACF_Validator' ) as $klass ) {
			$ref  = new ReflectionClass( $klass );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		$this->helper = new DryRunHelper();
	}

	/**
	 * Build a request, defaulting to dry_run=true.
	 *
	 * @param array    $body    JSON body.
	 * @param bool     $dry_run Whether to set the dry_run param.
	 * @param int|null $id      Post ID (update mode) or null (create).
	 * @return \WP_REST_Request
	 */
	private function make_request( array $body, $dry_run = true, $id = null ) {
		$request = new \WP_REST_Request();
		if ( null !== $id ) {
			$request->set_param( 'id', $id );
		}
		if ( $dry_run ) {
			$request->set_param( 'dry_run', true );
		}
		$request->set_json_params( $body );
		return $request;
	}

	// =========================================================================
	// POST /articles?dry_run=true
	// =========================================================================

	/**
	 * Dry-run create persists nothing and returns the normalized blocks.
	 */
	public function test_dry_run_create_does_not_persist_and_returns_blocks(): void {
		global $_test_posts, $_test_parse_blocks_results;

		$rendered = $this->helper->blocks->next_result;
		$_test_parse_blocks_results[ $rendered ] = array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerHTML'   => '<p>Hello</p>',
				'innerBlocks' => array(),
			),
		);

		$request = $this->make_request(
			array(
				'title'      => 'Dry Run Article',
				'children'   => array( array( 'type' => 'paragraph', 'content' => 'Hello' ) ),
				'acf_fields' => array( 'subtitle' => 'Sub' ),
			)
		);

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['dry_run'] );
		$this->assertTrue( $data['valid'] );
		$this->assertNotEmpty( $data['blocks'] );
		$this->assertEquals( 'core/paragraph', $data['blocks'][0]['blockName'] );
		// Option A: field_values is omitted from dry-run (it could only echo raw,
		// un-coerced input — divergent from the coerced values a real write stores).
		$this->assertArrayNotHasKey( 'field_values', $data );
		// force_draft option off → preview reports the requested status would hold.
		$this->assertFalse( $data['force_draft_applied'] );

		// Nothing persisted.
		$this->assertCount( 0, $_test_posts );
	}

	/**
	 * Dry-run create with invalid ACF mirrors a real write: WP_Error / 422.
	 */
	public function test_dry_run_create_invalid_acf_returns_422(): void {
		global $_test_posts;

		$this->helper->blocks->next_result = new \WP_Error(
			'acf_validation_failed',
			'ACF block validation failed.',
			array(
				'status' => 422,
				'errors' => array(
					array( 'field' => 'is_lightbox', 'error' => 'expected bool' ),
				),
			)
		);

		$request = $this->make_request(
			array(
				'title'    => 'Bad',
				'children' => array(
					array( 'type' => 'acf/text-image', 'properties' => array( 'is_lightbox' => 'banana' ) ),
				),
			)
		);

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'acf_validation_failed', $result->get_error_code() );
		$err = $result->get_error_data();
		$this->assertEquals( 422, $err['status'] );
		$this->assertNotEmpty( $err['errors'] );

		// Nothing persisted.
		$this->assertCount( 0, $_test_posts );
	}

	/**
	 * Dry-run create with no structured content returns empty blocks, no persist.
	 */
	public function test_dry_run_create_no_content_returns_empty_blocks(): void {
		global $_test_posts;

		$request = $this->make_request( array( 'title' => 'Title only' ) );

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertTrue( $data['dry_run'] );
		$this->assertSame( array(), $data['blocks'] );
		$this->assertCount( 0, $_test_posts );
	}

	/**
	 * Without the flag, create still persists (regression guard).
	 */
	public function test_create_without_flag_still_persists(): void {
		global $_test_posts;

		$request = $this->make_request(
			array(
				'title'   => 'Real Article',
				'status'  => 'draft',
				'content' => 'Hello world',
			),
			false
		);

		$result = $this->helper->create_post( $request );

		$data = $result->get_data();
		$this->assertArrayNotHasKey( 'dry_run', $data );
		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $_test_posts );
	}

	// =========================================================================
	// PUT /articles/{id}?dry_run=true
	// =========================================================================

	/**
	 * Dry-run update of a published post under revision enforcement creates no
	 * revision and leaves the live post untouched.
	 */
	public function test_dry_run_update_published_skips_revision(): void {
		global $_test_options, $_test_posts, $_test_parse_blocks_results;

		// Revision enforcement active + published post would normally create a revision.
		$_test_options['aa_force_draft'] = true;
		$_test_posts[42]                 = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Original Title',
			'post_status'    => 'publish',
			'post_content'   => '<p>Original</p>',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'original',
			'post_mime_type' => '',
		);

		$rendered = $this->helper->blocks->next_result;
		$_test_parse_blocks_results[ $rendered ] = array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerHTML'   => '<p>Hello</p>',
				'innerBlocks' => array(),
			),
		);

		$request = $this->make_request(
			array(
				'title'    => 'Proposed',
				'children' => array( array( 'type' => 'paragraph', 'content' => 'Hello' ) ),
			),
			true,
			42
		);

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['dry_run'] );
		$this->assertArrayNotHasKey( 'revision_created', $data );
		// force_draft is enabled → preview surfaces that the real write would draft.
		$this->assertTrue( $data['force_draft_applied'] );

		// Live post unchanged; no revision CPT added (only the original post remains).
		$this->assertEquals( 'Original Title', $_test_posts[42]->post_title );
		$this->assertCount( 1, $_test_posts );
	}

	// =========================================================================
	// Fail-safe flag reading (finding 9 — never persist on ambiguity)
	// =========================================================================

	/**
	 * dry_run set only in the JSON body (not via the query/param store) still
	 * triggers preview mode. Regression guard: get_param() alone never reads the
	 * JSON body, so a body-only flag would have silently performed a real write.
	 */
	public function test_dry_run_flag_in_json_body_is_honored(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'title'   => 'Body-flag article',
				'dry_run' => true,
			)
		);

		$result = $this->helper->create_post( $request );
		$data   = $result->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertCount( 0, $_test_posts );
	}

	/**
	 * Conflict resolution: query says dry_run=false but the body says true →
	 * preview wins, nothing is persisted. Ambiguity must never resolve toward an
	 * accidental real write.
	 */
	public function test_dry_run_query_body_conflict_prefers_preview(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_query_params( array( 'dry_run' => 'false' ) );
		$request->set_json_params(
			array(
				'title'   => 'Conflict article',
				'dry_run' => true,
			)
		);

		$result = $this->helper->create_post( $request );
		$data   = $result->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertCount( 0, $_test_posts );
	}
}
