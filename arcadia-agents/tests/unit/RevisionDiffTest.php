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
	 * ...and everywhere else the writer gates on ! empty(), so does the diff.
	 *
	 * `excerpt` above is the exception, not the rule: build_post_data() and
	 * approve_revision() gate title, slug, SEO title/description, taxonomies and
	 * the featured image on ! empty(). Listing those as proposed when they are
	 * empty promises a change approval silently skips — the reviewer clicks
	 * Approve, nothing moves, and no message explains why. Each gate below is
	 * pinned against the writer it mirrors.
	 *
	 * @dataProvider provide_empty_values_the_writer_ignores
	 *
	 * @param array $payload Stored revision payload carrying only empty values.
	 */
	public function test_values_the_writer_would_skip_are_not_listed( array $payload ): void {
		$result = $this->build( $payload );

		$this->assertSame(
			array(),
			$this->fields( $result ),
			'A gate that is looser than the writer\'s turns the diff into a false promise.'
		);
	}

	/**
	 * One case per writer gate. Kept as data rather than one big test so a failure
	 * names the field that drifted.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function provide_empty_values_the_writer_ignores(): array {
		return array(
			'title: ""'                => array( array( 'body' => array( 'title' => '' ), 'meta' => array() ) ),
			'meta.slug: ""'            => array( array( 'body' => array(), 'meta' => array( 'slug' => '' ) ) ),
			'meta.title: ""'           => array( array( 'body' => array(), 'meta' => array( 'title' => '' ) ) ),
			'meta.description: ""'     => array( array( 'body' => array(), 'meta' => array( 'description' => '' ) ) ),
			'categories: []'           => array( array( 'body' => array(), 'meta' => array( 'categories' => array() ) ) ),
			'tags: []'                 => array( array( 'body' => array(), 'meta' => array( 'tags' => array() ) ) ),
			'featured_image_url: ""'   => array( array( 'body' => array(), 'meta' => array( 'featured_image_url' => '' ) ) ),
			'alt without an image url' => array( array( 'body' => array(), 'meta' => array( 'featured_image_alt' => 'Texte alternatif' ) ) ),
		);
	}

	/**
	 * The gate is per field, not per group. `{title: "", description: "…"}` clears
	 * the early return but must still drop the empty title: finalize_post() gates
	 * the two writes independently.
	 */
	public function test_an_empty_seo_field_is_dropped_beside_a_filled_one(): void {
		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array( 'title' => '', 'description' => 'Une description' ),
			)
		);

		$this->assertSame( array( 'meta.description' ), $this->fields( $result ) );
	}

	/**
	 * The alt text is not written on its own: finalize_post() passes it as an
	 * argument to the featured-image sideload, inside the `! empty( url )` branch.
	 * It is listed only alongside the URL that carries it.
	 */
	public function test_featured_image_alt_is_listed_only_with_its_url(): void {
		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array(
					'featured_image_url' => 'https://example.com/hero.jpg',
					'featured_image_alt' => 'Une vue du bâtiment',
				),
			)
		);

		$this->assertSame(
			array( 'meta.featured_image_url', 'meta.featured_image_alt' ),
			$this->fields( $result )
		);
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
	 * SEO fields read the cell approval will overwrite; slug reads the post row.
	 *
	 * The "before" is deliberately NOT get_seo_meta()'s display view. With no SEO
	 * plugin installed that view falls back to post_title / post_excerpt, so this
	 * row used to read "current: Live H1" — making it look as though approving
	 * would replace the H1, when approval writes an SEO meta key and leaves
	 * post_title alone. A diff compares against the cell being overwritten.
	 */
	public function test_seo_and_slug_report_live_values(): void {
		global $_test_post_meta;

		$this->seed( array( 'body' => array(), 'meta' => array() ) );
		$_test_post_meta[10] = array(
			'_yoast_wpseo_title'    => 'Stored meta title',
			'_yoast_wpseo_metadesc' => 'Stored meta description',
		);

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

		$this->assertSame( 'Stored meta title', $this->change( $result, 'meta.title' )['current'] );
		$this->assertSame( 'Proposed meta title', $this->change( $result, 'meta.title' )['proposed'] );
		$this->assertSame( 'Stored meta description', $this->change( $result, 'meta.description' )['current'] );
		$this->assertNotSame(
			'Live H1',
			$this->change( $result, 'meta.title' )['current'],
			'The H1 is not the SEO title, and a diff that shows it there invites the wrong decision.'
		);
		$this->assertSame( 'live-h1', $this->change( $result, 'meta.slug' )['current'] );
		$this->assertSame( 'seo', $this->change( $result, 'meta.title' )['kind'] );
		$this->assertSame( 'post', $this->change( $result, 'meta.slug' )['kind'] );
	}

	/**
	 * Read and write must name the same meta key, whichever SEO plugin is active.
	 *
	 * On a Rank Math site the diff used to read `rank_math_title` (through
	 * get_seo_meta) while finalize_post() wrote `_yoast_wpseo_title`: the row
	 * promised a change, approval wrote to a key Rank Math never displays, and the
	 * page came back looking untouched with no error anywhere.
	 */
	public function test_seo_write_and_diff_read_the_same_key(): void {
		global $_test_post_meta;

		$this->seed( array( 'body' => array(), 'meta' => array() ) );

		$keys = \Arcadia_SEO_Meta::storage_keys();

		// Stand in for the one write finalize_post() makes for meta.title.
		update_post_meta( 10, $keys['meta_title'], 'Written by approval' );

		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array( 'title' => 'Next meta title' ),
			)
		);

		$this->assertSame(
			'Written by approval',
			$this->change( $result, 'meta.title' )['current'],
			'The diff must read back exactly the key the writer wrote.'
		);

		// Non-vacuity: the round trip above only proves anything because the key is
		// a real one and nothing else on the parent carries the value.
		$this->assertArrayHasKey( $keys['meta_title'], $_test_post_meta[10] );
		$this->assertSame(
			array( 'meta_title', 'meta_description' ),
			array_keys( $keys ),
			'Both writers (post builder, revision approval) index this array by these two names.'
		);
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
	 * Round-trip content is already HTML: no markdown pass runs, but sanitisation
	 * still does — and the diff must say so rather than imply "stored as sent".
	 *
	 * parse_rich( $value, true ) is wp_kses_post(): iframes, scripts and forms are
	 * dropped. Reporting null here told the reviewer nothing would happen to the
	 * value while an embed was being removed on approval.
	 */
	public function test_skip_markdown_reports_sanitisation_not_verbatim(): void {
		$this->register_acf_group( 'page', array( array( 'chapo', 'wysiwyg' ) ) );

		$result = $this->build(
			array(
				'body'          => array( 'acf_fields' => array( 'chapo' => '<h2>Already HTML</h2>' ) ),
				'meta'          => array(),
				'skip_markdown' => true,
			)
		);

		$this->assertTrue( $result['skip_markdown'] );
		$this->assertSame( 'sanitize_html', $this->change( $result, 'acf_fields.chapo' )['transform'] );
		$this->assertNotSame(
			'markdown_to_html',
			$this->change( $result, 'acf_fields.chapo' )['transform'],
			'No markdown pass happens on a round trip.'
		);
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
	 *
	 * Every probe value is chosen so the coercion it should trigger visibly changes
	 * it. That is not decoration: the first version of this test used
	 * `<h2>T</h2>` for the skip_markdown case, which wp_kses_post() lets through
	 * untouched — so the case passed while describe_field_transform() wrongly
	 * reported "no transform", and a stripped iframe went unannounced. A lockstep
	 * test whose probes survive the transform proves nothing about the transform.
	 */
	public function test_describer_agrees_with_the_coercion_it_describes(): void {
		$cases = array(
			// value, field type, skip_markdown, expected transform.
			array( '## T', 'wysiwyg', false, 'markdown_to_html' ),
			array( '<p>ok</p><iframe src="https://x/"></iframe>', 'wysiwyg', true, 'sanitize_html' ),
			array( null, 'wysiwyg', false, 'copy_rendered_content' ),
			array( 'https://example.com/p.jpg', 'image', false, 'sideload_image' ),
			array( 'plain', 'text', false, null ),
			array( 0, 'image', false, null ),
		);

		foreach ( $cases as $case ) {
			list( $value, $type, $skip, $expected ) = $case;

			$described = $this->context->describe_field_transform( $value, $type, $skip );
			$this->assertSame( $expected, $described, sprintf( 'Wrong transform named for type %s.', $type ) );

			// allow_sideload = false, so an image comes back raw by contract; the
			// describer having said 'sideload_image' is what the preview keys off.
			$coerced = $this->context->coerce_field_value( $value, $type, '<p>rendered</p>', $skip, 0, false );

			if ( null === $described || 'sideload_image' === $described ) {
				$this->assertSame(
					$value,
					$coerced,
					sprintf( 'Type %s reported "%s" but the read-only coercion changed the value.', $type, (string) $described )
				);
				continue;
			}

			$this->assertNotSame(
				$value,
				$coerced,
				sprintf( 'Type %s reported transform "%s" but the value came back untouched.', $type, $described )
			);
		}
	}

	/**
	 * The sanitisation named above is real, and it is the dangerous kind: content
	 * disappears rather than erroring.
	 */
	public function test_round_trip_sanitisation_actually_strips_embeds(): void {
		$coerced = $this->context->coerce_field_value(
			'<p>Texte</p><iframe src="https://player.example/1"></iframe>',
			'wysiwyg',
			'',
			true,
			0,
			false
		);

		$this->assertStringNotContainsString( '<iframe', $coerced );
		$this->assertStringContainsString( '<p>Texte</p>', $coerced );
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

	/**
	 * Content migrated from a latin1 database is not valid UTF-8, and preg_replace
	 * with /u returns NULL — not the subject — on such input. Unguarded, that null
	 * reached trim() and the Current cell rendered blank: the reviewer reads "this
	 * field is empty", approves, and overwrites text that was there all along.
	 */
	public function test_display_rows_survive_invalid_utf8_in_the_current_value(): void {
		global $_test_get_fields_results;

		$this->register_acf_group( 'page', array( array( 'chapo', 'text' ) ) );

		// A lone 0xE9 byte: "é" as latin1, invalid as UTF-8.
		$_test_get_fields_results[10] = array( 'chapo' => "Propri\xE9taire   du   lot" );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'chapo' => 'Propriétaire du lot' ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$rows = $diff->to_display_rows( $result['changes'] );

		$this->assertNotSame( '', $rows[0]['current'], 'A blank Current cell here invites an accidental overwrite.' );
		// Collapsing still happens, byte-wise. Asserting the *content* survives is
		// not enough: simply handing the subject back on failure would pass that,
		// and then the cell reads differently from every other row on the screen.
		// (The bad byte itself comes out as mbstring's substitution character —
		// that is mb_strimwidth's business, not ours, so it is not asserted.)
		$this->assertStringContainsString( 'taire du lot', $rows[0]['current'] );
		$this->assertStringNotContainsString( '  ', $rows[0]['current'] );
	}

	// =========================================================================
	// The REST detail surface
	// =========================================================================

	/**
	 * An ACF post_object / relationship / user field set to "return object" makes
	 * get_fields() hand back WP_Post or WP_User instances. Serialising those into
	 * a JSON response ships post_password, the whole post_content, user_email and
	 * user_login to anyone holding a read scope. A diff needs a reference, not a
	 * record.
	 */
	public function test_api_changes_never_serialise_a_wp_object(): void {
		global $_test_get_fields_results;

		$this->register_acf_group( 'page', array( array( 'auteur', 'post_object' ) ) );

		$_test_get_fields_results[10] = array(
			'auteur' => (object) array(
				'ID'            => 77,
				'post_title'    => 'Fiche privée',
				'post_password' => 'hunter2',
			),
		);

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'auteur' => 78 ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$api  = $diff->to_api_changes( $result['changes'] );

		$this->assertSame( 77, $api[0]['current']['id'] );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $api ) );
		$this->assertStringNotContainsString( 'post_password', wp_json_encode( $api ) );
	}

	/**
	 * A page whose editorial lives in a dozen wysiwyg fields must not turn one
	 * revision detail into hundreds of KB. Clipping is flagged, never silent — a
	 * consumer diffing values has to know it received a prefix.
	 */
	public function test_api_changes_bound_the_current_value_and_say_so(): void {
		global $_test_get_fields_results;

		$this->register_acf_group( 'page', array( array( 'corps', 'wysiwyg' ) ) );
		$_test_get_fields_results[10] = array( 'corps' => str_repeat( 'a', 20000 ) );

		$result = $this->build(
			array(
				'body' => array( 'acf_fields' => array( 'corps' => 'court' ) ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$api  = $diff->to_api_changes( $result['changes'] );

		$this->assertLessThanOrEqual( \Arcadia_Revision_Diff::API_VALUE_LIMIT, strlen( $api[0]['current'] ) );
		$this->assertTrue( $api[0]['current_truncated'] );
		$this->assertSame(
			'court',
			$api[0]['proposed'],
			'The proposal is the caller\'s own payload and api-contract promises it verbatim.'
		);
	}

	/**
	 * Non-vacuity for the flag: an ordinary value is not reported as truncated.
	 */
	public function test_api_changes_do_not_flag_short_values(): void {
		$result = $this->build(
			array(
				'body' => array( 'title' => 'Proposed H1' ),
				'meta' => array(),
			)
		);

		$diff = new \Arcadia_Revision_Diff( $this->context );
		$api  = $diff->to_api_changes( $result['changes'] );

		$this->assertFalse( $api[0]['current_truncated'] );
		$this->assertSame( 'Live H1', $api[0]['current'] );
	}

	/**
	 * A calibrated image value is sideloaded whatever its string shape, and the
	 * diff says so — because both now ask coerce_field_value()'s describer.
	 *
	 * The rule used to be spelled three different ways: filter_var(VALIDATE_URL)
	 * in the field-schema writer, the same filter_var copied into the diff, and
	 * "any non-empty string" in describe_field_transform() (which the preview
	 * reads). A protocol-relative URL therefore got three answers — the diff said
	 * "no transform", the writer stored the raw string into an ACF image field,
	 * and the field came out broken on the live page.
	 *
	 * @dataProvider provide_image_value_shapes
	 *
	 * @param string $url An image value a calibration may resolve to.
	 */
	public function test_every_calibrated_image_string_is_announced_as_a_sideload( string $url ): void {
		global $_test_options;

		$_test_options['aa_field_schema'] = array(
			'page' => array( 'visuel' => array( 'type' => 'mapping', 'source' => 'featured_image_url' ) ),
		);
		$this->register_acf_group( 'page', array( array( 'visuel', 'image' ) ) );

		$result = $this->build(
			array(
				'body' => array(),
				'meta' => array( 'featured_image_url' => $url ),
			)
		);

		$this->assertSame( 'sideload_image', $this->change( $result, 'acf_fields.visuel' )['transform'] );
		$this->assertSame( 'field_schema', $this->change( $result, 'acf_fields.visuel' )['origin'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_image_value_shapes(): array {
		return array(
			'absolute url'         => array( 'https://cdn.example.com/hero.jpg' ),
			'protocol-relative'    => array( '//cdn.example.com/hero.jpg' ),
			'root-relative path'   => array( '/wp-content/uploads/hero.jpg' ),
		);
	}

	/**
	 * ...and the writer honours the same rule: an ACF image field never receives a
	 * raw string, whatever shape it came in.
	 *
	 * The old filter_var(VALIDATE_URL) gate let `//cdn/hero.jpg` past as "not a URL,
	 * write it verbatim", so update_field() stored a string where ACF expects an
	 * attachment ID. Routing through coerce_field_value() means the value is either
	 * an imported attachment or nothing at all — a logged failure beats a silently
	 * broken field.
	 */
	public function test_calibrated_image_is_never_written_as_a_raw_string(): void {
		global $_test_options, $_test_acf_update_field_calls;

		$_test_options['aa_field_schema'] = array(
			'page' => array(
				'visuel'     => array( 'type' => 'mapping', 'source' => 'featured_image_url' ),
				'sous_titre' => array( 'type' => 'mapping', 'source' => 'meta_title' ),
			),
		);
		$this->register_acf_group(
			'page',
			array( array( 'visuel', 'image' ), array( 'sous_titre', 'text' ) )
		);

		$this->context->apply_field_schema_mappings(
			10,
			'page',
			array(),
			array(
				'featured_image_url' => '//cdn.example.com/hero.jpg',
				'title'              => 'Titre calibré',
			)
		);

		$written = array_column( $_test_acf_update_field_calls, 'value', 'field_name' );

		$this->assertArrayNotHasKey(
			'visuel',
			$written,
			'A schemeless URL cannot be imported, so nothing is written — never the raw string.'
		);
		$this->assertSame(
			'Titre calibré',
			$written['sous_titre'] ?? null,
			'Non-vacuity: the same call does write the text field, so the assertion above is about the image rule.'
		);
	}

	// =========================================================================
	// One selection, two consumers
	// =========================================================================

	/**
	 * The writer writes exactly the fields the resolver resolves — no more, no
	 * fewer, and under no extra condition of its own.
	 *
	 * This is what makes every gate in resolve_field_schema_mappings() apply to
	 * the diff and the preview as well as to approval. The ACF-availability check
	 * used to sit in apply_field_schema_mappings() alone, so on a site without ACF
	 * the reader still announced calibrated writes that could never happen. Moving
	 * it into the resolver only helps if this coupling holds — hence the test.
	 *
	 * (The `! function_exists( 'update_field' )` branch itself cannot be exercised
	 * here: the bootstrap defines the stub unconditionally, and undefining a PHP
	 * function is not possible. The coupling below is what the fix rests on.)
	 */
	public function test_calibrated_writes_are_exactly_the_resolved_fields(): void {
		global $_test_options, $_test_acf_update_field_calls;

		$_test_options['aa_field_schema'] = array(
			'page' => array(
				'sous_titre'   => array( 'type' => 'mapping', 'source' => 'meta_title' ),
				'accroche'     => array( 'type' => 'mapping', 'source' => 'meta_description' ),
				'jamais_ecrit' => array( 'type' => 'mapping', 'source' => 'featured_image_url' ),
			),
		);

		$this->register_acf_group(
			'page',
			array(
				array( 'sous_titre', 'text' ),
				array( 'accroche', 'text' ),
				array( 'jamais_ecrit', 'text' ),
			)
		);

		$body = array();
		// No featured_image_url, so `jamais_ecrit` resolves to nothing on both sides.
		$meta = array( 'title' => 'Meta title', 'description' => 'Meta description' );

		$resolved = $this->context->resolve_field_schema_mappings( 'page', $body, $meta );
		$this->context->apply_field_schema_mappings( 10, 'page', $body, $meta );

		$written = array_column( $_test_acf_update_field_calls, 'field_name' );

		$this->assertSame( array( 'sous_titre', 'accroche' ), array_keys( $resolved ) );
		$this->assertSame( array_keys( $resolved ), $written );
		$this->assertNotContains( 'jamais_ecrit', $written, 'Non-vacuity: the fixture does hold a field neither side picks.' );
	}
}
