<?php
/**
 * Tests for the consolidated permission gate (Phase B).
 *
 * Replaces 12 near-identical check_*_permission methods with a single
 * parameterized check_permission($request, $scope) that delegates to
 * Arcadia_Auth::authenticate_request(). Covered behaviors:
 *
 *   1. true on success — when auth succeeds, returns boolean true (not the
 *      payload, since check_permission narrows the contract for REST).
 *   2. WP_Error propagation — when auth returns WP_Error, propagate it
 *      verbatim so REST surfaces the auth-level code/message/status.
 *   3. Scope passthrough — the scope argument is forwarded unchanged to
 *      authenticate_request, for every scope the API actually uses.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load API dependencies.
require_once dirname( __DIR__, 2 ) . '/includes/class-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-api.php';

/**
 * Recording stub for Arcadia_Auth — captures the scope passed and
 * returns whatever the test pre-configured.
 */
class RecordingAuthStub {
	/** @var array<int, array{scope: string|null}> */
	public $calls = array();

	/** @var mixed Whatever authenticate_request() should return. */
	public $next_return = true;

	/**
	 * Match the signature of Arcadia_Auth::authenticate_request().
	 *
	 * @param mixed  $request        Ignored — only $required_scope matters here.
	 * @param string $required_scope Scope being checked.
	 * @return mixed
	 */
	public function authenticate_request( $request, $required_scope = null ) {
		$this->calls[] = array( 'scope' => $required_scope );
		return $this->next_return;
	}
}

/**
 * Tests for Arcadia_API::check_permission().
 */
class PermissionConsolidationTest extends TestCase {

	/**
	 * All 12 scopes the REST routes register against (Phase B).
	 *
	 * Single source of truth for this test: if any scope is added or
	 * removed in class-api.php, this list must be updated.
	 *
	 * @return array<int, array{0: string}>
	 */
	public static function scope_provider(): array {
		return array(
			array( 'articles:read' ),
			array( 'articles:write' ),
			array( 'articles:delete' ),
			array( 'media:read' ),
			array( 'media:write' ),
			array( 'media:delete' ),
			array( 'taxonomies:read' ),
			array( 'taxonomies:write' ),
			array( 'taxonomies:delete' ),
			array( 'redirects:read' ),
			array( 'redirects:write' ),
			array( 'settings:write' ),
			array( 'site:read' ),
		);
	}

	/**
	 * Build an Arcadia_API instance with a recording auth stub injected.
	 *
	 * @return array{0: \Arcadia_API, 1: RecordingAuthStub}
	 */
	private function api_with_stub_auth(): array {
		$api  = \Arcadia_API::get_instance();
		$stub = new RecordingAuthStub();

		$ref  = new \ReflectionClass( \Arcadia_API::class );
		$prop = $ref->getProperty( 'auth' );
		$prop->setAccessible( true );
		$prop->setValue( $api, $stub );

		return array( $api, $stub );
	}

	/**
	 * Invoke the private check_permission via reflection.
	 *
	 * @param \Arcadia_API $api API instance.
	 * @param string       $scope Scope to check.
	 * @return mixed
	 */
	private function invoke_check_permission( $api, $scope ) {
		$method = new \ReflectionMethod( \Arcadia_API::class, 'check_permission' );
		$method->setAccessible( true );
		return $method->invoke( $api, new \WP_REST_Request(), $scope );
	}

	/**
	 * Authenticated requests return true (not the payload) and forward the scope.
	 *
	 * @dataProvider scope_provider
	 */
	public function test_permission_granted_returns_true_and_forwards_scope( string $scope ): void {
		[ $api, $stub ]    = $this->api_with_stub_auth();
		$stub->next_return = array( 'sub' => 'agent' ); // Successful payload.

		$result = $this->invoke_check_permission( $api, $scope );

		$this->assertTrue( $result, "Scope {$scope} should yield boolean true on success" );
		$this->assertCount( 1, $stub->calls );
		$this->assertSame( $scope, $stub->calls[0]['scope'] );
	}

	/**
	 * WP_Error from auth is propagated verbatim, not collapsed to false.
	 *
	 * @dataProvider scope_provider
	 */
	public function test_permission_denied_propagates_wp_error( string $scope ): void {
		[ $api, $stub ]    = $this->api_with_stub_auth();
		$stub->next_return = new \WP_Error(
			'scope_denied',
			'Scope ' . $scope . ' is disabled in WP admin.',
			array( 'status' => 403 )
		);

		$result = $this->invoke_check_permission( $api, $scope );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'scope_denied', $result->get_error_code() );
		$this->assertSame( $scope, $stub->calls[0]['scope'] );
	}
}
