<?php
/**
 * Test: /contents and /articles are the same surface (Phase 40).
 *
 * AA renamed the concept to EditorialContent (prod 2026-07-02); the REST
 * surface follows. `/contents` is canonical, `/articles` is a deprecated
 * alias kept for a grace period.
 *
 * Every assertion here derives from what was **actually registered**, never
 * from the definition table. Iterating the table would only prove that the
 * loop loops; iterating the registrations catches a register_rest_route()
 * written by hand outside the loop six months from now.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-api.php';

/**
 * Records the scope each permission_callback asks for.
 */
class ParityAuthStub {
	/** @var string|null */
	public $last_scope = null;

	public function authenticate_request( $request, $required_scope = null ) {
		$this->last_scope = $required_scope;
		return true;
	}
}

class ContentRouteParityTest extends TestCase {

	/** @var \Arcadia_API */
	private $api;

	/** @var ParityAuthStub */
	private $auth;

	/** @var array<int, array> */
	private $routes;

	protected function setUp(): void {
		global $_test_registered_routes;
		$_test_registered_routes = array();

		$ref       = new \ReflectionClass( \Arcadia_API::class );
		$this->api = $ref->newInstanceWithoutConstructor();

		$this->auth = new ParityAuthStub();
		$prop       = $ref->getProperty( 'auth' );
		$prop->setAccessible( true );
		$prop->setValue( $this->api, $this->auth );

		$this->api->register_routes();

		$this->routes = $_test_registered_routes;
	}

	protected function tearDown(): void {
		global $_test_registered_routes;
		$_test_registered_routes = array();
	}

	// ---------------------------------------------------------------------
	// Helpers — all derived from what was registered
	// ---------------------------------------------------------------------

	/**
	 * Route paths registered under a prefix, with the prefix stripped.
	 *
	 * @return array<string, array> suffix => endpoints
	 */
	private function suffixes_under( string $prefix ): array {
		$found = array();

		foreach ( $this->routes as $route ) {
			$path = $route['route'];

			if ( $path !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {
				continue;
			}

			$found[ substr( $path, strlen( $prefix ) ) ] = $route['endpoints'];
		}

		return $found;
	}

	private function endpoints_for( string $path ): ?array {
		foreach ( $this->routes as $route ) {
			if ( $route['route'] === $path ) {
				return $route['endpoints'];
			}
		}

		return null;
	}

	/**
	 * Invoke a permission_callback and report the scope it asked for.
	 */
	private function scope_of( array $endpoint ): ?string {
		$this->auth->last_scope = null;
		call_user_func( $endpoint['permission_callback'], new \WP_REST_Request() );
		return $this->auth->last_scope;
	}

	private function callback_name( array $endpoint ): string {
		return is_array( $endpoint['callback'] ) ? $endpoint['callback'][1] : '(closure)';
	}

	// ---------------------------------------------------------------------
	// 1. Non-vacuity — everything below is worthless if nothing registered
	// ---------------------------------------------------------------------

	public function test_both_prefixes_actually_registered_routes(): void {
		$this->assertNotEmpty( $this->suffixes_under( '/contents' ) );
		$this->assertNotEmpty( $this->suffixes_under( '/articles' ) );
		$this->assertGreaterThanOrEqual( 7, count( $this->suffixes_under( '/contents' ) ) );
	}

	// ---------------------------------------------------------------------
	// 2. Bijection, in both directions
	// ---------------------------------------------------------------------

	/**
	 * The reverse direction is the line to delete at sunset — until then, an
	 * `/articles` route with no `/contents` twin is just as much a bug as the
	 * other way round.
	 */
	public function test_prefixes_expose_the_same_paths(): void {
		$contents = array_keys( $this->suffixes_under( '/contents' ) );
		$articles = array_keys( $this->suffixes_under( '/articles' ) );

		sort( $contents );
		sort( $articles );

		$this->assertSame( $contents, $articles );
	}

	/**
	 * The revision routes lived in their own registration group. Aliasing only
	 * the article group would have left them behind with no error at all.
	 */
	public function test_revision_routes_are_aliased_too(): void {
		$suffixes = array_keys( $this->suffixes_under( '/contents' ) );

		$this->assertContains( '/(?P<id>\d+)/revisions', $suffixes );
		$this->assertContains( '/(?P<id>\d+)/revisions/(?P<revision_id>\d+)', $suffixes );
	}

	// ---------------------------------------------------------------------
	// 3. Identity, not equality
	// ---------------------------------------------------------------------

	/**
	 * assertSame on the endpoint arrays: the twins hold the *same* Closure
	 * instance, because build_endpoints() runs once per route and its result
	 * is mounted under both prefixes. Two literals that merely happen to match
	 * today would pass an equality check and drift tomorrow.
	 */
	public function test_twin_routes_share_the_same_endpoint_instances(): void {
		$contents = $this->suffixes_under( '/contents' );
		$articles = $this->suffixes_under( '/articles' );

		foreach ( $contents as $suffix => $endpoints ) {
			$this->assertArrayHasKey( $suffix, $articles );
			$this->assertSame(
				$endpoints,
				$articles[ $suffix ],
				"Endpoints for '{$suffix}' are not the same instance under both prefixes."
			);
		}
	}

	// ---------------------------------------------------------------------
	// 4. Scopes, behaviourally, in both directions
	// ---------------------------------------------------------------------

	/**
	 * Expected scope per method per path suffix.
	 *
	 * Compared in both directions, so a tenth route cannot be added without
	 * someone typing its scope here.
	 */
	private static function expected_scopes(): array {
		return array(
			''                                            => array(
				'GET'  => 'articles:read',
				'POST' => 'articles:write',
			),
			'/(?P<id>\d+)'                                => array(
				'PUT'    => 'articles:write',
				'DELETE' => 'articles:delete',
			),
			'/(?P<id>\d+)/blocks'                         => array( 'GET' => 'articles:read' ),
			'/(?P<id>\d+)/preview-url'                    => array( 'GET' => 'articles:read' ),
			// The trap: this one is media:write, not articles:write. A loop
			// that hard-coded a single scope would widen access in silence.
			'/(?P<id>\d+)/featured-image'                 => array( 'PUT' => 'media:write' ),
			'/(?P<id>\d+)/revisions'                      => array( 'GET' => 'articles:read' ),
			'/(?P<id>\d+)/revisions/(?P<revision_id>\d+)' => array( 'GET' => 'articles:read' ),
			// The second deliberate break in the articles:* pattern. Withdrawing a
			// pending revision destroys a decision a human was about to make; that
			// is not the same power as writing content, so it gets its own scope
			// and arrives disabled on any site that has saved its settings.
			'/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/reject' => array( 'POST' => 'revisions:write' ),
		);
	}

	/**
	 * @dataProvider content_prefixes
	 */
	public function test_scopes_match_expectations( string $prefix ): void {
		$expected = self::expected_scopes();
		$actual   = array();

		foreach ( $this->suffixes_under( $prefix ) as $suffix => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$actual[ $suffix ][ $endpoint['methods'] ] = $this->scope_of( $endpoint );
			}
		}

		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual, "Scope table drifted under '{$prefix}'." );
	}

	public static function content_prefixes(): array {
		return array(
			'canonical'  => array( '/contents' ),
			'deprecated' => array( '/articles' ),
		);
	}

	public function test_featured_image_keeps_the_media_scope(): void {
		foreach ( self::content_prefixes() as $case ) {
			$endpoints = $this->endpoints_for( $case[0] . '/(?P<id>\d+)/featured-image' );

			$this->assertNotNull( $endpoints );
			$this->assertSame( 'media:write', $this->scope_of( $endpoints[0] ) );
		}
	}

	// ---------------------------------------------------------------------
	// 5. The /pages surface
	// ---------------------------------------------------------------------

	/**
	 * PUT /pages/{id} now runs the shared handler — no bespoke update_page().
	 */
	public function test_pages_put_delegates_to_update_post(): void {
		$endpoints = $this->endpoints_for( '/pages/(?P<id>\d+)' );

		$this->assertNotNull( $endpoints );
		$this->assertCount( 1, $endpoints );
		$this->assertSame( 'PUT', $endpoints[0]['methods'] );
		$this->assertSame( 'update_post', $this->callback_name( $endpoints[0] ) );
		$this->assertSame( 'articles:write', $this->scope_of( $endpoints[0] ) );
	}

	/**
	 * Guard against "we deprecated the whole /pages group": GET /pages feeds
	 * AA's internal-linking map and has no replacement.
	 */
	public function test_get_pages_is_untouched(): void {
		$endpoints = $this->endpoints_for( '/pages' );

		$this->assertNotNull( $endpoints );
		$this->assertSame( 'GET', $endpoints[0]['methods'] );
		$this->assertSame( 'get_pages', $this->callback_name( $endpoints[0] ) );
		$this->assertSame( 'site:read', $this->scope_of( $endpoints[0] ) );
	}

	// ---------------------------------------------------------------------
	// 6. WordPress merges duplicate registrations without a word
	// ---------------------------------------------------------------------

	public function test_no_route_string_is_registered_twice(): void {
		$paths = array_column( $this->routes, 'route' );

		$duplicates = array_keys( array_filter( array_count_values( $paths ), fn( $n ) => $n > 1 ) );

		$this->assertSame(
			array(),
			$duplicates,
			'WordPress silently merges duplicate route registrations: ' . implode( ', ', $duplicates )
		);
	}

	// ---------------------------------------------------------------------
	// 7. Namespace
	// ---------------------------------------------------------------------

	public function test_every_route_is_in_the_plugin_namespace(): void {
		foreach ( $this->routes as $route ) {
			$this->assertSame( 'arcadia/v1', $route['namespace'], "Route {$route['route']} escaped the namespace." );
		}
	}

	public function test_every_endpoint_has_a_permission_callback(): void {
		foreach ( $this->routes as $route ) {
			foreach ( $route['endpoints'] as $endpoint ) {
				$this->assertArrayHasKey( 'permission_callback', $endpoint, "Route {$route['route']} has an unguarded endpoint." );
				$this->assertIsCallable( $endpoint['permission_callback'] );
			}
		}
	}
}
