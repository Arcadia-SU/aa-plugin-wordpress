<?php
/**
 * Tests for Preview URL feature.
 *
 * Tests that:
 * - Token generation stores meta correctly
 * - Token validation works (valid, expired, wrong token)
 * - API endpoint returns correct response
 * - API endpoint returns 404 for missing post
 * - Cleanup removes expired tokens
 * - Schedule/unschedule cron
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load preview class.
require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';

// Load API trait.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-preview.php';

/**
 * Minimal class exposing preview trait for testing.
 */
class PreviewApiHelper {
	use \Arcadia_API_Preview_Handler;
}

/**
 * Test class for Preview URL feature.
 */
class PreviewUrlTest extends TestCase {

	/** @var \Arcadia_Preview */
	private $preview;

	/** @var PreviewApiHelper */
	private $api;

	protected function setUp(): void {
		global $_test_posts, $_test_post_meta, $_test_scheduled_events;

		$_test_posts            = array();
		$_test_post_meta        = array();
		$_test_scheduled_events = array();

		// Use a fresh instance for each test.
		$reflection = new \ReflectionClass( \Arcadia_Preview::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->preview = \Arcadia_Preview::get_instance();
		$this->api     = new PreviewApiHelper();
	}

	// =========================================================================
	// Token generation
	// =========================================================================

	/**
	 * Test that generate_token returns a 32-char hex string and stores meta.
	 */
	public function test_generate_token_format_and_storage(): void {
		global $_test_post_meta;

		$token = $this->preview->generate_token( 42 );

		// 16 random bytes → 32 hex chars.
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );

		// Check meta stored.
		$this->assertEquals( $token, $_test_post_meta[42]['_aa_preview_token'] );
		$this->assertIsInt( (int) $_test_post_meta[42]['_aa_preview_expires'] );
		$this->assertGreaterThan( time(), (int) $_test_post_meta[42]['_aa_preview_expires'] );
	}

	/**
	 * Test that generating a new token replaces the old one.
	 */
	public function test_generate_token_replaces_existing(): void {
		global $_test_post_meta;

		$token1 = $this->preview->generate_token( 42 );
		$token2 = $this->preview->generate_token( 42 );

		$this->assertNotEquals( $token1, $token2 );
		$this->assertEquals( $token2, $_test_post_meta[42]['_aa_preview_token'] );
	}

	// =========================================================================
	// get_or_create_token
	// =========================================================================

	/**
	 * Test that get_or_create_token generates a new token when none exists.
	 */
	public function test_get_or_create_token_generates_when_none(): void {
		$token = $this->preview->get_or_create_token( 50 );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Test that get_or_create_token reuses a valid existing token.
	 */
	public function test_get_or_create_token_reuses_valid(): void {
		$token1 = $this->preview->generate_token( 50 );
		$token2 = $this->preview->get_or_create_token( 50 );

		$this->assertEquals( $token1, $token2 );
	}

	/**
	 * Test that get_or_create_token regenerates when expired.
	 */
	public function test_get_or_create_token_regenerates_expired(): void {
		global $_test_post_meta;

		$token1 = $this->preview->generate_token( 50 );

		// Force expiry.
		$_test_post_meta[50]['_aa_preview_expires'] = time() - 1;

		$token2 = $this->preview->get_or_create_token( 50 );

		$this->assertNotEquals( $token1, $token2 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token2 );
	}

	// =========================================================================
	// Token validation
	// =========================================================================

	/**
	 * Test validate_token succeeds with correct token.
	 */
	public function test_validate_token_success(): void {
		$token = $this->preview->generate_token( 42 );

		$this->assertTrue( $this->preview->validate_token( 42, $token ) );
	}

	/**
	 * Test validate_token fails with wrong token.
	 */
	public function test_validate_token_wrong_token(): void {
		$this->preview->generate_token( 42 );

		$this->assertFalse( $this->preview->validate_token( 42, 'deadbeefdeadbeefdeadbeefdeadbeef' ) );
	}

	/**
	 * Test validate_token fails when token is expired.
	 */
	public function test_validate_token_expired(): void {
		global $_test_post_meta;

		$token = $this->preview->generate_token( 42 );

		// Force expiry to the past.
		$_test_post_meta[42]['_aa_preview_expires'] = time() - 1;

		$this->assertFalse( $this->preview->validate_token( 42, $token ) );

		// Expired token should be cleaned up.
		$this->assertArrayNotHasKey( '_aa_preview_token', $_test_post_meta[42] ?? array() );
	}

	/**
	 * Test validate_token fails when no token exists.
	 */
	public function test_validate_token_no_token(): void {
		$this->assertFalse( $this->preview->validate_token( 99, 'anything' ) );
	}

	// =========================================================================
	// API endpoint
	// =========================================================================

	/**
	 * Test get_preview_url returns 404 for missing post.
	 */
	public function test_api_returns_404_for_missing_post(): void {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', 999 );

		$result = $this->api->get_preview_url( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Test get_preview_url returns correct structure for existing post.
	 */
	public function test_api_returns_preview_url(): void {
		global $_test_posts;

		$_test_posts[42] = (object) array(
			'ID'           => 42,
			'post_type'    => 'post',
			'post_title'   => 'Test Post',
			'post_status'  => 'draft',
			'post_content' => '',
			'post_excerpt' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );

		$result = $this->api->get_preview_url( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		$this->assertArrayHasKey( 'preview_url', $data );
		$this->assertArrayHasKey( 'expires_in', $data );
		$this->assertEquals( DAY_IN_SECONDS, $data['expires_in'] );
		$this->assertStringContainsString( 'aa_preview=', $data['preview_url'] );
		$this->assertStringContainsString( 'p=42', $data['preview_url'] );
	}

	// =========================================================================
	// Cron scheduling
	// =========================================================================

	/**
	 * Test schedule_cleanup registers the cron event.
	 */
	public function test_schedule_cleanup(): void {
		global $_test_scheduled_events;

		\Arcadia_Preview::schedule_cleanup();

		$this->assertArrayHasKey( 'arcadia_preview_cleanup', $_test_scheduled_events );
	}

	/**
	 * Test unschedule_cleanup removes the cron event.
	 */
	public function test_unschedule_cleanup(): void {
		global $_test_scheduled_events;

		\Arcadia_Preview::schedule_cleanup();
		\Arcadia_Preview::unschedule_cleanup();

		$this->assertArrayNotHasKey( 'arcadia_preview_cleanup', $_test_scheduled_events );
	}

	// =========================================================================
	// fix_query_for_preview
	// =========================================================================

	/**
	 * Test fix_query_for_preview skips secondary (non-main) queries.
	 */
	public function test_fix_query_skips_secondary_query(): void {
		global $_test_posts;

		$_test_posts[55] = (object) array(
			'ID'           => 55,
			'post_type'    => 'article',
			'post_status'  => 'draft',
			'post_name'    => '',
			'post_title'   => '',
			'post_content' => '',
		);

		$token              = $this->preview->generate_token( 55 );
		$_GET['aa_preview'] = $token;
		$_GET['p']          = '55';

		$query          = new \WP_Query();
		$query->is_main = false;

		$this->preview->fix_query_for_preview( $query );

		$this->assertEmpty( $query->query_vars );

		unset( $_GET['aa_preview'], $_GET['p'] );
	}

	/**
	 * Test fix_query_for_preview skips when token is invalid.
	 */
	public function test_fix_query_skips_invalid_token(): void {
		global $_test_posts;

		$_test_posts[55] = (object) array(
			'ID'           => 55,
			'post_type'    => 'article',
			'post_status'  => 'draft',
			'post_name'    => '',
			'post_title'   => '',
			'post_content' => '',
		);

		$this->preview->generate_token( 55 );
		$_GET['aa_preview'] = 'deadbeefdeadbeefdeadbeefdeadbeef';
		$_GET['p']          = '55';

		$query          = new \WP_Query();
		$query->is_main = true;

		$this->preview->fix_query_for_preview( $query );

		$this->assertEmpty( $query->query_vars );

		unset( $_GET['aa_preview'], $_GET['p'] );
	}

	/**
	 * Test fix_query_for_preview sets post_type for CPT posts.
	 */
	public function test_fix_query_sets_post_type_for_cpt(): void {
		global $_test_posts;

		$_test_posts[55] = (object) array(
			'ID'           => 55,
			'post_type'    => 'article',
			'post_status'  => 'draft',
			'post_name'    => '',
			'post_title'   => '',
			'post_content' => '',
		);

		$token              = $this->preview->generate_token( 55 );
		$_GET['aa_preview'] = $token;
		$_GET['p']          = '55';

		$query          = new \WP_Query();
		$query->is_main = true;

		$this->preview->fix_query_for_preview( $query );

		$this->assertEquals( 'article', $query->get( 'post_type' ) );
		$this->assertEquals(
			array( 'publish', 'draft', 'pending', 'private', 'future' ),
			$query->get( 'post_status' )
		);

		unset( $_GET['aa_preview'], $_GET['p'] );
	}

	/**
	 * Test fix_query_for_preview does NOT set post_type for standard posts.
	 */
	public function test_fix_query_skips_post_type_for_standard_post(): void {
		global $_test_posts;

		$_test_posts[56] = (object) array(
			'ID'           => 56,
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_name'    => '',
			'post_title'   => '',
			'post_content' => '',
		);

		$token              = $this->preview->generate_token( 56 );
		$_GET['aa_preview'] = $token;
		$_GET['p']          = '56';

		$query          = new \WP_Query();
		$query->is_main = true;

		$this->preview->fix_query_for_preview( $query );

		// Should NOT set post_type for standard post.
		$this->assertEquals( '', $query->get( 'post_type' ) );

		// But should set post_status.
		$this->assertEquals(
			array( 'publish', 'draft', 'pending', 'private', 'future' ),
			$query->get( 'post_status' )
		);

		unset( $_GET['aa_preview'], $_GET['p'] );
	}

	// =========================================================================
	// handle_preview — state setup for CPT draft
	// =========================================================================

	/**
	 * Test handle_preview sets status 200 and fully populates wp_query for a draft CPT.
	 *
	 * The handler must populate posts, post_count, and found_posts so that
	 * theme template loops (have_posts / the_post) render content instead
	 * of producing an empty body.
	 */
	public function test_handle_preview_sets_status_200_for_cpt_draft(): void {
		global $_test_posts, $_test_status_header_calls;

		$_test_posts[57] = (object) array(
			'ID'           => 57,
			'post_type'    => 'article',
			'post_title'   => 'Draft CPT Article',
			'post_status'  => 'draft',
			'post_name'    => 'draft-cpt-article',
			'post_content' => '',
		);

		$token              = $this->preview->generate_token( 57 );
		$_GET['aa_preview'] = $token;
		$_GET['p']          = '57';

		// Set up wp_query global.
		$GLOBALS['wp_query'] = new \WP_Query();

		$_test_status_header_calls = array();

		$this->preview->handle_preview();

		// Assert status_header(200) was called.
		$this->assertContains( 200, $_test_status_header_calls );

		// Assert wp_query was fixed — queried_object and flags.
		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertTrue( $GLOBALS['wp_query']->is_single );
		$this->assertTrue( $GLOBALS['wp_query']->is_singular );
		$this->assertEquals( 57, $GLOBALS['wp_query']->queried_object_id );

		// Assert wp_query posts array is populated (root cause of empty body).
		$this->assertCount( 1, $GLOBALS['wp_query']->posts );
		$this->assertEquals( 57, $GLOBALS['wp_query']->posts[0]->ID );
		$this->assertEquals( 'publish', $GLOBALS['wp_query']->posts[0]->post_status );
		$this->assertEquals( 1, $GLOBALS['wp_query']->post_count );
		$this->assertEquals( 1, $GLOBALS['wp_query']->found_posts );
		$this->assertEquals( 57, $GLOBALS['wp_query']->post->ID );

		// Clean up.
		unset( $_GET['aa_preview'], $_GET['p'], $GLOBALS['wp_query'] );
	}
}
