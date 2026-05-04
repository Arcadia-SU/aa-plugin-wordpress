<?php
/**
 * Test: update_post() rejects post_type changes.
 *
 * Phase C gap test (safety net before extracting Arcadia_Post_Builder).
 *
 * Mutating an existing post's type via PUT corrupts type-scoped state
 * (taxonomies, ACF field groups, theme templates). The trait refuses any
 * payload whose meta.post_type or body.post_type differs from the
 * existing post's post_type, returning post_type_change_forbidden 400.
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

/**
 * Minimal helper exposing the posts trait for tests.
 */
class UpdatePostTypeRejectionHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json, $post_type = 'post' ) {
				return '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
			}
		};
	}
}

class UpdatePostTypeRejectionTest extends TestCase {

	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_next_post_id, $_test_taxonomies;

		$_test_options      = array();
		$_test_posts        = array();
		$_test_post_meta    = array();
		$_test_taxonomies   = array();
		$_test_next_post_id = 1000;

		$this->helper = new UpdatePostTypeRejectionHelper();
	}

	private function seed_post( int $id, string $post_type = 'post' ): void {
		global $_test_posts, $_test_post_meta;
		$_test_posts[ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => $post_type,
			'post_parent'    => 0,
			'post_title'     => 'Original',
			'post_status'    => 'publish',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_date'      => '2026-04-01 00:00:00',
			'post_modified'  => '2026-04-01 00:00:00',
			'post_author'    => 1,
			'post_name'      => 'original',
			'post_mime_type' => '',
		);
		$_test_post_meta[ $id ] = array();
	}

	public function test_meta_post_type_change_is_rejected(): void {
		$this->seed_post( 50, 'post' );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 50 );
		$request->set_json_params( array(
			'title' => 'New Title',
			'meta'  => array( 'post_type' => 'page' ),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_type_change_forbidden', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );

		// Live post stays untouched.
		global $_test_posts;
		$this->assertSame( 'post', $_test_posts[50]->post_type );
		$this->assertSame( 'Original', $_test_posts[50]->post_title );
	}

	public function test_body_post_type_change_is_rejected(): void {
		$this->seed_post( 51, 'post' );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 51 );
		$request->set_json_params( array(
			'title'     => 'New Title',
			'post_type' => 'page',
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_type_change_forbidden', $result->get_error_code() );
	}

	public function test_same_post_type_is_accepted(): void {
		$this->seed_post( 52, 'post' );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 52 );
		$request->set_json_params( array(
			'title' => 'Updated Title',
			'meta'  => array( 'post_type' => 'post' ),
		) );

		$result = $this->helper->update_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}
}
