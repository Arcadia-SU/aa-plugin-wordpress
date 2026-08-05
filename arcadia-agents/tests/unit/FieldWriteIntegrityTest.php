<?php
/**
 * Tests for Phase 42 — field write integrity.
 *
 * Three defects, one root cause: a secondary write path that did not replay
 * the primary path's field processing.
 *
 *   42.1 — approving a revision looped raw update_field() instead of calling
 *          process_acf_fields(), so markdown was never parsed, image URLs were
 *          never sideloaded, and `wysiwyg: null` stored null instead of the
 *          rendered post_content. Field-schema mappings were never replayed
 *          either. On published posts this IS the nominal path.
 *   42.2 — a PUT without acf_fields triggered auto_populate_acf_fields(), which
 *          writes '' into every wysiwyg/textarea field of the post type. Benign
 *          on a fresh post, destructive on a page whose editorial content lives
 *          in exactly those fields.
 *   42.4 — PUT /field-schema accepted an unknown `source`, stored it, then
 *          ignored it in silence at write time.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-taxonomies.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-revisions.php';

/**
 * Minimal class exposing the traits under test.
 */
class FieldWriteIntegrityHelper {
	use \Arcadia_API_Posts_Handler;
	use \Arcadia_API_Formatters;
	use \Arcadia_API_ACF_Fields_Handler;
	use \Arcadia_API_Taxonomies_Handler;
	use \Arcadia_API_Media_Handler;
	use \Arcadia_API_Field_Schema_Handler;
	use \Arcadia_API_Revisions_Handler;

	public $blocks;

	public function __construct() {
		$this->blocks = new class {
			public function json_to_blocks( $json, $post_type = 'post' ) {
				return '<!-- wp:paragraph --><p>rendered</p><!-- /wp:paragraph -->';
			}
		};
	}
}

/**
 * Test class for Phase 42.
 */
class FieldWriteIntegrityTest extends TestCase {

	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_taxonomies, $_test_next_post_id, $_test_users,
			$_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_options                = array();
		$_test_posts                  = array();
		$_test_post_meta              = array();
		$_test_post_categories        = array();
		$_test_post_tags              = array();
		$_test_taxonomies             = array();
		$_test_next_post_id           = 2000;
		$_test_acf_update_field_calls = array();
		$_test_acf_field_groups       = array();
		$_test_acf_fields_by_group    = array();
		$_test_users                  = array(
			1 => (object) array(
				'ID'           => 1,
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'roles'        => array( 'administrator' ),
			),
		);

		$this->helper = new FieldWriteIntegrityHelper();
	}

	/**
	 * Declare an ACF field group so build_acf_field_type_map() resolves types.
	 *
	 * @param string $post_type Post type the group is attached to.
	 * @param array  $fields    List of [name, type] pairs.
	 */
	private function register_acf_group( $post_type, array $fields ) {
		global $_test_acf_field_groups, $_test_acf_fields_by_group;

		$group_key                = 'group_' . $post_type;
		$_test_acf_field_groups[] = array(
			'key'      => $group_key,
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $post_type ) ) ),
		);

		$_test_acf_fields_by_group[ $group_key ] = array_map(
			static function ( $f ) {
				return array( 'name' => $f[0], 'type' => $f[1], 'label' => $f[0], 'key' => 'field_' . $f[0] );
			},
			$fields
		);
	}

	/**
	 * Seed a published post plus a pending revision carrying $payload.
	 *
	 * @param int    $post_id  Live post ID.
	 * @param int    $rev_id   Revision ID.
	 * @param array  $payload  Stored _aa_revision_meta payload.
	 * @param string $rendered Revision post_content.
	 */
	private function seed_revision( $post_id, $rev_id, array $payload, $rendered = '<p>rendered</p>' ) {
		global $_test_posts, $_test_post_meta;

		$_test_posts[ $post_id ] = (object) array(
			'ID'             => $post_id,
			'post_type'      => 'page',
			'post_title'     => 'Live H1',
			'post_name'      => 'live-h1',
			'post_status'    => 'publish',
			'post_content'   => '<p>live content</p>',
			'post_excerpt'   => 'live excerpt',
			'post_author'    => 1,
			'post_parent'    => 0,
			'post_date'      => '2026-08-01 10:00:00',
			'post_modified'  => '2026-08-01 10:00:00',
			'post_mime_type' => '',
		);

		$_test_posts[ $rev_id ] = (object) array(
			'ID'             => $rev_id,
			'post_type'      => 'aa_revision',
			'post_parent'    => $post_id,
			'post_title'     => 'Proposed',
			'post_name'      => '',
			'post_status'    => 'pending',
			'post_content'   => $rendered,
			'post_excerpt'   => '',
			'post_author'    => 1,
			'post_date'      => '2026-08-02 10:00:00',
			'post_modified'  => '2026-08-02 10:00:00',
			'post_mime_type' => '',
		);

		$_test_post_meta[ $rev_id ] = array(
			'_aa_revision_version' => 1,
			'_aa_revision_meta'    => wp_json_encode( $payload ),
		);
	}

	/**
	 * Last value update_field() received for $field_name, or null.
	 *
	 * @param string $field_name Field name.
	 * @return mixed|null
	 */
	private function last_field_value( $field_name ) {
		global $_test_acf_update_field_calls;

		$found = null;
		foreach ( $_test_acf_update_field_calls as $call ) {
			if ( $call['field_name'] === $field_name ) {
				$found = $call['value'];
			}
		}
		return $found;
	}

	// =========================================================================
	// 42.1 — approving a revision runs the same field pipeline as a direct PUT
	// =========================================================================

	/**
	 * A wysiwyg field carrying markdown must be parsed to HTML on approval.
	 *
	 * Before the fix the raw update_field() loop stored '## Titre' verbatim, so
	 * the client saw literal markdown on a page they had just approved.
	 */
	public function test_approve_parses_markdown_in_wysiwyg_field(): void {
		$this->register_acf_group( 'page', array( array( 'body_text', 'wysiwyg' ) ) );

		$this->seed_revision(
			50,
			1050,
			array(
				'body' => array(
					'title'      => 'Proposed H1',
					'acf_fields' => array( 'body_text' => "## Titre\n\nUn **gras**." ),
				),
				'meta' => array(),
			)
		);

		$result = \Arcadia_Revisions::get_instance()->approve_revision( 1050, 'admin' );
		$this->assertIsArray( $result );

		$stored = $this->last_field_value( 'body_text' );
		$this->assertStringContainsString( '<h2>', $stored );
		$this->assertStringContainsString( '<strong>', $stored );
		$this->assertStringNotContainsString( '## Titre', $stored );
	}

	/**
	 * `wysiwyg: null` copies the rendered content, it does not store null.
	 */
	public function test_approve_null_wysiwyg_copies_rendered_content(): void {
		$this->register_acf_group( 'page', array( array( 'body_text', 'wysiwyg' ) ) );

		$this->seed_revision(
			51,
			1051,
			array(
				'body' => array( 'acf_fields' => array( 'body_text' => null ) ),
				'meta' => array(),
			),
			'<p>the rendered body</p>'
		);

		\Arcadia_Revisions::get_instance()->approve_revision( 1051, 'admin' );

		$this->assertEquals( '<p>the rendered body</p>', $this->last_field_value( 'body_text' ) );
	}

	/**
	 * A text sub-type is left alone — the pipeline is type-aware, not blanket.
	 */
	public function test_approve_does_not_convert_plain_text_field(): void {
		$this->register_acf_group( 'page', array( array( 'label', 'text' ) ) );

		$this->seed_revision(
			52,
			1052,
			array(
				'body' => array( 'acf_fields' => array( 'label' => '**not bold**' ) ),
				'meta' => array(),
			)
		);

		\Arcadia_Revisions::get_instance()->approve_revision( 1052, 'admin' );

		$this->assertEquals( '**not bold**', $this->last_field_value( 'label' ) );
	}

	/**
	 * skip_markdown travels in the stored payload and is honoured at approval.
	 *
	 * Without it, round-trip content (already HTML) would be markdown-parsed a
	 * second time on approval — the asymmetry this phase exists to close.
	 */
	public function test_approve_honours_stored_skip_markdown_flag(): void {
		$this->register_acf_group( 'page', array( array( 'body_text', 'wysiwyg' ) ) );

		$this->seed_revision(
			53,
			1053,
			array(
				'body'          => array( 'acf_fields' => array( 'body_text' => '## Not a heading' ) ),
				'meta'          => array(),
				'skip_markdown' => true,
			)
		);

		\Arcadia_Revisions::get_instance()->approve_revision( 1053, 'admin' );

		$this->assertStringNotContainsString( '<h2>', (string) $this->last_field_value( 'body_text' ) );
	}

	/**
	 * create_revision() persists skip_markdown so approval can read it back.
	 */
	public function test_create_revision_persists_skip_markdown(): void {
		global $_test_posts, $_test_post_meta;

		$_test_posts[60] = (object) array(
			'ID'           => 60,
			'post_type'    => 'page',
			'post_title'   => 'A page',
			'post_name'    => 'a-page',
			'post_status'  => 'publish',
			'post_content' => '',
			'post_excerpt'  => '',
			'post_author'   => 1,
			'post_date'     => '2026-08-01 10:00:00',
			'post_modified' => '2026-08-01 10:00:00',
		);

		$result = \Arcadia_Revisions::get_instance()->create_revision(
			60,
			array( 'title' => 'T' ),
			array(),
			'<p>x</p>',
			true
		);

		$stored = json_decode( $_test_post_meta[ $result['revision_id'] ]['_aa_revision_meta'], true );
		$this->assertTrue( $stored['skip_markdown'] );
	}

	/**
	 * meta.title reaches the SEO snippet only — the live H1 keeps its value.
	 *
	 * The revision path duplicated the crossfeed of Phase 42.3; delegating to
	 * finalize_post() removes the second copy rather than patching it twice.
	 */
	public function test_approve_meta_title_does_not_overwrite_live_h1(): void {
		global $_test_posts, $_test_post_meta;

		$this->seed_revision(
			54,
			1054,
			array(
				'body' => array(),
				'meta' => array( 'title' => 'SEO Only' ),
			)
		);

		\Arcadia_Revisions::get_instance()->approve_revision( 1054, 'admin' );

		$this->assertEquals( 'Live H1', $_test_posts[54]->post_title );
		$this->assertEquals( 'SEO Only', $_test_post_meta[54]['_yoast_wpseo_title'] );
	}

	/**
	 * Same rule for the excerpt on the revision path.
	 */
	public function test_approve_meta_description_does_not_overwrite_excerpt(): void {
		global $_test_posts, $_test_post_meta;

		$this->seed_revision(
			55,
			1055,
			array(
				'body' => array(),
				'meta' => array( 'description' => 'Snippet only' ),
			)
		);

		\Arcadia_Revisions::get_instance()->approve_revision( 1055, 'admin' );

		$this->assertEquals( 'live excerpt', $_test_posts[55]->post_excerpt );
		$this->assertEquals( 'Snippet only', $_test_post_meta[55]['_yoast_wpseo_metadesc'] );
	}

	// =========================================================================
	// 42.2 — a partial PUT stays partial
	// =========================================================================

	/**
	 * An UPDATE without acf_fields must not blank the post's wysiwyg fields.
	 *
	 * The destructive case in production: a business page whose editorial
	 * content lives in ACF fields, updated by a payload that simply does not
	 * mention them. The trigger is the ABSENCE of a key, so no caller can guard
	 * against it client-side.
	 */
	public function test_update_without_acf_fields_leaves_fields_untouched(): void {
		global $_test_posts, $_test_acf_update_field_calls;

		$this->register_acf_group(
			'page',
			array( array( 'chapo', 'wysiwyg' ), array( 'notes', 'textarea' ) )
		);

		$_test_posts[70] = (object) array(
			'ID'           => 70,
			'post_type'    => 'page',
			'post_title'   => 'Business page',
			'post_name'    => 'business-page',
			'post_status'  => 'draft',
			'post_content' => '<p>body</p>',
			'post_excerpt'  => '',
			'post_author'   => 1,
			'post_date'     => '2026-08-01 10:00:00',
			'post_modified' => '2026-08-01 10:00:00',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 70 );
		$request->set_json_params( array( 'title' => 'Renamed' ) );

		$this->helper->update_post( $request );

		$this->assertSame(
			array(),
			array_filter(
				$_test_acf_update_field_calls,
				static function ( $call ) {
					return in_array( $call['field_name'], array( 'chapo', 'notes' ), true );
				}
			),
			'An update that does not mention a field must never write it.'
		);
	}

	/**
	 * CREATE keeps auto-population — the safety net still has a job there.
	 *
	 * Guards against over-correcting 42.2 into "never auto-populate at all",
	 * which would reintroduce the themes-crash-on-false problem it was added for.
	 */
	public function test_create_still_auto_populates_acf_references(): void {
		$this->register_acf_group( 'post', array( array( 'chapo', 'wysiwyg' ) ) );

		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'title' => 'Brand new',
			'meta'  => array( 'post_type' => 'post' ),
		) );

		$this->helper->create_post( $request );

		$this->assertSame( '', $this->last_field_value( 'chapo' ) );
	}

	// =========================================================================
	// 42.4 — field-schema sources are validated, and calibration is reversible
	// =========================================================================

	/**
	 * An unknown source is refused, and the error names the accepted values.
	 */
	public function test_field_schema_rejects_unknown_source(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array(
			'page' => array(
				'my_field' => array( 'type' => 'mapping', 'source' => 'not_a_source' ),
			),
		) );

		$result = $this->helper->update_field_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_mapping_source', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertContains( 'excerpt', $data['allowed_sources'] );
		$this->assertStringContainsString( 'meta_title', $result->get_error_message() );
	}

	/**
	 * Every declared source is accepted.
	 */
	public function test_field_schema_accepts_every_declared_source(): void {
		foreach ( \Arcadia_API::mapping_sources() as $source ) {
			$request = new \WP_REST_Request();
			$request->set_json_params( array(
				'page' => array( 'f' => array( 'type' => 'mapping', 'source' => $source ) ),
			) );

			$this->assertNotInstanceOf(
				\WP_Error::class,
				$this->helper->update_field_schema( $request ),
				sprintf( "Declared source '%s' must be accepted.", $source )
			);
		}
	}

	/**
	 * The declared source list and the write-side value map must agree.
	 *
	 * This is the poka-yoke: a source added to one side only would either be
	 * unreachable (rejected at PUT) or silently ignored (accepted, no value) —
	 * the exact defect 42.4 closes. Asserted structurally, not by eyeball.
	 */
	public function test_declared_sources_match_write_side_value_map(): void {
		global $_test_posts;

		$_test_posts[80] = (object) array(
			'ID'           => 80,
			'post_type'    => 'page',
			'post_title'   => 'H1 value',
			'post_name'    => 'h1-value',
			'post_status'  => 'draft',
			'post_content' => '',
			'post_excerpt'  => '',
			'post_author'   => 1,
			'post_date'     => '2026-08-01 10:00:00',
			'post_modified' => '2026-08-01 10:00:00',
		);

		$this->register_acf_group(
			'page',
			array_map(
				static function ( $s ) {
					return array( 'f_' . $s, 'text' );
				},
				\Arcadia_API::mapping_sources()
			)
		);

		// Calibrate one field per declared source.
		$schema = array();
		foreach ( \Arcadia_API::mapping_sources() as $source ) {
			$schema[ 'f_' . $source ] = array( 'type' => 'mapping', 'source' => $source );
		}
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'page' => $schema ) );
		$this->helper->update_field_schema( $request );

		// A body that supplies a non-empty value for every declared source.
		$body = array( 'title' => 'H1 value', 'excerpt' => 'Excerpt value' );
		$meta = array(
			'title'              => 'Meta title value',
			'description'        => 'Meta description value',
			'featured_image_url' => 'https://example.com/i.jpg',
		);

		$this->helper->apply_field_schema_mappings( 80, 'page', $body, $meta );

		foreach ( \Arcadia_API::mapping_sources() as $source ) {
			$this->assertNotNull(
				$this->last_field_value( 'f_' . $source ),
				sprintf(
					"Source '%s' is declared in mapping_sources() but produces no value at write time.",
					$source
				)
			);
		}
	}

	/**
	 * null removes a calibration — the PUT is no longer write-only.
	 */
	public function test_field_schema_null_removes_calibration(): void {
		$set = new \WP_REST_Request();
		$set->set_json_params( array(
			'page' => array( 'my_field' => array( 'type' => 'mapping', 'source' => 'h1' ) ),
		) );
		$this->helper->update_field_schema( $set );

		$stored = get_option( 'aa_field_schema', array() );
		$this->assertArrayHasKey( 'my_field', $stored['page'] );

		$clear = new \WP_REST_Request();
		$clear->set_json_params( array( 'page' => array( 'my_field' => null ) ) );
		$this->assertNotInstanceOf( \WP_Error::class, $this->helper->update_field_schema( $clear ) );

		$stored = get_option( 'aa_field_schema', array() );
		$this->assertArrayNotHasKey( 'my_field', $stored['page'] );
	}

	/**
	 * Removing one field leaves its siblings calibrated (targeted, not a reset).
	 */
	public function test_field_schema_null_only_removes_the_named_field(): void {
		$set = new \WP_REST_Request();
		$set->set_json_params( array(
			'page' => array(
				'keep_me' => array( 'type' => 'mapping', 'source' => 'h1' ),
				'drop_me' => array( 'type' => 'mapping', 'source' => 'excerpt' ),
			),
		) );
		$this->helper->update_field_schema( $set );

		$clear = new \WP_REST_Request();
		$clear->set_json_params( array( 'page' => array( 'drop_me' => null ) ) );
		$this->helper->update_field_schema( $clear );

		$stored = get_option( 'aa_field_schema', array() );
		$this->assertArrayHasKey( 'keep_me', $stored['page'] );
		$this->assertArrayNotHasKey( 'drop_me', $stored['page'] );
	}
}
