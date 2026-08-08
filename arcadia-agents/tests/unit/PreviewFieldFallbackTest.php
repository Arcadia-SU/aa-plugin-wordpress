<?php
/**
 * Tests for Phase 43.3 — a revision preview must render a whole page.
 *
 * The preview loop yields the revision post, and a revision carries only
 * post_title and post_content: its proposal lives as JSON in _aa_revision_meta
 * and is replayed onto the parent only at approval. So every get_field() in the
 * theme resolved against a post with no fields and rendered nothing — a page
 * measured at 72KB live came back as 11KB, zero paragraphs, no header, no
 * footer. The preview was rendering a delta as if it were a whole page.
 *
 * The fix is a read-only overlay on get_post_metadata with one rule: the
 * proposal wins where it has an opinion, the parent shows through everywhere
 * else. These tests pin that rule and the three ways it could go wrong —
 * leaking plugin bookkeeping, dropping ACF's field-key pairs, and missing the
 * bulk-read path that ACF uses to prime its cache.
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
 * Field context stand-in holding the coercion pipeline.
 */
class PreviewOverlayContext {
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
 * Test class for the preview field overlay.
 */
class PreviewFieldFallbackTest extends TestCase {

	private $context;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_acf_field_groups,
			$_test_acf_fields_by_group, $_test_acf_update_field_calls, $_test_filters;

		$_test_options                = array();
		$_test_posts                  = array();
		$_test_post_meta              = array();
		$_test_acf_field_groups       = array();
		$_test_acf_fields_by_group    = array();
		$_test_acf_update_field_calls = array();
		$_test_filters                = array();

		$this->context = new PreviewOverlayContext();
	}

	protected function tearDown(): void {
		// The overlay is a filter; leaving it armed would poison every later test.
		remove_all_filters( 'get_post_metadata' );
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
	 * Seed parent + revision, then arm the overlay.
	 *
	 * @param array  $payload      Stored _aa_revision_meta payload.
	 * @param array  $parent_meta  Meta stored on the live post.
	 * @param array  $rev_meta     Extra meta stored on the revision itself.
	 * @param string $rev_content  The revision's own post_content. '' models a PUT
	 *                             that proposed fields but no page content.
	 */
	private function arm( array $payload, array $parent_meta = array(), array $rev_meta = array(), $rev_content = '<p>proposed content</p>' ) {
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
			'post_content'   => $rev_content,
			'post_excerpt'   => '',
			'post_author'    => 1,
			'post_date'      => '2026-08-02 10:00:00',
			'post_modified'  => '2026-08-02 10:00:00',
			'post_mime_type' => '',
		);

		$_test_post_meta[10] = array_merge(
			array( '_aa_preview_token' => 'parent-secret-token' ),
			$parent_meta
		);

		$_test_post_meta[11] = array_merge(
			array(
				'_aa_revision_version' => 1,
				'_aa_revision_meta'    => wp_json_encode( $payload ),
			),
			$rev_meta
		);

		\Arcadia_Preview::get_instance()->install_field_overlay(
			$_test_posts[11],
			$_test_posts[10],
			$this->context
		);
	}

	// =========================================================================
	// The one rule
	// =========================================================================

	/**
	 * The whole defect in one assertion: a field the revision does not propose
	 * must still render, with the parent's published value.
	 */
	public function test_unproposed_field_falls_back_to_the_parent(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array( 'body' => array( 'acf_fields' => array() ), 'meta' => array() ),
			array( 'chapo_1' => '<p>Chapo publié</p>' )
		);

		$this->assertSame( '<p>Chapo publié</p>', get_post_meta( 11, 'chapo_1', true ) );
	}

	/**
	 * ...and a field it does propose renders the proposal, not the old value.
	 */
	public function test_proposed_field_wins_over_the_parent(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => 'Nouveau chapo' ) ),
				'meta' => array(),
			),
			array( 'chapo_1' => '<p>Chapo publié</p>' )
		);

		$this->assertStringContainsString( 'Nouveau chapo', get_post_meta( 11, 'chapo_1', true ) );
		$this->assertStringNotContainsString( 'Chapo publié', get_post_meta( 11, 'chapo_1', true ) );
	}

	/**
	 * A proposed wysiwyg value is markdown; the preview must show what the reader
	 * will see after approval, not the raw source. Reuses the write path's own
	 * coercion, so preview and approval cannot disagree.
	 */
	public function test_proposed_markdown_is_rendered_not_shown_raw(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => '## Titre proposé' ) ),
				'meta' => array(),
			)
		);

		$rendered = get_post_meta( 11, 'chapo_1', true );
		$this->assertStringContainsString( '<h2>', $rendered );
		$this->assertStringNotContainsString( '## ', $rendered );
	}

	/**
	 * `wysiwyg: null` means "take the proposed page content" — the same rule the
	 * write path applies, so the preview shows the same thing approval will store.
	 */
	public function test_null_wysiwyg_takes_the_proposed_content(): void {
		$this->register_acf_group( 'page', array( array( 'corps', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'corps' => null ) ),
				'meta' => array(),
			)
		);

		$this->assertSame( '<p>proposed content</p>', get_post_meta( 11, 'corps', true ) );
	}

	// =========================================================================
	// The ways this could go wrong
	// =========================================================================

	/**
	 * ACF stores every field as a pair — `name` and `_name` holding the field key.
	 * Return only one and ACF resolves the field type wrong, so a correct value
	 * still renders wrong. A generic key-by-key rule carries both; this asserts
	 * the rule really is generic and not a list of field names.
	 */
	public function test_the_acf_field_key_pair_falls_back_too(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => 'Nouveau' ) ),
				'meta' => array(),
			),
			array(
				'chapo_1'  => '<p>Ancien</p>',
				'_chapo_1' => 'field_chapo_1',
			)
		);

		$this->assertSame( 'field_chapo_1', get_post_meta( 11, '_chapo_1', true ) );
	}

	/**
	 * Plugin bookkeeping must never inherit. A parent's preview token resolving
	 * on the revision is a security defect, not a convenience — the token is what
	 * authorises viewing an unpublished page.
	 */
	public function test_plugin_internal_meta_never_inherits(): void {
		$this->arm( array( 'body' => array(), 'meta' => array() ) );

		$this->assertSame( '', get_post_meta( 11, '_aa_preview_token', true ) );
	}

	/**
	 * The revision's own bookkeeping keeps answering for itself.
	 */
	public function test_revision_keeps_its_own_meta(): void {
		$this->arm( array( 'body' => array(), 'meta' => array() ) );

		$this->assertSame( 1, get_post_meta( 11, '_aa_revision_version', true ) );
	}

	/**
	 * A key the revision genuinely carries wins over the parent's — the overlay
	 * fills gaps, it does not overwrite.
	 */
	public function test_revision_own_value_beats_the_parent(): void {
		$this->arm(
			array( 'body' => array(), 'meta' => array() ),
			array( 'shared_key' => 'parent value' ),
			array( 'shared_key' => 'revision value' )
		);

		$this->assertSame( 'revision value', get_post_meta( 11, 'shared_key', true ) );
	}

	/**
	 * ACF primes its cache with one bulk read. Answer only single-key reads and
	 * the fallback is invisible on that path — half the fields render empty
	 * anyway, which would have looked like the bug was only half fixed.
	 */
	public function test_bulk_read_is_merged(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => 'Nouveau chapo' ) ),
				'meta' => array(),
			),
			array(
				'chapo_1'   => '<p>Ancien</p>',
				'autre_champ' => 'valeur parente',
			)
		);

		$all = get_post_meta( 11 );

		$this->assertArrayHasKey( 'autre_champ', $all, 'Parent keys must show through in bulk.' );
		$this->assertArrayHasKey( 'chapo_1', $all );
		$this->assertArrayHasKey( '_aa_revision_meta', $all, "The revision's own meta stays." );
		$this->assertArrayNotHasKey( '_aa_preview_token', $all, 'Parent bookkeeping must not leak in bulk either.' );
		$this->assertStringContainsString( 'Nouveau chapo', $all['chapo_1'][0] );
	}

	/**
	 * An image proposed as a URL has no attachment ID yet, and building one means
	 * importing a file. A page render is not allowed to create media, so the
	 * field shows the parent's current image and the diff announces the change.
	 */
	public function test_image_proposed_as_url_falls_back_to_the_parent(): void {
		$this->register_acf_group( 'page', array( array( 'visuel', 'image' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'visuel' => 'https://example.com/new.jpg' ) ),
				'meta' => array(),
			),
			array( 'visuel' => 4242 )
		);

		$this->assertSame( 4242, get_post_meta( 11, 'visuel', true ) );
	}

	/**
	 * Non-vacuity guard for the read-only invariant: rendering a preview must not
	 * write a single field, however tempting it is to resolve values by replaying
	 * the write path.
	 */
	public function test_arming_the_overlay_writes_nothing(): void {
		global $_test_acf_update_field_calls;

		$this->register_acf_group( 'page', array( array( 'visuel', 'image' ), array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array(
					'acf_fields' => array(
						'visuel'  => 'https://example.com/new.jpg',
						'chapo_1' => '## Titre',
					),
				),
				'meta' => array(),
			)
		);

		get_post_meta( 11, 'chapo_1', true );

		$this->assertSame( array(), $_test_acf_update_field_calls );
	}

	// =========================================================================
	// The preview must show what approval would produce, not something else
	// =========================================================================

	/**
	 * `wysiwyg: null` means "copy the page content into this field". When the
	 * revision proposes no content of its own, approval copies the LIVE post's
	 * content (finalize_post falls back to it) — so the preview must too.
	 *
	 * Reading only the revision's post_content copied '' into the field: the
	 * preview blanked a field that approval would have preserved, and a reviewer
	 * looking at an empty block rejects a revision that was correct. That is the
	 * exact failure Phase 43.3 set out to end, reintroduced one level down.
	 */
	public function test_wysiwyg_null_copies_live_content_when_the_revision_proposes_none(): void {
		$this->register_acf_group( 'page', array( array( 'corps', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'corps' => null ) ),
				'meta' => array(),
			),
			array( 'corps' => '<p>Ancien corps</p>' ),
			array(),
			'' // The PUT touched fields only; no content was proposed.
		);

		$this->assertSame(
			'<p>live content</p>',
			get_post_meta( 11, 'corps', true ),
			'Mirror finalize_post(): no proposed content means the live content is copied.'
		);
	}

	/**
	 * The revision's own content still wins when it has some.
	 */
	public function test_wysiwyg_null_copies_the_proposed_content_when_there_is_some(): void {
		$this->register_acf_group( 'page', array( array( 'corps', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'corps' => null ) ),
				'meta' => array(),
			),
			array( 'corps' => '<p>Ancien corps</p>' )
		);

		$this->assertSame( '<p>proposed content</p>', get_post_meta( 11, 'corps', true ) );
	}

	/**
	 * A repeater is not stored under its own name — ACF keeps a row count there and
	 * one meta key per sub-field per row. Handing back the payload array made ACF
	 * read intval(array) = 1 and render a single row filled from the PARENT's
	 * sub-field keys: neither the current page nor the proposal, but a chimera of
	 * both. Showing the parent's real rows is the honest fallback; the diff is what
	 * announces the change.
	 */
	public function test_structured_fields_fall_back_to_the_parent(): void {
		$this->register_acf_group(
			'page',
			array(
				array( 'blocs', 'repeater' ),
				array( 'groupe', 'group' ),
				array( 'flex', 'flexible_content' ),
				array( 'chapo_1', 'wysiwyg' ),
			)
		);

		$this->arm(
			array(
				'body' => array(
					'acf_fields' => array(
						'blocs'   => array( array( 'titre' => 'A' ), array( 'titre' => 'B' ) ),
						'groupe'  => array( 'titre' => 'G' ),
						'flex'    => array( array( 'acf_fc_layout' => 'x' ) ),
						'chapo_1' => 'Nouveau',
					),
				),
				'meta' => array(),
			),
			array(
				'blocs'         => 3,
				'blocs_0_titre' => 'Rangée live',
				'groupe_titre'  => 'Groupe live',
				'flex'          => 2,
				'chapo_1'       => '<p>Ancien</p>',
			)
		);

		$this->assertSame( 3, get_post_meta( 11, 'blocs', true ), 'Row count stays the parent\'s.' );
		$this->assertSame( 'Rangée live', get_post_meta( 11, 'blocs_0_titre', true ) );
		$this->assertSame( 'Groupe live', get_post_meta( 11, 'groupe_titre', true ) );
		$this->assertSame( 2, get_post_meta( 11, 'flex', true ) );

		// Non-vacuity: a scalar field in the same payload still gets overlaid, so
		// the assertions above are about the field type and not a dead overlay.
		$this->assertSame( '<p>Nouveau</p>', get_post_meta( 11, 'chapo_1', true ) );
	}

	// =========================================================================
	// Scope
	// =========================================================================

	/**
	 * A read addressed to the parent still belongs to the preview — but only for
	 * the fields the revision actually proposes.
	 *
	 * The overlay was first keyed to the revision ID alone. That looked right and
	 * was the most dangerous shape available: setup_preview_state() points
	 * queried_object/queried_object_id at the PARENT, so every theme calling
	 * get_field( 'x', get_queried_object_id() ) addressed the live post and got
	 * live values. The page rendered full, well-formed and stale, with nothing on
	 * screen to suggest the proposal had been ignored.
	 *
	 * Everything the revision does NOT propose must still read through untouched:
	 * the parent remains the source of truth, and its own bookkeeping is never
	 * rewritten.
	 */
	public function test_parent_reads_carry_the_proposal_and_nothing_else(): void {
		$this->register_acf_group(
			'page',
			array( array( 'chapo_1', 'wysiwyg' ), array( 'chapo_2', 'wysiwyg' ) )
		);

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => 'Nouveau' ) ),
				'meta' => array(),
			),
			array(
				'chapo_1' => '<p>Ancien</p>',
				'chapo_2' => '<p>Intouché</p>',
			)
		);

		$this->assertSame(
			'<p>Nouveau</p>',
			get_post_meta( 10, 'chapo_1', true ),
			'A theme reading off the queried object must see the proposal, not the live value.'
		);
		$this->assertSame(
			'<p>Intouché</p>',
			get_post_meta( 10, 'chapo_2', true ),
			'A field the revision does not propose is the parent\'s, unchanged.'
		);
		$this->assertSame(
			'parent-secret-token',
			get_post_meta( 10, '_aa_preview_token', true ),
			'The parent\'s own bookkeeping is never rewritten.'
		);
	}

	/**
	 * Non-vacuity for the rule above: the parent branch answers proposed keys only,
	 * so a bulk read of the parent is not turned into the revision's merged view.
	 */
	public function test_parent_bulk_read_is_not_hijacked(): void {
		$this->register_acf_group( 'page', array( array( 'chapo_1', 'wysiwyg' ) ) );

		$this->arm(
			array(
				'body' => array( 'acf_fields' => array( 'chapo_1' => 'Nouveau' ) ),
				'meta' => array(),
			),
			array( 'chapo_1' => '<p>Ancien</p>' )
		);

		$all = get_post_meta( 10 );

		$this->assertArrayHasKey( '_aa_preview_token', $all );
		$this->assertSame( array( 'parent-secret-token' ), $all['_aa_preview_token'] );
	}

	/**
	 * Previewing an ordinary post (not a revision) installs nothing at all.
	 */
	public function test_no_overlay_for_a_non_revision_post(): void {
		global $_test_posts, $_test_post_meta;

		$_test_posts[20] = (object) array(
			'ID'             => 20,
			'post_type'      => 'page',
			'post_title'     => 'Ordinary',
			'post_name'      => 'ordinary',
			'post_status'    => 'draft',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_author'    => 1,
			'post_parent'    => 0,
			'post_date'      => '2026-08-01 10:00:00',
			'post_modified'  => '2026-08-01 10:00:00',
			'post_mime_type' => '',
		);
		$_test_post_meta[20] = array();

		\Arcadia_Preview::get_instance()->install_field_overlay(
			$_test_posts[20],
			$_test_posts[20],
			$this->context
		);

		$this->assertFalse( has_filter( 'get_post_metadata' ) );
	}

	/**
	 * Calibrated fields are written on approval too, so the preview has to show
	 * them — otherwise the page a reviewer approves is not the page they saw.
	 */
	public function test_calibrated_fields_are_overlaid(): void {
		global $_test_options;

		$this->register_acf_group( 'page', array( array( 'h1_affiche', 'text' ) ) );
		$_test_options['aa_field_schema'] = array(
			'page' => array( 'h1_affiche' => array( 'type' => 'mapping', 'source' => 'h1' ) ),
		);

		$this->arm(
			array(
				'body' => array( 'title' => 'Titre proposé' ),
				'meta' => array(),
			),
			array( 'h1_affiche' => 'Ancien titre' )
		);

		$this->assertSame( 'Titre proposé', get_post_meta( 11, 'h1_affiche', true ) );
	}
}
