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
     * Test check_scope with enabled scope.
     */
    public function test_check_scope_enabled(): void {
        global $_test_options;

        // Enable all scopes.
        $_test_options['arcadia_agents_scopes'] = array(
            'articles:read',
            'articles:write',
            'media:read',
        );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->check_scope( 'articles:read', array( 'articles:read' ) );

        $this->assertTrue( $result );
    }

    /**
     * Test check_scope with disabled scope in WP settings.
     */
    public function test_check_scope_disabled_in_wp(): void {
        global $_test_options;

        // Only enable articles:read.
        $_test_options['arcadia_agents_scopes'] = array( 'articles:read' );

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->check_scope( 'articles:write', array( 'articles:write' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'scope_denied', $result->get_error_code() );
    }

    /**
     * Test check_scope when token doesn't have scope.
     */
    public function test_check_scope_not_in_token(): void {
        global $_test_options;

        // Enable all scopes in WP.
        $_test_options['arcadia_agents_scopes'] = array(
            'articles:read',
            'articles:write',
            'media:read',
        );

        $auth = \Arcadia_Auth::get_instance();
        // Token only has articles:read, but we need articles:write.
        $result = $auth->check_scope( 'articles:write', array( 'articles:read' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'scope_not_granted', $result->get_error_code() );
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

        $auth   = \Arcadia_Auth::get_instance();
        $result = $auth->disconnect();

        $this->assertTrue( $result );
        $this->assertArrayNotHasKey( 'arcadia_agents_public_key', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_connected', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_connected_at', $_test_options );
        $this->assertArrayNotHasKey( 'arcadia_agents_last_activity', $_test_options );
    }

    /**
     * Test scope checking with empty token scopes defaults to allowed.
     */
    public function test_check_scope_empty_token_scopes(): void {
        global $_test_options;

        // Enable scope in WP.
        $_test_options['arcadia_agents_scopes'] = array( 'articles:read' );

        $auth = \Arcadia_Auth::get_instance();
        // Empty token scopes - should pass (WP scope is enabled, no token restriction).
        $result = $auth->check_scope( 'articles:read', array() );

        $this->assertTrue( $result );
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
            'taxonomies:read',
            'taxonomies:write',
            'site:read',
        );

        // These are the scopes the plugin should support.
        $this->assertCount( 8, $expected_scopes );
        $this->assertContains( 'articles:read', $expected_scopes );
        $this->assertContains( 'articles:write', $expected_scopes );
        $this->assertContains( 'articles:delete', $expected_scopes );
        $this->assertContains( 'media:read', $expected_scopes );
        $this->assertContains( 'media:write', $expected_scopes );
        $this->assertContains( 'taxonomies:read', $expected_scopes );
        $this->assertContains( 'taxonomies:write', $expected_scopes );
        $this->assertContains( 'site:read', $expected_scopes );
    }
}
