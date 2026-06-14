<?php
/**
 * Tests for Arcadia_Auth class.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load the auth class for testing.
require_once dirname( __DIR__, 2 ) . '/includes/class-auth.php';

/**
 * Test class for authentication functions.
 */
class AuthTest extends TestCase {

    /**
     * Reset options before each test.
     */
    protected function setUp(): void {
        global $_test_options;
        $_test_options = array();
    }

    /**
     * Test check_scope with enabled scope (WP admin settings only).
     */
    public function test_check_scope_enabled(): void {
        global $_test_options;

        $_test_options['arcadia_agents_scopes'] = array(
            'articles:read',
            'articles:write',
            'media:read',
        );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->check_scope( 'articles:read' );

        $this->assertTrue( $result );
    }

    /**
     * Test check_scope with disabled scope in WP settings.
     */
    public function test_check_scope_disabled_in_wp(): void {
        global $_test_options;

        $_test_options['arcadia_agents_scopes'] = array( 'articles:read' );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->check_scope( 'articles:write' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'scope_denied', $result->get_error_code() );
    }

    /**
     * Test validate_jwt without public key configured.
     */
    public function test_validate_jwt_not_configured(): void {
        global $_test_options;

        // No public key set.
        $_test_options = array();

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_jwt( 'some.jwt.token' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'not_configured', $result->get_error_code() );
    }

    /**
     * Test error_response helper.
     */
    public function test_error_response(): void {
        $auth = \Arcadia_Auth::get_instance();

        $error = $auth->error_response( 'test_code', 'Test message', 400 );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertEquals( 'test_code', $error->get_error_code() );
        $this->assertEquals( 'Test message', $error->get_error_message() );

        $data = $error->get_error_data();
        $this->assertEquals( 400, $data['status'] );
    }

    /**
     * Test disconnect clears options.
     */
    public function test_disconnect(): void {
        global $_test_options;

        // Set some options.
        $_test_options['arcadia_agents_public_key']    = 'test_key';
        $_test_options['arcadia_agents_connected']     = true;
        $_test_options['arcadia_agents_connected_at']  = '2025-01-01 00:00:00';
        $_test_options['arcadia_agents_last_activity'] = '2025-01-02 00:00:00';
        $_test_options['arcadia_agents_site_id']       = 'site-abc';

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->disconnect();

        $this->assertTrue( $result );
        $this->assertArrayNotHasKey( 'arcadia_agents_public_key', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_connected', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_connected_at', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_last_activity', $_test_options );
        // The site identity pin must be cleared so a re-handshake can re-pin.
        $this->assertArrayNotHasKey( 'arcadia_agents_site_id', $_test_options );
    }

    // -------------------------------------------------------
    // validate_claims() — JWT identity (A5: iss + site binding)
    // -------------------------------------------------------

    /**
     * First valid token pins the site identity (trust-on-first-use).
     */
    public function test_validate_claims_pins_site_on_first_use(): void {
        global $_test_options;
        $_test_options = array();

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_claims(
            array( 'iss' => 'arcadia-agents', 'sub' => 'site-123' )
        );

        $this->assertTrue( $result );
        $this->assertSame( 'site-123', $_test_options['arcadia_agents_site_id'] );
    }

    /**
     * A token whose sub matches the pinned site is accepted.
     */
    public function test_validate_claims_accepts_matching_site(): void {
        global $_test_options;
        $_test_options = array( 'arcadia_agents_site_id' => 'site-123' );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_claims(
            array( 'iss' => 'arcadia-agents', 'sub' => 'site-123' )
        );

        $this->assertTrue( $result );
    }

    /**
     * A token minted for a different site (same keypair) is rejected.
     */
    public function test_validate_claims_rejects_site_mismatch(): void {
        global $_test_options;
        $_test_options = array( 'arcadia_agents_site_id' => 'site-123' );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_claims(
            array( 'iss' => 'arcadia-agents', 'sub' => 'site-999' )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'site_mismatch', $result->get_error_code() );
        // The original pin must not be overwritten by the rejected token.
        $this->assertSame( 'site-123', $_test_options['arcadia_agents_site_id'] );
    }

    /**
     * A token with the wrong issuer is rejected, even with a valid signature.
     */
    public function test_validate_claims_rejects_bad_issuer(): void {
        global $_test_options;
        $_test_options = array();

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_claims(
            array( 'iss' => 'evil-issuer', 'sub' => 'site-123' )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'invalid_issuer', $result->get_error_code() );
        // Must not pin a site from a rejected token.
        $this->assertArrayNotHasKey( 'arcadia_agents_site_id', $_test_options );
    }

    /**
     * A token without a sub claim is rejected (cannot identify a site).
     */
    public function test_validate_claims_rejects_missing_subject(): void {
        global $_test_options;
        $_test_options = array();

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->validate_claims(
            array( 'iss' => 'arcadia-agents' )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_subject', $result->get_error_code() );
    }

    /**
     * Test check_scope uses default all_scopes when no option set.
     */
    public function test_check_scope_default_all_enabled(): void {
        global $_test_options;

        // No scopes option set — defaults to all scopes enabled.
        unset( $_test_options['arcadia_agents_scopes'] );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->check_scope( 'articles:read' );

        $this->assertTrue( $result );
    }

    /**
     * Test get_enabled_scopes returns current WP settings.
     */
    public function test_get_enabled_scopes(): void {
        global $_test_options;

        $scopes = array( 'articles:read', 'media:write' );
        $_test_options['arcadia_agents_scopes'] = $scopes;

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->get_enabled_scopes();

        $this->assertEquals( $scopes, $result );
    }

    /**
     * Test get_enabled_scopes returns all scopes when no option set.
     */
    public function test_get_enabled_scopes_default(): void {
        global $_test_options;

        unset( $_test_options['arcadia_agents_scopes'] );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->get_enabled_scopes();

        $this->assertCount( 13, $result );
        $this->assertContains( 'articles:read', $result );
        $this->assertContains( 'settings:write', $result );
    }

    // -------------------------------------------------------
    // get_bearer_token() — X-AA-Token fallback tests
    // -------------------------------------------------------

    /**
     * Test token extracted from standard Authorization header.
     */
    public function test_get_bearer_token_from_authorization(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();
        $request->set_header( 'Authorization', 'Bearer my.jwt.token' );

        $result = $auth->get_bearer_token( $request );

        $this->assertEquals( 'my.jwt.token', $result );
    }

    /**
     * Test token extracted from X-AA-Token when Authorization is absent.
     */
    public function test_get_bearer_token_fallback_xaa_token(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();
        $request->set_header( 'X-AA-Token', 'Bearer fallback.jwt.token' );

        $result = $auth->get_bearer_token( $request );

        $this->assertEquals( 'fallback.jwt.token', $result );
    }

    /**
     * Test X-AA-Token used when Authorization has Basic Auth (not Bearer).
     */
    public function test_get_bearer_token_xaa_when_authorization_is_basic(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();
        $request->set_header( 'Authorization', 'Basic dXNlcjpwYXNz' );
        $request->set_header( 'X-AA-Token', 'Bearer jwt.via.xaa' );

        $result = $auth->get_bearer_token( $request );

        $this->assertEquals( 'jwt.via.xaa', $result );
    }

    /**
     * Test Authorization Bearer takes priority over X-AA-Token.
     */
    public function test_get_bearer_token_authorization_takes_priority(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();
        $request->set_header( 'Authorization', 'Bearer priority.token' );
        $request->set_header( 'X-AA-Token', 'Bearer fallback.token' );

        $result = $auth->get_bearer_token( $request );

        $this->assertEquals( 'priority.token', $result );
    }

    /**
     * Test error when no valid Bearer token in any header.
     */
    public function test_get_bearer_token_error_when_no_headers(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();

        $result = $auth->get_bearer_token( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_authorization', $result->get_error_code() );
    }

    /**
     * Test error when both headers present but neither has Bearer format.
     */
    public function test_get_bearer_token_error_when_no_bearer_format(): void {
        $auth    = \Arcadia_Auth::get_instance();
        $request = new \WP_REST_Request();
        $request->set_header( 'Authorization', 'Basic dXNlcjpwYXNz' );
        $request->set_header( 'X-AA-Token', 'InvalidFormat' );

        $result = $auth->get_bearer_token( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_authorization', $result->get_error_code() );
    }

    /**
     * Test available scopes list.
     */
    public function test_available_scopes(): void {
        $expected_scopes = array(
            'articles:read',
            'articles:write',
            'articles:delete',
            'media:read',
            'media:write',
            'media:delete',
            'taxonomies:read',
            'taxonomies:write',
            'taxonomies:delete',
            'site:read',
            'redirects:read',
            'redirects:write',
            'settings:write',
        );

        // These are the scopes the plugin should support (v2 + FS: 13 scopes).
        $this->assertCount( 13, $expected_scopes );
        $this->assertContains( 'articles:read', $expected_scopes );
        $this->assertContains( 'articles:write', $expected_scopes );
        $this->assertContains( 'articles:delete', $expected_scopes );
        $this->assertContains( 'media:read', $expected_scopes );
        $this->assertContains( 'media:write', $expected_scopes );
        $this->assertContains( 'media:delete', $expected_scopes );
        $this->assertContains( 'taxonomies:read', $expected_scopes );
        $this->assertContains( 'taxonomies:write', $expected_scopes );
        $this->assertContains( 'taxonomies:delete', $expected_scopes );
        $this->assertContains( 'site:read', $expected_scopes );
        $this->assertContains( 'redirects:read', $expected_scopes );
        $this->assertContains( 'redirects:write', $expected_scopes );
        $this->assertContains( 'settings:write', $expected_scopes );
    }
}
