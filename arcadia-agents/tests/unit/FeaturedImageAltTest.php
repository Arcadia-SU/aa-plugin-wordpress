<?php
/**
 * Tests for featured_image_alt propagation during sideload.
 *
 * Tests that:
 * - create_post passes featured_image_alt to sideload
 * - update_post passes featured_image_alt to sideload
 * - Omitting featured_image_alt does NOT set alt text on attachment
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required traits.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';

/**
 * Minimal class exposing traits for testing.
 */
class FeaturedImageAltHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	/** @var object */
	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json ) {
				return '<!-- wp:paragraph --><p>test</p><!-- /wp:paragraph -->';
			}
		};
	}
}

/**
 * Test class for featured image alt text propagation.
 */
class FeaturedImageAltTest extends TestCase {

	/** @var FeaturedImageAltHelper */
	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_taxonomies, $_test_next_post_id, $_test_users,
			$_test_next_attachment_id;

		$_test_options           = array();
		$_test_posts             = array();
		$_test_post_meta         = array();
		$_test_post_categories   = array();
		$_test_post_tags         = array();
		$_test_taxonomies        = array();
		$_test_next_post_id      = 1000;
		$_test_next_attachment_id = 5000;
		$_test_users             = array(
			1 => (object) array(
				'ID'           => 1,
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'roles'        => array( 'administrator' ),
			),
		);

		$this->helper = new FeaturedImageAltHelper();
	}

	/**
	 * Test create_post sets featured_image_alt on the attachment.
	 */
	public function test_create_post_sets_featured_image_alt(): void {
		global $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Article with image',
			'content' => 'Hello',
			'meta'    => array(
				'featured_image_url' => 'https://example.com/photo.jpg',
				'featured_image_alt' => 'A beautiful sunset',
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );

		// The attachment ID is 5000 (first from stub).
		$this->assertEquals(
			'A beautiful sunset',
			$_test_post_meta[5000]['_wp_attachment_image_alt']
		);
	}

	/**
	 * Test update_post sets featured_image_alt on the attachment.
	 */
	public function test_update_post_sets_featured_image_alt(): void {
		global $_test_posts, $_test_post_meta;

		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'meta' => array(
				'featured_image_url' => 'https://example.com/new-photo.jpg',
				'featured_image_alt' => 'Updated alt text',
			),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		$this->assertEquals(
			'Updated alt text',
			$_test_post_meta[5000]['_wp_attachment_image_alt']
		);
	}

	/**
	 * Test create_post surfaces sideload failure as warning (not error).
	 */
	public function test_create_post_sideload_failure_returns_warning(): void {
		global $_test_download_url_result;
		$_test_download_url_result = new \WP_Error( 'http_404', 'Not Found' );

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Article with broken image',
			'content' => 'Hello',
			'meta'    => array(
				'featured_image_url' => 'https://example.com/broken.jpg',
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['warnings'] );
		$this->assertStringContainsString( 'Featured image sideload failed', $data['warnings'][0] );

		$_test_download_url_result = null;
	}

	/**
	 * Test update_post surfaces sideload failure as warning (not error).
	 */
	public function test_update_post_sideload_failure_returns_warning(): void {
		global $_test_posts, $_test_download_url_result;
		$_test_download_url_result = new \WP_Error( 'http_timeout', 'Connection timed out' );

		$_test_posts[42] = (object) array(
			'ID'             => 42,
			'post_type'      => 'post',
			'post_title'     => 'Existing',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'existing',
			'post_mime_type' => '',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 42 );
		$request->set_json_params( array(
			'meta' => array(
				'featured_image_url' => 'https://example.com/timeout.webp',
			),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['warnings'] );
		$this->assertStringContainsString( 'Featured image sideload failed', $data['warnings'][0] );

		$_test_download_url_result = null;
	}

	/**
	 * Test create_post WITHOUT featured_image_alt does NOT set alt meta.
	 */
	public function test_create_post_omits_alt_when_not_provided(): void {
		global $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Article without alt',
			'content' => 'Hello',
			'meta'    => array(
				'featured_image_url' => 'https://example.com/photo.jpg',
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		// Alt should NOT be set on the attachment.
		$this->assertArrayNotHasKey(
			'_wp_attachment_image_alt',
			$_test_post_meta[5000] ?? array()
		);
	}
}
