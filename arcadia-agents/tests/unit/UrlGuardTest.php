<?php
/**
 * Tests for Arcadia_Url_Guard (SSRF protection on remote URLs).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-url-guard.php';

/**
 * Test class for the remote URL guard.
 */
class UrlGuardTest extends TestCase {

	/**
	 * Reset the configurable wp_http_validate_url() result between tests.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_test_http_validate_url'] );
	}

	/**
	 * HTTPS and HTTP URLs to public hosts are accepted.
	 */
	public function test_accepts_public_http_and_https(): void {
		$this->assertTrue( \Arcadia_Url_Guard::validate_remote_url( 'https://example.com/a.jpg' ) );
		$this->assertTrue( \Arcadia_Url_Guard::validate_remote_url( 'http://example.com/a.jpg' ) );
	}

	/**
	 * Non-HTTP(S) schemes are rejected (file://, ftp://, data:, gopher://).
	 */
	public function test_rejects_non_http_schemes(): void {
		foreach ( array( 'file:///etc/passwd', 'ftp://example.com/x', 'gopher://example.com' ) as $url ) {
			$result = \Arcadia_Url_Guard::validate_remote_url( $url );
			$this->assertInstanceOf( \WP_Error::class, $result, "Expected rejection for $url" );
			$this->assertSame( 'invalid_url_scheme', $result->get_error_code() );
		}
	}

	/**
	 * A URL with no scheme is rejected.
	 */
	public function test_rejects_schemeless_url(): void {
		$result = \Arcadia_Url_Guard::validate_remote_url( 'example.com/a.jpg' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url_scheme', $result->get_error_code() );
	}

	/**
	 * A private/reserved host (wp_http_validate_url() returns false) is rejected
	 * as SSRF even though the scheme is valid.
	 */
	public function test_rejects_private_host(): void {
		$GLOBALS['_test_http_validate_url'] = false;

		$result = \Arcadia_Url_Guard::validate_remote_url( 'http://127.0.0.1:22/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}
}
