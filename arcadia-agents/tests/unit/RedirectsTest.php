<?php
/**
 * Tests for redirects CRUD operations and cache management.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required classes and traits.
require_once dirname( __DIR__, 2 ) . '/includes/class-redirects.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-redirects.php';

/**
 * Minimal class exposing redirects trait methods.
 */
class RedirectsCrudHelper {
    use \Arcadia_API_Formatters;
    use \Arcadia_API_Redirects_Handler;
}

/**
 * Test class for redirects CRUD and cache.
 */
class RedirectsTest extends TestCase {

    /**
     * @var RedirectsCrudHelper
     */
    private $helper;

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $_test_posts, $_test_post_meta, $_test_options, $_test_next_post_id;
        $_test_posts        = array();
        $_test_post_meta    = array();
        $_test_options      = array();
        $_test_next_post_id = 1000;
        \WP_Query::reset();
        $this->helper = new RedirectsCrudHelper();
    }

    /**
     * Test create_redirect returns 400 when source is missing.
     */
    public function test_create_redirect_missing_source(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params( array( 'target' => 'https://example.com/new' ) );

        $result = $this->helper->create_redirect( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_source', $result->get_error_code() );
    }

    /**
     * Test create_redirect returns 400 when target is missing.
     */
    public function test_create_redirect_missing_target(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params( array( 'source' => '/old-page' ) );

        $result = $this->helper->create_redirect( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_target', $result->get_error_code() );
    }

    /**
     * Test create_redirect returns 400 for invalid type.
     */
    public function test_create_redirect_invalid_type(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params(
            array(
                'source' => '/old-page',
                'target' => 'https://example.com/new',
                'type'   => 307,
            )
        );

        $result = $this->helper->create_redirect( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'invalid_type', $result->get_error_code() );
    }

    /**
     * Test create_redirect success with 301.
     */
    public function test_create_redirect_success(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params(
            array(
                'source' => '/old-page',
                'target' => 'https://example.com/new-page',
                'type'   => 301,
            )
        );

        $result = $this->helper->create_redirect( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $this->assertEquals( 201, $result->get_status() );

        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertArrayHasKey( 'redirect', $data );
        $this->assertEquals( '/old-page', $data['redirect']['source'] );
        $this->assertEquals( 'https://example.com/new-page', $data['redirect']['target'] );
        $this->assertEquals( 301, $data['redirect']['type'] );
    }

    /**
     * Test create_redirect defaults to 301 when type is omitted.
     */
    public function test_create_redirect_default_type_301(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params(
            array(
                'source' => '/old',
                'target' => 'https://example.com/new',
            )
        );

        $result = $this->helper->create_redirect( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertEquals( 301, $data['redirect']['type'] );
    }

    /**
     * Test delete_redirect returns 404 for missing redirect.
     */
    public function test_delete_redirect_not_found(): void {
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );

        $result = $this->helper->delete_redirect( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'redirect_not_found', $result->get_error_code() );
    }

    /**
     * Test delete_redirect returns 404 for wrong post type.
     */
    public function test_delete_redirect_wrong_post_type(): void {
        global $_test_posts;
        $_test_posts[100] = (object) array(
            'ID'        => 100,
            'post_type' => 'post',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '100' );

        $result = $this->helper->delete_redirect( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'redirect_not_found', $result->get_error_code() );
    }

    /**
     * Test delete_redirect success.
     */
    public function test_delete_redirect_success(): void {
        global $_test_posts;
        $_test_posts[200] = (object) array(
            'ID'        => 200,
            'post_type' => 'arcadia_redirect',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '200' );

        $result = $this->helper->delete_redirect( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertEquals( 200, $data['deleted'] );
    }

    /**
     * Test get_redirects returns empty list.
     */
    public function test_get_redirects_empty(): void {
        \WP_Query::set_next_result( array() );

        $request = new \WP_REST_Request();
        $result  = $this->helper->get_redirects( $request );

        $data = $result->get_data();
        $this->assertEmpty( $data['redirects'] );
        $this->assertEquals( 0, $data['total'] );
    }

    /**
     * Test get_redirect_map builds map from posts and caches.
     */
    public function test_get_redirect_map_builds_cache(): void {
        global $_test_post_meta;

        \WP_Query::set_next_result(
            array(
                (object) array(
                    'ID'         => 100,
                    'post_title' => '/old-page',
                    'post_date'  => '2025-01-01 00:00:00',
                ),
            )
        );
        $_test_post_meta[100] = array(
            '_redirect_target' => 'https://example.com/new-page',
            '_redirect_type'   => '301',
        );

        $redirects = \Arcadia_Redirects::get_instance();
        $redirects->invalidate_cache();
        $map = $redirects->get_redirect_map();

        $this->assertArrayHasKey( '/old-page', $map );
        $this->assertEquals( 'https://example.com/new-page', $map['/old-page']['target'] );
        $this->assertEquals( 301, $map['/old-page']['type'] );
        $this->assertEquals( 100, $map['/old-page']['id'] );
    }

    /**
     * Test cache invalidation forces rebuild.
     */
    public function test_cache_invalidation(): void {
        global $_test_options;

        // Pre-fill the transient cache.
        $_test_options['_transient_arcadia_redirects_map'] = array(
            '/cached' => array(
                'target' => '/cached-target',
                'type'   => 301,
                'id'     => 1,
            ),
        );

        $redirects = \Arcadia_Redirects::get_instance();

        // Should return cached data.
        $map = $redirects->get_redirect_map();
        $this->assertArrayHasKey( '/cached', $map );

        // Invalidate cache.
        $redirects->invalidate_cache();

        // Now should rebuild from WP_Query (empty).
        \WP_Query::set_next_result( array() );
        $map = $redirects->get_redirect_map();
        $this->assertEmpty( $map );
    }
}
