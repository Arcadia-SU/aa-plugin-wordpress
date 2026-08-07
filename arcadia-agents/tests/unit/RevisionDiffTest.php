<?php
/**
 * Tests for Phase 43.1/43.2 — a pending revision must say what it proposes.
 *
 * A revision stores its payload as JSON and writes nothing until approval. That
 * left the proposal unreadable: the REST detail endpoint returned metadata only,
 * and the admin showed a bare "PENDING" row. Approving was a blind click.
 *
 * These tests pin the three things that make the projection trustworthy:
 *   - it reports ONLY fields the payload actually touches (a diff that invents
 *     entries is as useless as no diff);
 *   - it names the coercion each value will undergo instead of applying it —
 *     building a diff must never import a media file;
 *   - it surfaces the calibrated field-schema writes the payload never names,
 *     which until now changed content with nothing on screen to hint at it.
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

/**
 * Field context stand-in: the same traits Arcadia_API composes, without booting it.
 */
class RevisionDiffContext {
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
				return '<p>rendered</p>';
			}
		};
	}
}

/**
 * Test class for the revision diff projection.
 */
class RevisionDiffTest extends TestCase {

	private $context;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_acf_field_groups, $_test_acf_fields_by_group,
			$_test_get_fields_results, $_test_acf_update_field_calls, $_test_filters;

		$_test_options                = array();
		$_test_posts                  = array();
		$_test_post_meta              = array();
		$_test_post_categories        = array();
		$_test_post_tags              = array();
		$_test_acf_field_groups       = array();
		$_test_acf_fields_by_group    = array();
		$_test_get_fields_results     = array();
		$_test_acf_update_field_calls = array();
		$_test_filters                = array();

		$this->context = new RevisionDiffContext();
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
	 * Seed a published parent plus a pending revision carrying $payload.
	 *
	 * @param array $payload Stored _aa_revision_meta payload.
	 * @return \stdClass The revision post object.
	 */
	private function seed( array $payload ) {
		global $_test_posts, $_test_post_meta;

		$_test_posts[10] = (object) array(
			'ID'             => 10,
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

		$_test_posts[11] = (object) array(
			'ID'             => 11,
			'post_type'      => 'aa_revision',
			'post_parent'    => 10,
			'post_title'     => 'Proposed',
			'post_name'      => '',
			'post_status'    => 'pending',
			'post_content'   => '<p>proposed content</p>',
			'post_excerpt'   => '',
			'post_author'    => 1,
			'post_date'      => '2026-08-02 10:00:00',
			'post_modified'  => '2026-08-02 10:00:00',
			'post_mime_type' => '',
		);

		$_test_post_meta[11] = array(
			'_aa_revision_version' => 1,
			'_aa_revision_meta'    => wp_json_encode( $payload ),
		);

		return $_test_posts[11];
	}

	/**
	 * Build the projection for a payload.
	 *
	 * @param array $payload Stored revision payload.
	 * @return array
	 */
	private function build( array $payload ) {
		$diff = new \Arcadia_Revision_Diff( $this->context );

		return $diff->build( $this->seed( $payload ) );
	}

	/**
	 * Find one change entry by its dotted field path.
	 *
	 * @param array  $result Projection result.
	 * @param string $field  Dotted path.
	 * @return array|null
	 */
	private function change( array $result, $field ) {
		foreach ( $result['changes'] as $change ) {
			if ( $change['field'] === $field ) {
				return $change;
			}
		}
		return null;
	}

	/**
	 * Every dotted field path present in a projection.
	 *
	 * @param array $result Projection result.
	 * @return array
	 */
	private function fields( array $result ) {
		return array_column( $result['changes'], 'field' );
	}

	// =========================================================================
	// Only what the payload touches
	// =========================================================================

	/**
	 * The whole value of a diff is that it is a diff — a projection listing every
	 * field of the post would tell the reviewer nothing about what changes.
	 */
	public function test_only_touched_fields_appear(): void {
		$result = $this->build(
			array(
				'body' => array( 'title' => 'Proposed H1' ),
				'meta' => array(),
			)
		);

		$this->assertSame( array( 'title' ), $this->fields( $result ) );
		$this->assertSame( 'Live H1', $this->change( $result, 'title' )['current'] );
		$this->assertSame( 'Proposed H1', $this->change( $result, 'title' )['proposed'] );
	}

	/**
	 * An explicit empty string is a proposal (it clears the field); an absent key
	 * is not. The write path uses isset() for exactly this reason, so the diff
	 * must draw the line in the same place or it will under-report a deletion.
	 */
	public function test_explicit_empty_string_is_a_change_absent_key_is_not(): void {
		$result = $this->build(
			array(
				'body' => array( 'excerpt' => '' ),
				'meta' => array(),
			)
		);

		$this->assertSame( array( 'excerpt' ), $this->fields( $result ) );
		$this->assertSame( '', $this->change( $result, 'excerpt' )['proposed'] );
		$this->assertSame( 'live excerpt', $this->change( $result, 'excerpt' )['current'] );
	}

	/**
	 * body.status is deliberately absent: approve_revision() never applies it.
	 * Reporting it as "proposed" would state something untrue on the one screen
	 * whose entire job is to be trustworthy.
	 */
	public function test_status_is_not_reported_because_approval_never_applies_it(): void {
		$result = $this->build(
			array(
				'body' => array( 'status' => 'draft', 'title' => 'T' ),
				'meta' => array(),
			)
		);

		$this->assertSame( array( 'title' ), $this->fields( $result ) );
	}

	/**
	 * Content lives in the revision's post_content, not in the change list —
	 * a block-markup diff is unreadable, so the flag points at the preview.
	 */
	public function test_content_is_a_flag_not_a_change_entry(): void {
		$result = $this->build(
			array(
				'body' => array( 'children' => array( array( 'type' => 'paragraph' ) ) ),
				'meta' => array(),
			)
		);

		$this->assertTrue( $result['content_changed'] );
		$this->assertSame( array(), $result['changes'] );
	}

	// =========================================================================
	// Current values come from the live post
	// =========================================================================

	/**
	 * SEO fields read through Arcadia_SEO_Meta, and slug off the post row — the
	 * "before" has to be what the site actually shows today.
	 *
	 * With no SEO plugin present, get_seo_meta() falls back to post_title /
	 * post_excerpt. Asserting that fallback is the point: it proves the diff goes
	 * through the SEO abstraction rather than reading a hardcoded Yoast meta key,
	 * which would have reported an empty "before" on a RankMath or bare site.
	 */
	public function test_seo_and_slug_report_live_values(): void {
		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array(
					'title'       => 'Proposed meta title',
					'description' => 'Proposed meta description',
					'slug'        => 'proposed-slug',
				),
			)
		);

		$this->assertSame( 'Live H1', $this->change( $result, 'meta.title' )['current'] );
		$this->assertSame( 'Proposed meta title', $this->change( $result, 'meta.title' )['proposed'] );
		$this->assertSame( 'live excerpt', $this->change( $result, 'meta.description' )['current'] );
		$this->assertSame( 'live-h1', $this->change( $result, 'meta.slug' )['current'] );
		$this->assertSame( 'seo', $this->change( $result, 'meta.title' )['kind'] );
		$this->assertSame( 'post', $this->change( $result, 'meta.slug' )['kind'] );
	}

	/**
	 * An ACF field's "before" is its stored value on the parent.
	 */
	public function test_acf_current_value_comes_from_the_parent(): void {
		global $_test_get_fields_results;

		$this->register_acf_group( 'page', array( array( 'chapo_2', 'wysiwyg' ) ) );
		$_test_get_fields_results[10] = array( 'chapo_2' => '<p>Live chapo</p>' );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_2' => '## Proposed' ) ),
				'meta' => array(),
			)
		);

		$change = $this->change( $result, 'acf_fields.chapo_2' );
		$this->assertNotNull( $change );
		$this->assertSame( '<p>Live chapo</p>', $change['current'] );
		$this->assertSame( '## Proposed', $change['proposed'], 'The proposal is reported raw, as sent.' );
		$this->assertSame( 'chapo_2', $change['label'] );
	}

	// =========================================================================
	// Transforms are named, never applied
	// =========================================================================

	/**
	 * The stored value differs from the sent value for three field shapes. A raw
	 * echo would mislead ("why is my heading literal ##?"), and coercing for real
	 * would mean importing media on a GET. Naming is the only honest option.
	 */
	public function test_each_coercion_is_named(): void {
		$this->register_acf_group(
			'page',
			array(
				array( 'chapo', 'wysiwyg' ),
				array( 'body_copy', 'wysiwyg' ),
				array( 'visuel', 'image' ),
				array( 'sous_titre', 'text' ),
			)
		);

		$result = $this->build(
			array(
				'body' => array(
					'acf_fields' => array(
						'chapo'      => '## Titre',
						'body_copy'  => null,
						'visuel'     => 'https://example.com/photo.jpg',
						'sous_titre' => 'Plain text',
					),
				),
				'meta' => array(),
			)
		);

		$this->assertSame( 'markdown_to_html', $this->change( $result, 'acf_fields.chapo' )['transform'] );
		$this->assertSame( 'copy_rendered_content', $this->change( $result, 'acf_fields.body_copy' )['transform'] );
		$this->assertSame( 'sideload_image', $this->change( $result, 'acf_fields.visuel' )['transform'] );
		$this->assertNull( $this->change( $result, 'acf_fields.sous_titre' )['transform'] );
	}

	/**
	 * Round-trip content is already HTML, so no markdown pass happens on approval
	 * and the diff must not claim one will.
	 */
	public function test_skip_markdown_suppresses_the_markdown_transform(): void {
		$this->register_acf_group( 'page', array( array( 'chapo', 'wysiwyg' ) ) );

		$result = $this->build(
			array(
				'body'          => array( 'acf_fields' => array( 'chapo' => '<h2>Already HTML</h2>' ) ),
				'meta'          => array(),
				'skip_markdown' => true,
			)
		);

		$this->assertTrue( $result['skip_markdown'] );
		$this->assertNull( $this->change( $result, 'acf_fields.chapo' )['transform'] );
	}

	/**
	 * Non-vacuity guard for the whole "named, not applied" design: building a
	 * diff over an image URL must not import anything. If a future refactor
	 * routes the diff through the write pipeline, this fails.
	 */
	public function test_building_a_diff_writes_nothing(): void {
		global $_test_acf_update_field_calls;

		$this->register_acf_group( 'page', array( array( 'visuel', 'image' ) ) );

		$this->build(
			array(
				'body' => array( 'acf_fields' => array( 'visuel' => 'https://example.com/photo.jpg' ) ),
				'meta' => array(),
			)
		);

		$this->assertSame( array(), $_test_acf_update_field_calls, 'A read path must not write fields.' );
	}

	/**
	 * The describer and the doer must agree case by case, or the screen promises
	 * one thing and approval does another.
	 */
	public function test_describer_agrees_with_the_coercion_it_describes(): void {
		$cases = array(
			array( '## T', 'wysiwyg', false ),
			array( '<h2>T</h2>', 'wysiwyg', true ),
			array( null, 'wysiwyg', false ),
			array( 'plain', 'text', false ),
			array( 0, 'image', false ),
		);

		foreach ( $cases as $case ) {
			list( $value, $type, $skip ) = $case;

			$described = $this->context->describe_field_transform( $value, $type, $skip );
			$coerced   = $this->context->coerce_field_value( $value, $type, '<p>rendered</p>', $skip, 0, false );

			if ( null === $described ) {
				$this->assertSame(
					$value,
					$coerced,
					sprintf( 'Type %s reported as verbatim but the value changed.', $type )
				);
			} else {
				$this->assertNotSame(
					$value,
					$coerced,
					sprintf( 'Type %s reported transform "%s" but the value came back untouched.', $type, $described )
				);
			}
		}
	}

	// =========================================================================
	// Calibrated writes the payload never names
	// =========================================================================

	/**
	 * Field-schema calibration writes ACF fields derived from the payload. Those
	 * writes are real and were invisible: nothing in the payload mentions the
	 * field, so nothing on screen hinted it would change.
	 */
	public function test_calibrated_fields_are_surfaced_with_their_source(): void {
		global $_test_options, $_test_get_fields_results;

		$this->register_acf_group( 'page', array( array( 'h1_affiche', 'text' ) ) );
		$_test_options['aa_field_schema'] = array(
			'page' => array( 'h1_affiche' => array( 'type' => 'mapping', 'source' => 'h1' ) ),
		);
		$_test_get_fields_results[10] = array( 'h1_affiche' => 'Ancien H1' );

		$result = $this->build(
			array(
				'body' => array( 'title' => 'Nouveau H1' ),
				'meta' => array(),
			)
		);

		$change = $this->change( $result, 'acf_fields.h1_affiche' );
		$this->assertNotNull( $change, 'A calibrated field write must be visible.' );
		$this->assertSame( 'field_schema', $change['origin'] );
		$this->assertSame( 'h1', $change['source'] );
		$this->assertSame( 'Ancien H1', $change['current'] );
		$this->assertSame( 'Nouveau H1', $change['proposed'] );
	}

	/**
	 * A field named explicitly in acf_fields is written by process_acf_fields(),
	 * not by calibration — reporting it twice would double-count the change.
	 */
	public function test_explicit_field_is_not_also_reported_as_calibrated(): void {
		global $_test_options;

		$this->register_acf_group( 'page', array( array( 'h1_affiche', 'text' ) ) );
		$_test_options['aa_field_schema'] = array(
			'page' => array( 'h1_affiche' => array( 'type' => 'mapping', 'source' => 'h1' ) ),
		);

		$result = $this->build(
			array(
				'body' => array(
					'title'      => 'Nouveau H1',
					'acf_fields' => array( 'h1_affiche' => 'Valeur explicite' ),
				),
				'meta' => array(),
			)
		);

		$entries = array_filter(
			$result['changes'],
			static function ( $c ) {
				return 'acf_fields.h1_affiche' === $c['field'];
			}
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'payload', array_values( $entries )[0]['origin'] );
	}

	// =========================================================================
	// Degenerate inputs must not break the screen the reviewer stands on
	// =========================================================================

	/**
	 * A corrupt payload is a 500 on the REST path, but the metabox renders inside
	 * the post editor — degrading to "nothing to show" beats taking the page down.
	 */
	public function test_corrupt_payload_yields_an_empty_projection(): void {
		global $_test_posts, $_test_post_meta;

		$this->seed( array( 'body' => array( 'title' => 'x' ), 'meta' => array() ) );
		$_test_post_meta[11]['_aa_revision_meta'] = '{not json';

		$diff   = new \Arcadia_Revision_Diff( $this->context );
		$result = $diff->build( $_test_posts[11] );

		$this->assertSame( array(), $result['changes'] );
		$this->assertFalse( $result['content_changed'] );
	}

	/**
	 * A revision whose parent was deleted has nothing to compare against.
	 */
	public function test_missing_parent_yields_an_empty_projection(): void {
		global $_test_posts;

		$revision = $this->seed( array( 'body' => array( 'title' => 'x' ), 'meta' => array() ) );
		unset( $_test_posts[10] );

		$diff   = new \Arcadia_Revision_Diff( $this->context );
		$result = $diff->build( $revision );

		$this->assertSame( array(), $result['changes'] );
	}

	// =========================================================================
	// Display rows — the shared payload behind both admin surfaces
	// =========================================================================

	/**
	 * Both admin surfaces render from this one list, so its shape is the contract
	 * that keeps the classic banner and the Gutenberg panel in agreement.
	 */
	public function test_display_rows_flatten_values_and_carry_the_note(): void {
		$this->register_acf_group( 'page', array( array( 'chapo', 'wysiwyg' ) ) );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'chapo' => "## Titre\n\navec   des espaces" ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$rows = $diff->to_display_rows( $result['changes'] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'chapo', $rows[0]['label'] );
		$this->assertSame( '## Titre avec des espaces', $rows[0]['proposed'], 'Whitespace is collapsed for display.' );
		$this->assertNotSame( '', $rows[0]['note'], 'A coerced value must carry its caveat.' );
	}

	/**
	 * "No value stored" and "stored, and empty" are different facts for someone
	 * deciding whether a change is destructive.
	 */
	public function test_display_rows_distinguish_null_from_empty_string(): void {
		$this->register_acf_group( 'page', array( array( 'sous_titre', 'text' ) ) );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'sous_titre' => '' ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$rows = $diff->to_display_rows( $result['changes'] );

		$this->assertSame( '—', $rows[0]['current'], 'Parent has no value for this field.' );
		$this->assertSame( '', $rows[0]['proposed'] );
	}

	/**
	 * Long values are truncated before they reach a metabox.
	 */
	public function test_display_rows_truncate_long_values(): void {
		$this->register_acf_group( 'page', array( array( 'sous_titre', 'text' ) ) );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'sous_titre' => str_repeat( 'a', 900 ) ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$rows = $diff->to_display_rows( $result['changes'] );

		$this->assertLessThanOrEqual(
			\Arcadia_Revision_Diff::DISPLAY_LIMIT,
			mb_strlen( $rows[0]['proposed'] )
		);
	}

	/**
	 * Term lists render as readable text rather than "Array".
	 */
	public function test_display_rows_render_taxonomy_lists(): void {
		global $_test_post_categories;
		$_test_post_categories[10] = array( 'Actu' );

		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array( 'categories' => array( 'Guide', 'Fiscalité' ) ),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$rows = $diff->to_display_rows( $result['changes'] );

		$this->assertSame( 'Actu', $rows[0]['current'] );
		$this->assertSame( 'Guide, Fiscalité', $rows[0]['proposed'] );
	}
}
