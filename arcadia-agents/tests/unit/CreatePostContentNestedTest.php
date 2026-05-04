<?php
/**
 * Test: create_post() accepts content nested in body.content.
 *
 * Phase C gap test (safety net before extracting Arcadia_Post_Builder).
 *
 * The API accepts two payload shapes for backward compatibility:
 *
 *   Flat shape (canonical):
 *     { "title": "...", "h1": "...", "sections": [...], "meta": {...} }
 *
 *   Nested shape (legacy / wrapper):
 *     { "title": "...", "content": { "h1": "...", "sections": [...], "meta": {...} } }
 *
 * In nested shape, the trait promotes content.meta values (title, slug,
 * description) to post fields when no top-level meta is present, AND
 * sends content.{h1,sections,children} through json_to_blocks().
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

if ( ! trait_exists( 'Arcadia_API_Posts_Handler' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
}

class CreatePostContentNestedHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	public $blocks;

	/** @var array<int, array{json: array, post_type: string}> */
	public $blocks_calls = array();

	public function __construct() {
		$test = $this;
		$this->blocks = new class( $test ) {
			private $test;

			public function __construct( $test ) {
				$this->test = $test;
			}

			public function json_to_blocks( $json, $post_type = 'post' ) {
				$this->test->blocks_calls[] = array(
					'json'      => $json,
					'post_type' => $post_type,
				);
				return '<!-- wp:paragraph --><p>NESTED RENDERED</p><!-- /wp:paragraph -->';
			}
		};
	}
}

class CreatePostContentNestedTest extends TestCase {

	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_next_post_id,
			$_test_taxonomies, $_test_wp_users;

		$_test_options      = array();
		$_test_posts        = array();
		$_test_post_meta    = array();
		$_test_taxonomies   = array();
		$_test_next_post_id = 1000;
		$_test_wp_users     = array( 1 );

		$this->helper = new CreatePostContentNestedHelper();
	}

	public function test_nested_content_is_rendered_through_json_to_blocks(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Top-level Title',
			'content' => array(
				'h1'       => 'H1 from nested',
				'sections' => array( array( 'type' => 'paragraph', 'content' => 'p1' ) ),
				'meta'     => array(
					'title'       => 'SEO Title from nested',
					'slug'        => 'nested-slug',
					'description' => 'SEO meta description from nested',
				),
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$post_id = $result->get_data()['post_id'];

		// json_to_blocks was invoked once with the NESTED body, not the outer one.
		$this->assertCount( 1, $this->helper->blocks_calls );
		$this->assertSame( 'H1 from nested', $this->helper->blocks_calls[0]['json']['h1'] );

		// Rendered output landed in post_content.
		$this->assertStringContainsString( 'NESTED RENDERED', $_test_posts[ $post_id ]->post_content );
	}

	public function test_nested_meta_promotes_to_post_fields_when_top_level_meta_absent(): void {
		global $_test_posts, $_test_post_meta;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			// No top-level title, no top-level meta — body.content.meta must drive.
			'content' => array(
				'h1'       => 'H1',
				'sections' => array(),
				'meta'     => array(
					'title'       => 'Promoted Title',
					'slug'        => 'promoted-slug',
					'description' => 'Promoted excerpt',
					'post_type'   => 'post',
				),
			),
		) );

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$post_id = $result->get_data()['post_id'];

		$this->assertSame( 'Promoted Title', $_test_posts[ $post_id ]->post_title );
		$this->assertSame( 'promoted-slug', $_test_posts[ $post_id ]->post_name );
		$this->assertSame( 'Promoted excerpt', $_test_posts[ $post_id ]->post_excerpt );

		// SEO meta propagated through promoted meta.title / meta.description.
		$this->assertSame( 'Promoted Title', $_test_post_meta[ $post_id ]['_yoast_wpseo_title'] );
		$this->assertSame( 'Promoted excerpt', $_test_post_meta[ $post_id ]['_yoast_wpseo_metadesc'] );
	}

	public function test_top_level_title_takes_priority_over_nested_meta_title(): void {
		global $_test_posts;

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title'   => 'Top-level H1',
			'content' => array(
				'h1'   => 'h1',
				'meta' => array(
					'title' => 'Nested SEO Title',
				),
			),
		) );

		$result = $this->helper->create_post( $request );

		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 'Top-level H1', $_test_posts[ $post_id ]->post_title );
	}
}
