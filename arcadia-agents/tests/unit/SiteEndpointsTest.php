<?php
/**
 * Tests for site structure endpoints (GET /menus, GET /users).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-site.php';

/**
 * Minimal class exposing site trait methods.
 */
class SiteEndpointsHelper {
    use \Arcadia_API_Formatters;
    use \Arcadia_API_Site_Handler;
}

/**
 * Test class for site structure endpoints.
 */
class SiteEndpointsTest extends TestCase {

    /**
     * @var SiteEndpointsHelper
     */
    private $helper;

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $_test_nav_menus, $_test_nav_menu_items, $_test_wp_users;
        $_test_nav_menus      = array();
        $_test_nav_menu_items = array();
        $_test_wp_users       = array();
        $this->helper         = new SiteEndpointsHelper();
    }

    /**
     * Test get_menus returns empty when no menus.
     */
    public function test_get_menus_empty(): void {
        $request = new \WP_REST_Request();
        $result  = $this->helper->get_menus( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertEmpty( $data['menus'] );
        $this->assertEquals( 0, $data['total'] );
    }

    /**
     * Test get_menus returns menu with items.
     */
    public function test_get_menus_with_items(): void {
        global $_test_nav_menus, $_test_nav_menu_items;

        $_test_nav_menus = array(
            (object) array( 'term_id' => 1, 'name' => 'Main Menu', 'slug' => 'main-menu' ),
        );

        $_test_nav_menu_items[1] = array(
            (object) array(
                'ID'               => 10,
                'title'            => 'Home',
                'url'              => 'https://example.com/',
                'type'             => 'custom',
                'object'           => 'custom',
                'menu_item_parent' => 0,
            ),
            (object) array(
                'ID'               => 11,
                'title'            => 'About',
                'url'              => 'https://example.com/about',
                'type'             => 'post_type',
                'object'           => 'page',
                'menu_item_parent' => 0,
            ),
        );

        $request = new \WP_REST_Request();
        $result  = $this->helper->get_menus( $request );

        $data = $result->get_data();
        $this->assertCount( 1, $data['menus'] );
        $this->assertEquals( 'Main Menu', $data['menus'][0]['name'] );
        $this->assertCount( 2, $data['menus'][0]['items'] );
        $this->assertEquals( 'Home', $data['menus'][0]['items'][0]['title'] );
    }

    /**
     * Test hierarchical menu tree building.
     */
    public function test_get_menus_hierarchy(): void {
        global $_test_nav_menus, $_test_nav_menu_items;

        $_test_nav_menus = array(
            (object) array( 'term_id' => 2, 'name' => 'Footer', 'slug' => 'footer' ),
        );

        $_test_nav_menu_items[2] = array(
            (object) array(
                'ID'               => 20,
                'title'            => 'Services',
                'url'              => '/services',
                'type'             => 'custom',
                'object'           => 'custom',
                'menu_item_parent' => 0,
            ),
            (object) array(
                'ID'               => 21,
                'title'            => 'SEO',
                'url'              => '/services/seo',
                'type'             => 'custom',
                'object'           => 'custom',
                'menu_item_parent' => 20,
            ),
        );

        $request = new \WP_REST_Request();
        $result  = $this->helper->get_menus( $request );

        $data = $result->get_data();
        $menu = $data['menus'][0];

        // Top-level should have 1 item.
        $this->assertCount( 1, $menu['items'] );
        $this->assertEquals( 'Services', $menu['items'][0]['title'] );

        // Services should have 1 child.
        $this->assertCount( 1, $menu['items'][0]['children'] );
        $this->assertEquals( 'SEO', $menu['items'][0]['children'][0]['title'] );
    }

    /**
     * Test get_users_list returns empty when no users.
     */
    public function test_get_users_empty(): void {
        $request = new \WP_REST_Request();
        $result  = $this->helper->get_users_list( $request );

        $data = $result->get_data();
        $this->assertEmpty( $data['users'] );
        $this->assertEquals( 0, $data['total'] );
    }

    /**
     * Test get_users_list returns formatted users.
     */
    public function test_get_users_list_with_users(): void {
        global $_test_wp_users;

        $_test_wp_users = array(
            (object) array(
                'ID'           => 1,
                'user_email'   => 'admin@example.com',
                'display_name' => 'Admin',
                'roles'        => array( 'administrator' ),
            ),
            (object) array(
                'ID'           => 2,
                'user_email'   => 'editor@example.com',
                'display_name' => 'Editor',
                'roles'        => array( 'editor' ),
            ),
        );

        $request = new \WP_REST_Request();
        $result  = $this->helper->get_users_list( $request );

        $data = $result->get_data();
        $this->assertCount( 2, $data['users'] );
        $this->assertEquals( 2, $data['total'] );

        // Check structure.
        $user = $data['users'][0];
        $this->assertArrayHasKey( 'id', $user );
        $this->assertArrayHasKey( 'email', $user );
        $this->assertArrayHasKey( 'name', $user );
        $this->assertArrayHasKey( 'role', $user );
        $this->assertArrayHasKey( 'posts_count', $user );
        $this->assertEquals( 'administrator', $user['role'] );
    }

    /**
     * Test user posts_count field is integer.
     */
    public function test_users_posts_count_is_integer(): void {
        global $_test_wp_users;

        $_test_wp_users = array(
            (object) array(
                'ID'           => 3,
                'user_email'   => 'author@example.com',
                'display_name' => 'Author',
                'roles'        => array( 'author' ),
            ),
        );

        $request = new \WP_REST_Request();
        $result  = $this->helper->get_users_list( $request );

        $data = $result->get_data();
        $this->assertIsInt( $data['users'][0]['posts_count'] );
    }
}
