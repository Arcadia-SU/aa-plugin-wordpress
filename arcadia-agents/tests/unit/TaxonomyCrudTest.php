<?php
/**
 * Tests for taxonomy CRUD operations.
 *
 * Tests create_tag(), update_category(), update_tag()
 * and later delete_category(), delete_tag().
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';

/**
 * Minimal class exposing taxonomy trait methods.
 */
class TaxonomyCrudHelper {
    use \Arcadia_API_Formatters;
    use \Arcadia_API_Taxonomies_Handler;
}

/**
 * Test class for taxonomy CRUD.
 */
class TaxonomyCrudTest extends TestCase {

    /**
     * @var TaxonomyCrudHelper
     */
    private $helper;

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $_test_terms;
        $_test_terms    = array();
        $this->helper   = new TaxonomyCrudHelper();
    }

    /**
     * Test create_tag returns 400 when name is missing.
     */
    public function test_create_tag_missing_name(): void {
        $request = new \WP_REST_Request();
        $request->set_json_params( array() );

        $result = $this->helper->create_tag( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'missing_name', $result->get_error_code() );
    }

    /**
     * Test create_tag returns 201 on success.
     */
    public function test_create_tag_success(): void {
        global $_test_terms;
        $_test_terms['post_tag:99'] = (object) array(
            'term_id'     => 99,
            'name'        => 'SEO',
            'slug'        => 'seo',
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        );

        $request = new \WP_REST_Request();
        $request->set_json_params( array( 'name' => 'SEO' ) );

        $result = $this->helper->create_tag( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $this->assertEquals( 201, $result->get_status() );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertEquals( 99, $data['tag_id'] );
        $this->assertEquals( 'SEO', $data['tag']['name'] );
    }

    /**
     * Test update_category returns 404 for missing term.
     */
    public function test_update_category_not_found(): void {
        // No terms in $_test_terms → get_term returns null.
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );
        $request->set_json_params( array( 'name' => 'New Name' ) );

        $result = $this->helper->update_category( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'term_not_found', $result->get_error_code() );
    }

    /**
     * Test update_category with valid data.
     */
    public function test_update_category_success(): void {
        global $_test_terms;
        $_test_terms['category:5'] = (object) array(
            'term_id'     => 5,
            'name'        => 'Old Name',
            'slug'        => 'old-name',
            'description' => '',
            'parent'      => 0,
            'count'       => 10,
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '5' );
        $request->set_json_params( array( 'name' => 'New Name', 'description' => 'Updated description' ) );

        $result = $this->helper->update_category( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertArrayHasKey( 'category', $data );
    }

    /**
     * Test update_category returns 400 when no fields provided.
     */
    public function test_update_category_nothing_to_update(): void {
        global $_test_terms;
        $_test_terms['category:5'] = (object) array(
            'term_id'     => 5,
            'name'        => 'Name',
            'slug'        => 'name',
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '5' );
        $request->set_json_params( array() );

        $result = $this->helper->update_category( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'nothing_to_update', $result->get_error_code() );
    }

    /**
     * Test update_tag returns 404 for missing term.
     */
    public function test_update_tag_not_found(): void {
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );
        $request->set_json_params( array( 'name' => 'New Name' ) );

        $result = $this->helper->update_tag( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'term_not_found', $result->get_error_code() );
    }

    /**
     * Test update_tag with valid data.
     */
    public function test_update_tag_success(): void {
        global $_test_terms;
        $_test_terms['post_tag:8'] = (object) array(
            'term_id'     => 8,
            'name'        => 'Old Tag',
            'slug'        => 'old-tag',
            'description' => '',
            'parent'      => 0,
            'count'       => 5,
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '8' );
        $request->set_json_params( array( 'name' => 'New Tag' ) );

        $result = $this->helper->update_tag( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertArrayHasKey( 'tag', $data );
    }
}
