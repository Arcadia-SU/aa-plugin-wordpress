<?php
/**
 * Test: POST /disconnect — Arcadia withdraws the connection (Phase 51).
 *
 * The owner can now remove the WordPress connection from Arcadia's settings.
 * Arcadia then calls this route, best-effort, just before deleting the RS256
 * pair, so the plugin stops showing "Connected" and the key field becomes
 * editable again.
 *
 * These tests run against the REAL Arcadia_Auth with a real RSA key pair and
 * real signed tokens: the security property under test — a token that is not
 * this connection's leaves the site connected — lives in validate_jwt() and
 * validate_claims(), and a stubbed auth would only prove the stub.
 *
 * Every call goes through what was actually registered (permission_callback,
 * then callback), the way WordPress dispatches it.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-api.php';

class DisconnectEndpointTest extends TestCase {

	const SITE_ID = 'site-abc';
	const ISSUER  = 'arcadia-agents';

	/** @var array{private:string, public:string} The connection's key pair. */
	private static $keys;

	/** @var array{private:string, public:string} Someone else's key pair. */
	private static $foreign_keys;

	/** @var array The registered /disconnect endpoint. */
	private $endpoint;

	public static function setUpBeforeClass(): void {
		self::$keys         = self::key_pair();
		self::$foreign_keys = self::key_pair();
	}

	protected function setUp(): void {
		global $_test_options, $_test_registered_routes;
		$_test_options           = array();
		$_test_registered_routes = array();

		$ref = new \ReflectionClass( \Arcadia_API::class );
		$api = $ref->newInstanceWithoutConstructor();
		$prop = $ref->getProperty( 'auth' );
		$prop->setAccessible( true );
		$prop->setValue( $api, \Arcadia_Auth::get_instance() );

		$api->register_routes();

		$this->endpoint = $this->find_endpoint( '/disconnect', 'POST' );
	}

	protected function tearDown(): void {
		global $_test_options, $_test_registered_routes;
		$_test_options           = array();
		$_test_registered_routes = array();
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @return array{private:string, public:string}
	 */
	private static function key_pair(): array {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $private );
		$details = openssl_pkey_get_details( $res );

		return array(
			'private' => $private,
			'public'  => $details['key'],
		);
	}

	/**
	 * The state a successful handshake leaves behind.
	 */
	private function connect(): void {
		global $_test_options;
		$_test_options['arcadia_agents_public_key']    = self::$keys['public'];
		$_test_options['arcadia_agents_connected']     = true;
		$_test_options['arcadia_agents_connected_at']  = '2026-10-01 10:00:00';
		$_test_options['arcadia_agents_last_activity'] = '2026-10-03 10:00:00';
		$_test_options['arcadia_agents_site_id']       = self::SITE_ID;
		$_test_options['arcadia_agents_issuer']        = self::ISSUER;
	}

	private function token( array $claims = array(), ?string $private_key = null ): string {
		$payload = array_merge(
			array(
				'sub' => self::SITE_ID,
				'iss' => self::ISSUER,
				'iat' => time(),
				'exp' => time() + 300,
			),
			$claims
		);

		return JWT::encode( $payload, $private_key ?? self::$keys['private'], 'RS256' );
	}

	private function request( ?string $token ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_method( 'POST' );
		$request->set_route( '/arcadia/v1/disconnect' );
		if ( null !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}
		return $request;
	}

	/**
	 * Dispatch the way WordPress does: permission first, callback only if allowed.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( \WP_REST_Request $request ) {
		$allowed = call_user_func( $this->endpoint['permission_callback'], $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$this->assertTrue( $allowed );
		return call_user_func( $this->endpoint['callback'], $request );
	}

	private function find_endpoint( string $route, string $method ): array {
		global $_test_registered_routes;
		foreach ( $_test_registered_routes as $registered ) {
			if ( 'arcadia/v1' !== $registered['namespace'] || $route !== $registered['route'] ) {
				continue;
			}
			foreach ( $registered['endpoints'] as $endpoint ) {
				if ( $method === $endpoint['methods'] ) {
					return $endpoint;
				}
			}
		}
		$this->fail( "{$method} {$route} is not registered." );
	}

	private function assertStillConnected(): void {
		global $_test_options;
		$this->assertSame( self::$keys['public'], $_test_options['arcadia_agents_public_key'] ?? null );
		$this->assertTrue( $_test_options['arcadia_agents_connected'] ?? false );
		$this->assertSame( self::SITE_ID, $_test_options['arcadia_agents_site_id'] ?? null );
		$this->assertSame( self::ISSUER, $_test_options['arcadia_agents_issuer'] ?? null );
	}

	private function assertRefused( $result, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertStillConnected();
	}

	// ---------------------------------------------------------------------
	// The owner's call
	// ---------------------------------------------------------------------

	public function test_a_token_of_this_connection_disconnects_the_site(): void {
		global $_test_options;
		$this->connect();

		$response = $this->dispatch( $this->request( $this->token() ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );

		foreach ( array(
			'arcadia_agents_public_key',
			'arcadia_agents_connected',
			'arcadia_agents_connected_at',
			'arcadia_agents_last_activity',
			'arcadia_agents_site_id',
			'arcadia_agents_issuer',
		) as $option ) {
			$this->assertArrayNotHasKey( $option, $_test_options, "{$option} survived the disconnect." );
		}
	}

	public function test_no_scope_is_required(): void {
		global $_test_options;
		$this->connect();
		// Every permission unticked in the admin.
		$_test_options['arcadia_agents_scopes'] = array();

		$response = $this->dispatch( $this->request( $this->token() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'arcadia_agents_public_key', $_test_options );
	}

	public function test_the_token_can_travel_in_the_fallback_header(): void {
		global $_test_options;
		$this->connect();
		$request = $this->request( null );
		$request->set_header( 'X-AA-Token', 'Bearer ' . $this->token() );

		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'arcadia_agents_public_key', $_test_options );
	}

	// ---------------------------------------------------------------------
	// Anything else leaves the site connected
	// ---------------------------------------------------------------------

	public function test_no_token_is_refused(): void {
		$this->connect();

		$this->assertRefused( $this->dispatch( $this->request( null ) ), 'missing_authorization' );
	}

	public function test_a_token_signed_by_another_key_is_refused(): void {
		$this->connect();

		$token = $this->token( array(), self::$foreign_keys['private'] );

		$this->assertRefused( $this->dispatch( $this->request( $token ) ), 'invalid_signature' );
	}

	public function test_a_token_for_another_site_is_refused(): void {
		$this->connect();

		$token = $this->token( array( 'sub' => 'site-other' ) );

		$this->assertRefused( $this->dispatch( $this->request( $token ) ), 'site_mismatch' );
	}

	public function test_a_token_from_another_issuer_is_refused(): void {
		$this->connect();

		$token = $this->token( array( 'iss' => 'someone-else' ) );

		$this->assertRefused( $this->dispatch( $this->request( $token ) ), 'invalid_issuer' );
	}

	public function test_an_expired_token_is_refused(): void {
		$this->connect();

		$token = $this->token(
			array(
				'iat' => time() - 3600,
				'exp' => time() - 600,
			)
		);

		$this->assertRefused( $this->dispatch( $this->request( $token ) ), 'token_expired' );
	}

	// ---------------------------------------------------------------------
	// Already disconnected: 200, and nothing touched
	// ---------------------------------------------------------------------

	public function test_already_disconnected_answers_200_without_a_token(): void {
		$response = $this->dispatch( $this->request( null ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
	}

	public function test_already_disconnected_answers_200_to_any_token(): void {
		$token = $this->token( array( 'sub' => 'whoever' ), self::$foreign_keys['private'] );

		$response = $this->dispatch( $this->request( $token ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The unauthenticated path must not be able to change anything — not even
	 * the connection key the owner may be about to submit in the admin.
	 */
	public function test_already_disconnected_touches_nothing(): void {
		global $_test_options;
		$_test_options['arcadia_agents_connection_key'] = 'pending-key';
		$_test_options['arcadia_agents_scopes']         = array( 'articles:read' );
		$before                                         = $_test_options;

		$this->dispatch( $this->request( null ) );

		$this->assertSame( $before, $_test_options );
	}

	public function test_a_second_call_is_harmless(): void {
		$this->connect();
		$token = $this->token();

		$this->assertSame( 200, $this->dispatch( $this->request( $token ) )->get_status() );
		$this->assertSame( 200, $this->dispatch( $this->request( $token ) )->get_status() );
	}

	// ---------------------------------------------------------------------
	// What "connected" means
	// ---------------------------------------------------------------------

	/**
	 * Keyed on the public key: without it no token can be validated, whatever
	 * the admin's display flag says.
	 */
	public function test_connected_means_a_public_key_is_stored(): void {
		global $_test_options;
		$auth = \Arcadia_Auth::get_instance();

		$this->assertFalse( $auth->is_connected() );

		$_test_options['arcadia_agents_connected'] = true;
		$this->assertFalse( $auth->is_connected() );

		$_test_options['arcadia_agents_public_key'] = self::$keys['public'];
		$this->assertTrue( $auth->is_connected() );
	}
}
