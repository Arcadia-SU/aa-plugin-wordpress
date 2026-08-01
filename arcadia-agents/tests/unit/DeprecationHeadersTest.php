<?php
/**
 * Test: deprecation signalling on superseded REST paths (Phase 40).
 *
 * Rules are keyed by *prefix*, not by an enumerated list of paths, so a tenth
 * `/contents` route cannot be added without its `/articles` twin being
 * deprecated too. The traps that come with prefix matching — segment
 * boundaries, a rule limited to one method, foreign namespaces — are each
 * pinned below.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-api-deprecations.php';

class DeprecationHeadersTest extends TestCase {

	private function dispatch( string $route, string $method = 'GET' ): \WP_REST_Response {
		$request = new \WP_REST_Request();
		$request->set_route( $route );
		$request->set_method( $method );

		return \Arcadia_API_Deprecations::add_headers( new \WP_REST_Response( array( 'ok' => true ) ), null, $request );
	}

	// ---------------------------------------------------------------------
	// Deprecated paths get stamped
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider deprecated_routes
	 */
	public function test_deprecated_route_carries_all_three_headers( string $route, string $method ): void {
		$headers = $this->dispatch( $route, $method )->get_headers();

		$this->assertArrayHasKey( 'Deprecation', $headers, "{$method} {$route} was not marked deprecated." );
		$this->assertArrayHasKey( 'Sunset', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
	}

	public static function deprecated_routes(): array {
		return array(
			'articles listing'   => array( '/arcadia/v1/articles', 'GET' ),
			'articles create'    => array( '/arcadia/v1/articles', 'POST' ),
			'articles update'    => array( '/arcadia/v1/articles/12', 'PUT' ),
			'articles delete'    => array( '/arcadia/v1/articles/12', 'DELETE' ),
			'articles blocks'    => array( '/arcadia/v1/articles/12/blocks', 'GET' ),
			'articles revisions' => array( '/arcadia/v1/articles/12/revisions', 'GET' ),
			'pages update'       => array( '/arcadia/v1/pages/12', 'PUT' ),
		);
	}

	// ---------------------------------------------------------------------
	// …and nothing else does
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider untouched_routes
	 */
	public function test_route_is_not_marked_deprecated( string $route, string $method, string $why ): void {
		$headers = $this->dispatch( $route, $method )->get_headers();

		$this->assertArrayNotHasKey( 'Deprecation', $headers, $why );
		$this->assertArrayNotHasKey( 'Sunset', $headers, $why );
		$this->assertArrayNotHasKey( 'Link', $headers, $why );
	}

	public static function untouched_routes(): array {
		return array(
			'canonical contents'      => array( '/arcadia/v1/contents', 'GET', 'The canonical path must never be marked deprecated.' ),
			'canonical contents item' => array( '/arcadia/v1/contents/12', 'PUT', 'The canonical path must never be marked deprecated.' ),
			'GET /pages'              => array( '/arcadia/v1/pages', 'GET', 'GET /pages feeds AA internal linking and has no replacement.' ),
			'GET /pages/{id}'         => array( '/arcadia/v1/pages/12', 'GET', 'The /pages rule is limited to PUT.' ),
			'DELETE /pages/{id}'      => array( '/arcadia/v1/pages/12', 'DELETE', 'The /pages rule is limited to PUT.' ),
			// A bare strpos() would deprecate this — it is a different resource.
			'segment boundary'        => array( '/arcadia/v1/articles-archive', 'GET', 'Prefix matching must stop at a segment boundary.' ),
			'boundary on pages'       => array( '/arcadia/v1/pages-sitemap', 'PUT', 'Prefix matching must stop at a segment boundary.' ),
			'foreign namespace'       => array( '/wp/v2/posts', 'GET', 'Never stamp headers on another namespace.' ),
			'foreign lookalike'       => array( '/other/v1/articles', 'GET', 'Never stamp headers on another namespace.' ),
			// Same byte length as '/arcadia/v1', so a namespace check that
			// blindly substr()s past 11 characters would land on '/articles'
			// and stamp another plugin's surface. The two cases above cannot
			// catch that — they decay to harmless garbage when stripped.
			'foreign same-length ns'  => array( '/foobar/v11/articles', 'GET', 'A foreign namespace of the same length must not be stripped blindly.' ),
			'foreign same-length put' => array( '/foobar/v11/pages/12', 'PUT', 'A foreign namespace of the same length must not be stripped blindly.' ),
			'plugin root'             => array( '/arcadia/v1', 'GET', 'The namespace root is not a deprecated path.' ),
			'unrelated plugin route'  => array( '/arcadia/v1/media', 'GET', 'Only the listed prefixes are deprecated.' ),
			'health'                  => array( '/arcadia/v1/health', 'GET', 'Only the listed prefixes are deprecated.' ),
		);
	}

	// ---------------------------------------------------------------------
	// Header formats
	// ---------------------------------------------------------------------

	/**
	 * RFC 9745: an IMF-fixdate. The `true` form of the earlier draft was
	 * dropped from the final RFC.
	 */
	public function test_deprecation_is_an_imf_fixdate(): void {
		$headers = $this->dispatch( '/arcadia/v1/articles', 'GET' )->get_headers();

		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
			$headers['Deprecation']
		);
		$this->assertNotSame( 'true', $headers['Deprecation'] );
	}

	public function test_sunset_is_an_imf_fixdate_in_the_future(): void {
		$headers = $this->dispatch( '/arcadia/v1/articles', 'GET' )->get_headers();

		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
			$headers['Sunset']
		);
		$this->assertGreaterThan(
			strtotime( '2026-08-01' ),
			strtotime( $headers['Sunset'] ),
			'Sunset must leave clients at least a full release cycle.'
		);
	}

	/**
	 * @dataProvider successor_cases
	 */
	public function test_link_points_at_the_successor( string $route, string $method, string $expected_suffix ): void {
		$headers = $this->dispatch( $route, $method )->get_headers();

		$this->assertStringContainsString( 'rel="successor-version"', $headers['Link'] );
		$this->assertStringContainsString( $expected_suffix, $headers['Link'] );
		$this->assertStringNotContainsString( '/articles', $headers['Link'] );
	}

	public static function successor_cases(): array {
		return array(
			'listing'   => array( '/arcadia/v1/articles', 'GET', 'arcadia/v1/contents' ),
			'item'      => array( '/arcadia/v1/articles/12', 'PUT', 'arcadia/v1/contents/12' ),
			'blocks'    => array( '/arcadia/v1/articles/12/blocks', 'GET', 'arcadia/v1/contents/12/blocks' ),
			'revisions' => array( '/arcadia/v1/articles/12/revisions/7', 'GET', 'arcadia/v1/contents/12/revisions/7' ),
			'page put'  => array( '/arcadia/v1/pages/12', 'PUT', 'arcadia/v1/contents/12' ),
		);
	}

	// ---------------------------------------------------------------------
	// Body untouched
	// ---------------------------------------------------------------------

	/**
	 * Payloads must stay byte-for-byte identical between twins — that is what
	 * makes the parity assertion mean anything.
	 */
	public function test_response_body_and_status_are_untouched(): void {
		$request = new \WP_REST_Request();
		$request->set_route( '/arcadia/v1/articles/12' );
		$request->set_method( 'PUT' );

		$original = new \WP_REST_Response( array( 'success' => true, 'post' => array( 'id' => 12 ) ), 200 );
		$result   = \Arcadia_API_Deprecations::add_headers( $original, null, $request );

		$this->assertSame( $original, $result );
		$this->assertSame( array( 'success' => true, 'post' => array( 'id' => 12 ) ), $result->get_data() );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * permission_callback short-circuits before any callback wrapper would
	 * run. A client refused for a missing scope is exactly the one that needs
	 * to hear the path is going away, so the filter must still stamp it.
	 */
	public function test_error_responses_are_still_marked(): void {
		$request = new \WP_REST_Request();
		$request->set_route( '/arcadia/v1/articles/12' );
		$request->set_method( 'PUT' );

		$response = \Arcadia_API_Deprecations::add_headers(
			new \WP_REST_Response( array( 'code' => 'insufficient_scope' ), 403 ),
			null,
			$request
		);

		$this->assertArrayHasKey( 'Deprecation', $response->get_headers() );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_existing_link_header_is_appended_to_not_replaced(): void {
		$request = new \WP_REST_Request();
		$request->set_route( '/arcadia/v1/articles' );
		$request->set_method( 'GET' );

		$response = new \WP_REST_Response( array(), 200 );
		$response->header( 'Link', '<http://localhost/page/2>; rel="next"' );

		\Arcadia_API_Deprecations::add_headers( $response, null, $request );

		$link = $response->get_headers()['Link'];
		$this->assertStringContainsString( 'rel="next"', $link );
		$this->assertStringContainsString( 'rel="successor-version"', $link );
	}

	// ---------------------------------------------------------------------
	// Robustness
	// ---------------------------------------------------------------------

	public function test_a_response_without_a_header_method_is_returned_unchanged(): void {
		$request = new \WP_REST_Request();
		$request->set_route( '/arcadia/v1/articles' );

		$plain = new \stdClass();

		$this->assertSame( $plain, \Arcadia_API_Deprecations::add_headers( $plain, null, $request ) );
	}

	public function test_a_routeless_request_is_ignored(): void {
		$response = \Arcadia_API_Deprecations::add_headers(
			new \WP_REST_Response( array(), 200 ),
			null,
			new \WP_REST_Request()
		);

		$this->assertSame( array(), $response->get_headers() );
	}
}
