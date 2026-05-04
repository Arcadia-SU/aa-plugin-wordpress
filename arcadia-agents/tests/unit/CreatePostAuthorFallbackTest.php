<?php
/**
 * Test: create_post() author resolution + fallback chain.
 *
 * Phase C gap test (safety net before extracting Arcadia_Post_Builder).
 *
 * resolve_author() in trait-api-posts.php is the only place authoring
 * identity is established for new posts. Its behavior is subtle:
 *   1. meta.author lookup by email, then by login → returned ID.
 *   2. Lookup miss → first administrator returned by get_users().
 *   3. No administrators → user ID 1.
 * These branches must not regress when the resolver is moved into
 * Arcadia_Post_Builder.
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

class CreatePostAuthorFallbackHelper {
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

class CreatePostAuthorFallbackTest extends TestCase {

	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_next_post_id,
			$_test_taxonomies, $_test_users_by, $_test_wp_users;

		$_test_options      = array();
		$_test_posts        = array();
		$_test_post_meta    = array();
		$_test_taxonomies   = array();
		$_test_next_post_id = 1000;
		$_test_users_by     = array();
		$_test_wp_users     = array();

		$this->helper = new CreatePostAuthorFallbackHelper();
	}

	private function basic_request( array $extra_meta = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title' => 'Article',
			'meta'  => array_merge( array( 'post_type' => 'post' ), $extra_meta ),
		) );
		return $request;
	}

	public function test_author_resolved_by_email(): void {
		global $_test_users_by, $_test_posts;

		$_test_users_by['email:editor@test.com'] = (object) array(
			'ID'         => 7,
			'user_email' => 'editor@test.com',
			'user_login' => 'editor',
		);

		$result = $this->helper->create_post( $this->basic_request( array( 'author' => 'editor@test.com' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 7, (int) $_test_posts[ $post_id ]->post_author );
	}

	public function test_author_resolved_by_login_when_email_misses(): void {
		global $_test_users_by, $_test_posts;

		$_test_users_by['login:editor_user'] = (object) array(
			'ID'         => 9,
			'user_email' => 'editor@test.com',
			'user_login' => 'editor_user',
		);

		$result = $this->helper->create_post( $this->basic_request( array( 'author' => 'editor_user' ) ) );

		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 9, (int) $_test_posts[ $post_id ]->post_author );
	}

	public function test_unknown_author_falls_back_to_first_admin(): void {
		global $_test_wp_users, $_test_posts;

		$_test_wp_users = array( 42 );

		$result = $this->helper->create_post( $this->basic_request( array( 'author' => 'ghost@nowhere.test' ) ) );

		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 42, (int) $_test_posts[ $post_id ]->post_author );
	}

	public function test_missing_author_falls_back_to_first_admin(): void {
		global $_test_wp_users, $_test_posts;

		$_test_wp_users = array( 17 );

		$result = $this->helper->create_post( $this->basic_request() );

		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 17, (int) $_test_posts[ $post_id ]->post_author );
	}

	public function test_no_admins_falls_back_to_user_id_1(): void {
		global $_test_wp_users, $_test_posts;

		$_test_wp_users = array(); // No administrators on the site.

		$result = $this->helper->create_post( $this->basic_request() );

		$post_id = $result->get_data()['post_id'];
		$this->assertSame( 1, (int) $_test_posts[ $post_id ]->post_author );
	}
}
