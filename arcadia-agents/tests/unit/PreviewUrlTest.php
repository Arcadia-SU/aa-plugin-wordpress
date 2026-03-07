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
}
