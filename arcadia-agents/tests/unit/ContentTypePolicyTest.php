<?php
/**
 * Test: unified content-type policy + structural-field rejection (Phase 41.1).
 *
 * Three incompatible type policies used to coexist in the plugin:
 *
 *   | Seam                     | Old policy                | Effect on `page` |
 *   |--------------------------|---------------------------|------------------|
 *   | is_allowed_post_type()   | public && !hierarchical   | 404 / 400        |
 *   | get_posts()              | none (raw passthrough)    | served in full   |
 *   | get_blocks_usage()       | public minus attachment   | included         |
 *
 * An agent could therefore list a business page and read its content, but not
 * fetch its blocks nor edit it. The policy is now one — public, minus
 * `attachment` — applied on every seam.
 *
 * Opening hierarchical types does NOT open site structure: `post_parent`,
 * `menu_order` and `page_template` are refused with a 422 instead of being
 * silently dropped, so the agent learns the boundary rather than believing
 * the write took effect.
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

require_once dirname( __DIR__, 2 ) . '/includes/class-post-builder.php';

/**
 * Minimal helper exposing the posts trait for tests.
 */
class ContentTypePolicyHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;

	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json, $post_type = 'post', $dry_run = false ) {
				return '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
			}
		};
	}
}

class ContentTypePolicyTest extends TestCase {

	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_next_post_id, $_test_taxonomies;

		$_test_options      = array();
		$_test_posts        = array();
		$_test_post_meta    = array();
		$_test_taxonomies   = array();
		$_test_next_post_id = 1000;

		\WP_Query::reset();

		$this->helper = new ContentTypePolicyHelper();
	}

	protected function tearDown(): void {
		\WP_Query::reset();
	}

	private function seed_post( int $id, string $post_type = 'post' ): void {
		global $_test_posts, $_test_post_meta;
		$_test_posts[ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => $post_type,
			'post_parent'    => 0,
			'menu_order'     => 0,
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

	private function update_request( int $id, array $body ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', $id );
		$request->set_json_params( $body );
		return $request;
	}

	// ---------------------------------------------------------------------
	// Hierarchical types are now first-class content.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider hierarchical_types
	 */
	public function test_hierarchical_type_can_be_updated( string $post_type ): void {
		$this->seed_post( 60, $post_type );

		$result = $this->helper->update_post( $this->update_request( 60, array( 'title' => 'Nouveau titre' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result, "update_post refused post_type '{$post_type}'" );
		$this->assertSame( 200, $result->get_status() );

		global $_test_posts;
		$this->assertSame( 'Nouveau titre', $_test_posts[60]->post_title );
	}

	/**
	 * @dataProvider hierarchical_types
	 */
	public function test_hierarchical_type_blocks_can_be_read( string $post_type ): void {
		$this->seed_post( 61, $post_type );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 61 );

		$result = $this->helper->get_article_blocks( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result, "get_article_blocks refused post_type '{$post_type}'" );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * @dataProvider hierarchical_types
	 */
	public function test_hierarchical_type_can_be_deleted( string $post_type ): void {
		$this->seed_post( 62, $post_type );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 62 );

		$result = $this->helper->delete_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result, "delete_post refused post_type '{$post_type}'" );
		$this->assertSame( 200, $result->get_status() );
	}

	public function test_hierarchical_type_can_be_created(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'title' => 'Page métier',
				'meta'  => array( 'post_type' => 'page' ),
			)
		);

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
	}

	public static function hierarchical_types(): array {
		return array(
			'native page'      => array( 'page' ),
			'hierarchical CPT' => array( 'landing' ),
		);
	}

	// ---------------------------------------------------------------------
	// …and the policy still closes what it must close.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider disallowed_types
	 */
	public function test_disallowed_type_is_refused_on_update( string $post_type ): void {
		$this->seed_post( 63, $post_type );

		$result = $this->helper->update_post( $this->update_request( 63, array( 'title' => 'x' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result, "update_post accepted post_type '{$post_type}'" );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * @dataProvider disallowed_types
	 */
	public function test_disallowed_type_is_refused_on_blocks( string $post_type ): void {
		$this->seed_post( 64, $post_type );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 64 );

		$result = $this->helper->get_article_blocks( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "get_article_blocks accepted post_type '{$post_type}'" );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * @dataProvider disallowed_types
	 */
	public function test_disallowed_type_is_refused_on_delete( string $post_type ): void {
		$this->seed_post( 65, $post_type );

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 65 );

		$result = $this->helper->delete_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "delete_post accepted post_type '{$post_type}'" );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * @dataProvider disallowed_types
	 */
	public function test_disallowed_type_is_refused_on_create( string $post_type ): void {
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'title' => 'x',
				'meta'  => array( 'post_type' => $post_type ),
			)
		);

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "create_post accepted post_type '{$post_type}'" );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public static function disallowed_types(): array {
		return array(
			// Public + non-hierarchical, so the OLD guard let it through.
			'attachment'     => array( 'attachment' ),
			'non-public CPT' => array( 'aa_secret' ),
			'unregistered'   => array( 'no_such_type' ),
		);
	}

	// ---------------------------------------------------------------------
	// The listing shares the guard instead of trusting WP_Query.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider disallowed_types
	 */
	public function test_listing_refuses_disallowed_type( string $post_type ): void {
		$request = new \WP_REST_Request();
		$request->set_param( 'post_type', $post_type );

		$result = $this->helper->get_posts( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "get_posts served post_type '{$post_type}'" );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_listing_accepts_page(): void {
		$request = new \WP_REST_Request();
		$request->set_param( 'post_type', 'page' );

		$result = $this->helper->get_posts( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	// ---------------------------------------------------------------------
	// Structural fields: 422, not a silent drop.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider structural_field_paths
	 */
	public function test_structural_field_is_rejected_with_422( array $body, string $expected_path ): void {
		$this->seed_post( 70, 'page' );

		$result = $this->helper->update_post( $this->update_request( 70, $body ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_structural_field', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] ?? null );
		$this->assertSame( $expected_path, $data['field'] ?? null );
		$this->assertSame(
			\Arcadia_Post_Builder::FORBIDDEN_STRUCTURAL_FIELDS,
			$data['forbidden_fields'] ?? null,
			'The error must name the whole forbidden set, not only the field that tripped it.'
		);
	}

	public static function structural_field_paths(): array {
		return array(
			'top-level post_parent'   => array( array( 'post_parent' => 12 ), 'post_parent' ),
			'top-level menu_order'    => array( array( 'menu_order' => 3 ), 'menu_order' ),
			'top-level page_template' => array( array( 'page_template' => 'tpl.php' ), 'page_template' ),
			'meta.post_parent'        => array( array( 'meta' => array( 'post_parent' => 12 ) ), 'meta.post_parent' ),
			'meta.menu_order'         => array( array( 'meta' => array( 'menu_order' => 3 ) ), 'meta.menu_order' ),
			'meta.page_template'      => array( array( 'meta' => array( 'page_template' => 'tpl.php' ) ), 'meta.page_template' ),
		);
	}

	/**
	 * A null value is still an instruction — `"post_parent": null` means
	 * "detach me". array_key_exists, not isset.
	 */
	public function test_structural_field_rejected_even_when_null(): void {
		$this->seed_post( 71, 'page' );

		$result = $this->helper->update_post( $this->update_request( 71, array( 'post_parent' => null ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_structural_field', $result->get_error_code() );
	}

	/**
	 * The nested-content shape promotes content.meta to $meta *after* the
	 * point where a naive check would run — so it gets its own scope.
	 */
	public function test_structural_field_rejected_in_nested_content_meta(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'content' => array(
					'h1'   => 'Titre',
					'meta' => array( 'post_type' => 'page', 'post_parent' => 12 ),
				),
			)
		);

		$result = $this->helper->create_post( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_structural_field', $result->get_error_code() );
		$this->assertSame( 'content.meta.post_parent', $result->get_error_data()['field'] ?? null );
	}

	public function test_rejection_happens_before_any_write(): void {
		$this->seed_post( 72, 'page' );

		$this->helper->update_post( $this->update_request( 72, array( 'title' => 'Écrasé', 'post_parent' => 12 ) ) );

		global $_test_posts;
		$this->assertSame( 'Original', $_test_posts[72]->post_title, 'A refused write must not have applied its other fields.' );
		$this->assertSame( 0, $_test_posts[72]->post_parent );
	}

	public function test_clean_body_is_untouched_by_the_guard(): void {
		$this->seed_post( 73, 'page' );

		$result = $this->helper->update_post( $this->update_request( 73, array( 'title' => 'Propre' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	// ---------------------------------------------------------------------
	// Anti-refactor lock.
	// ---------------------------------------------------------------------

	/**
	 * The 422 is a signal, not the barrier. The barrier is that
	 * build_post_data() constructs its payload key by key and can therefore
	 * never emit a field it does not name itself.
	 *
	 * Asserted separately from the 422 on purpose: a future refactor to a
	 * copy-then-filter shape would keep the 422 test green while reopening the
	 * hole for any field the filter forgets.
	 */
	public function test_build_post_data_never_emits_an_unknown_key(): void {
		$known = array(
			'ID',
			'post_type',
			'post_status',
			'post_title',
			'post_name',
			'post_excerpt',
			'post_content',
		);

		$builder = new \Arcadia_Post_Builder( $this->helper->blocks );
		$meta    = array(
			'title'       => 'T',
			'slug'        => 's',
			'description' => 'd',
		);

		$body = array(
			'title'         => 'Titre',
			'excerpt'       => 'Extrait',
			'status'        => 'draft',
			'h1'            => 'H1',
			'sections'      => array( array( 'type' => 'paragraph', 'text' => 'x' ) ),
			// Fields the builder must never carry through, forbidden or not.
			'author'        => 'someone@example.com',
			'comment_status' => 'open',
			'ping_status'   => 'closed',
			'guid'          => 'http://evil.test/',
		);

		$built = $builder->build_post_data( $body, $meta, 'page' );

		$this->assertIsArray( $built );
		$unexpected = array_diff( array_keys( $built['post_data'] ), $known );
		$this->assertSame(
			array(),
			$unexpected,
			'build_post_data() emitted a key outside its declared set: ' . implode( ', ', $unexpected )
		);
	}
}
