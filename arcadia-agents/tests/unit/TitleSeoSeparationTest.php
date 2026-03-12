<?php
/**
 * Tests for title vs SEO meta-title separation.
 *
 * Verifies that body.title (H1) sets post_title and meta.title sets
 * _yoast_wpseo_title independently — fixing the bug where both used
 * meta.title as primary source.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load all required class files for the API handler.
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';

// Load all API traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';

/**
 * Minimal API class exposing post trait methods for testing.
 */
class TitleSeoHelper {
	use \Arcadia_API_Formatters;
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_ACF_Fields_Handler;

	/**
	 * Block generator (required by create_post/update_post).
	 *
	 * @var \Arcadia_Blocks
	 */
	public $blocks;

	/**
	 * Constructor — set up blocks dependency.
	 */
	public function __construct() {
		$this->blocks = \Arcadia_Blocks::get_instance();
	}
}

/**
 * Test class for H1 / SEO meta-title separation.
 */
class TitleSeoSeparationTest extends TestCase {

	/**
	 * @var TitleSeoHelper
	 */
	private $helper;

	/**
	 * Reset state before each test.
	 */
	protected function setUp(): void {
		global $_test_posts, $_test_post_meta, $_test_next_post_id,
			   $_test_options, $_test_wp_users, $_test_taxonomies;

		$_test_posts       = array();
		$_test_post_meta   = array();
		$_test_next_post_id = 1000;
		$_test_options     = array();
		$_test_wp_users    = array( 1 ); // Admin fallback.
		$_test_taxonomies  = array();

		// Reset Arcadia_Preview singleton (tokens depend on post meta).
		$ref = new \ReflectionClass( \Arcadia_Preview::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->helper = new TitleSeoHelper();
	}

	// ------------------------------------------------------------------
	// create_post tests
	// ------------------------------------------------------------------

	/**
	 * Test: body.title takes priority over meta.title for post_title.
	 */
	public function test_create_body_title_priority(): void {
		global $_test_posts, $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title' => 'H1 Visible Heading',
			'meta'  => array(
				'title'     => 'SEO Meta Title',
				'post_type' => 'post',
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		// post_title should come from body.title (H1).
		$post = $_test_posts[ $data['post_id'] ];
		$this->assertEquals( 'H1 Visible Heading', $post->post_title );

		// _yoast_wpseo_title should come from meta.title (SEO meta-title).
		$this->assertEquals(
			'SEO Meta Title',
			$_test_post_meta[ $data['post_id'] ]['_yoast_wpseo_title']
		);
	}

	/**
	 * Test: only body.title — post_title set, no SEO meta-title.
	 */
	public function test_create_only_body_title(): void {
		global $_test_posts, $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title' => 'Just an H1',
			'meta'  => array( 'post_type' => 'post' ),
		) );

		$result = $this->helper->create_post( $request );
		$data   = $result->get_data();

		$post = $_test_posts[ $data['post_id'] ];
		$this->assertEquals( 'Just an H1', $post->post_title );

		// No meta.title → _yoast_wpseo_title should NOT be set.
		$this->assertArrayNotHasKey(
			'_yoast_wpseo_title',
			$_test_post_meta[ $data['post_id'] ]
		);
	}

	/**
	 * Test: only meta.title — backward compat fallback for post_title.
	 */
	public function test_create_only_meta_title_fallback(): void {
		global $_test_posts, $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'meta' => array(
				'title'     => 'Title From Meta',
				'post_type' => 'post',
			),
		) );

		$result = $this->helper->create_post( $request );
		$data   = $result->get_data();

		$post = $_test_posts[ $data['post_id'] ];
		$this->assertEquals( 'Title From Meta', $post->post_title );

		// meta.title also sets _yoast_wpseo_title.
		$this->assertEquals(
			'Title From Meta',
			$_test_post_meta[ $data['post_id'] ]['_yoast_wpseo_title']
		);
	}

	/**
	 * Test: meta.description sets _yoast_wpseo_metadesc independently.
	 */
	public function test_create_meta_description_sets_yoast_metadesc(): void {
		global $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title' => 'Article Title',
			'meta'  => array(
				'title'       => 'SEO Title',
				'description' => 'SEO meta description for search engines',
				'post_type'   => 'post',
			),
		) );

		$result = $this->helper->create_post( $request );
		$data   = $result->get_data();

		$this->assertEquals(
			'SEO meta description for search engines',
			$_test_post_meta[ $data['post_id'] ]['_yoast_wpseo_metadesc']
		);
	}

	// ------------------------------------------------------------------
	// update_post tests
	// ------------------------------------------------------------------

	/**
	 * Test: body.title takes priority over meta.title in update_post.
	 */
	public function test_update_body_title_priority(): void {
		global $_test_posts, $_test_post_meta;

		// Seed an existing post.
		$_test_posts[42] = (object) array(
			'ID'            => 42,
			'post_type'     => 'post',
			'post_title'    => 'Old Title',
			'post_status'   => 'draft',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_date'     => '2026-01-01 00:00:00',
			'post_modified' => '2026-01-01 00:00:00',
			'post_author'   => 1,
			'post_name'     => 'old-title',
			'post_mime_type' => '',
		);
		$_test_post_meta[42] = array();

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'title' => 'New H1 Heading',
			'meta'  => array(
				'title' => 'New SEO Title',
			),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		// _yoast_wpseo_title should come from meta.title.
		$this->assertEquals(
			'New SEO Title',
			$_test_post_meta[42]['_yoast_wpseo_title']
		);
	}

	/**
	 * Test: only body.title in update — no SEO meta-title set.
	 */
	public function test_update_only_body_title(): void {
		global $_test_posts, $_test_post_meta;

		$_test_posts[43] = (object) array(
			'ID'            => 43,
			'post_type'     => 'post',
			'post_title'    => 'Old Title',
			'post_status'   => 'publish',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_date'     => '2026-01-01 00:00:00',
			'post_modified' => '2026-01-01 00:00:00',
			'post_author'   => 1,
			'post_name'     => 'old-title',
			'post_mime_type' => '',
		);
		$_test_post_meta[43] = array();

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 43 );
		$request->set_json_params( array(
			'title' => 'Updated H1 Only',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		// No meta.title → _yoast_wpseo_title should NOT be set.
		$this->assertArrayNotHasKey(
			'_yoast_wpseo_title',
			$_test_post_meta[43]
		);
	}

	// ------------------------------------------------------------------
	// update_page tests
	// ------------------------------------------------------------------

	/**
	 * Test: body.title takes priority over meta.title in update_page.
	 */
	public function test_update_page_body_title_priority(): void {
		global $_test_posts, $_test_post_meta;

		$_test_posts[80] = (object) array(
			'ID'            => 80,
			'post_type'     => 'page',
			'post_title'    => 'Old Page Title',
			'post_status'   => 'publish',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_date'     => '2026-01-01 00:00:00',
			'post_modified' => '2026-01-01 00:00:00',
			'post_author'   => 1,
			'post_name'     => 'old-page',
			'post_mime_type' => '',
			'post_parent'   => 0,
			'menu_order'    => 0,
		);
		$_test_post_meta[80] = array();

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 80 );
		$request->set_json_params( array(
			'title' => 'New Page H1',
			'meta'  => array(
				'title' => 'Page SEO Title',
			),
		) );

		$result = $this->helper->update_page( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		// _yoast_wpseo_title for the page should come from meta.title.
		$this->assertEquals(
			'Page SEO Title',
			$_test_post_meta[80]['_yoast_wpseo_title']
		);
	}
}
