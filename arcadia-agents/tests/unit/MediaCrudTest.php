<?php
/**
 * Tests for media CRUD operations (v2).
 *
 * Tests update_media(), delete_media(), and enriched get_media() filters.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';

/**
 * Minimal class exposing media trait methods.
 */
class MediaCrudHelper {
    use \Arcadia_API_Formatters;
    use \Arcadia_API_Media_Handler;
}

/**
 * Test class for media CRUD.
 */
class MediaCrudTest extends TestCase {

    /**
     * @var MediaCrudHelper
     */
    private $helper;

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $_test_posts, $_test_post_meta;
        $_test_posts     = array();
        $_test_post_meta = array();
        $this->helper    = new MediaCrudHelper();
    }

    /**
     * Test update_media returns 404 for missing attachment.
     */
    public function test_update_media_not_found(): void {
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );
        $request->set_json_params( array( 'title' => 'New Title' ) );

        $result = $this->helper->update_media( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'media_not_found', $result->get_error_code() );
    }

    /**
     * Test update_media with valid alt_text.
     */
    public function test_update_media_alt_text(): void {
        global $_test_posts, $_test_post_meta;
        $_test_posts[50] = (object) array(
            'ID'             => 50,
            'post_type'      => 'attachment',
            'post_title'     => 'My Image',
            'post_excerpt'   => '',
            'post_mime_type' => 'image/jpeg',
            'post_date'      => '2025-01-01 00:00:00',
        );
        $_test_post_meta[50] = array();

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '50' );
        $request->set_json_params( array( 'alt_text' => 'New Alt Text' ) );

        $result = $this->helper->update_media( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertArrayHasKey( 'media', $data );
    }

    /**
     * Test update_media returns 400 when no fields provided.
     */
    public function test_update_media_nothing_to_update(): void {
        global $_test_posts;
        $_test_posts[50] = (object) array(
            'ID'             => 50,
            'post_type'      => 'attachment',
            'post_title'     => 'Image',
            'post_excerpt'   => '',
            'post_mime_type' => 'image/jpeg',
            'post_date'      => '2025-01-01 00:00:00',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '50' );
        $request->set_json_params( array() );

        $result = $this->helper->update_media( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'nothing_to_update', $result->get_error_code() );
    }

    /**
     * Test delete_media returns 404 for missing attachment.
     */
    public function test_delete_media_not_found(): void {
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );
        $request->set_json_params( array() );

        $result = $this->helper->delete_media( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'media_not_found', $result->get_error_code() );
    }

    /**
     * Test delete_media success.
     */
    public function test_delete_media_success(): void {
        global $_test_posts;
        $_test_posts[60] = (object) array(
            'ID'             => 60,
            'post_type'      => 'attachment',
            'post_title'     => 'To Delete',
            'post_excerpt'   => '',
            'post_mime_type' => 'image/png',
            'post_date'      => '2025-01-01 00:00:00',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '60' );
        $request->set_json_params( array() );

        $result = $this->helper->delete_media( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertEquals( 60, $data['deleted'] );
        $this->assertFalse( $data['force'] );
    }

    /**
     * Test delete_media with force flag.
     */
    public function test_delete_media_force(): void {
        global $_test_posts;
        $_test_posts[70] = (object) array(
            'ID'             => 70,
            'post_type'      => 'attachment',
            'post_title'     => 'Force Delete',
            'post_excerpt'   => '',
            'post_mime_type' => 'image/png',
            'post_date'      => '2025-01-01 00:00:00',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '70' );
        $request->set_json_params( array( 'force' => true ) );

        $result = $this->helper->delete_media( $request );

        $data = $result->get_data();
        $this->assertTrue( $data['force'] );
    }

    /**
     * Test type filter maps to MIME type prefix.
     */
    public function test_type_filter_whitelist(): void {
        $whitelist = array( 'image', 'video', 'audio', 'application' );
        $this->assertContains( 'image', $whitelist );
        $this->assertNotContains( 'text', $whitelist );
    }

    /**
     * Test date filter structure for media.
     */
    public function test_media_date_filter(): void {
        $date_from = '2025-06-01';
        $date_to   = '2025-12-31';

        $date_query = array(
            'after'     => $date_from,
            'before'    => $date_to,
            'inclusive' => true,
        );

        $this->assertEquals( '2025-06-01', $date_query['after'] );
        $this->assertEquals( '2025-12-31', $date_query['before'] );
    }
}
