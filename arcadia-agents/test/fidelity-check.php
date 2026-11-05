<?php
/**
 * Real-WordPress fidelity check for the wp_slash data-corruption bug class.
 *
 * The unit suite uses hand-mocks; this script runs against a LIVE WordPress so
 * the behaviour comes from the real wp_insert_post / wp_unslash / parse_blocks /
 * update_post_meta stack — the only place the wp_slash bug actually manifests.
 *
 * It drives an adversarial corpus (\r\n, escaped quotes, backslashes, the
 * comment-breakout sequence -->, <!-- , unicode, emoji) through the plugin's
 * write paths, reads the data back from the database, and asserts byte-for-byte
 * fidelity. If any wp_slash() were removed, an escape would be stripped at
 * storage time and one of these assertions would fail.
 *
 * Usage (in the Docker container):
 *   docker compose exec -T wordpress bash -c \
 *     "cd /var/www/html/wp-content/plugins/arcadia-agents && php test/fidelity-check.php"
 *
 * Exit: 0 = all fidelity checks pass, 1 = corruption detected.
 *
 * @package ArcadiaAgents
 */

// ─── Load WordPress ─────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
	$candidates = array(
		'/var/www/html/wp-load.php',
		dirname( __DIR__ ) . '/../../../wp-load.php',
	);
	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			require_once $candidate;
			break;
		}
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Error: could not load WordPress (wp-load.php not found).\n" );
	exit( 1 );
}

// Run as an administrator so post_content is stored with unfiltered_html, exactly
// as the plugin's write_post() does (it sets the author user for this reason).
// Without it, WordPress KSES entity-encodes the comment markup — a filtering
// artifact unrelated to the wp_slash bug this check targets.
wp_set_current_user( 1 );

// ─── Tiny assertion harness ─────────────────────────────────────────────────
$failures = array();
$passes   = 0;

/**
 * Assert two values are byte-for-byte identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Human label.
 * @return void
 */
function check_same( $expected, $actual, $label ) {
	global $failures, $passes;
	if ( $expected === $actual ) {
		$passes++;
		fwrite( STDOUT, "  ✓ $label\n" );
	} else {
		$failures[] = $label;
		fwrite( STDOUT, "  ✗ $label\n" );
		fwrite( STDOUT, '      expected: ' . var_export( $expected, true ) . "\n" );
		fwrite( STDOUT, '      actual:   ' . var_export( $actual, true ) . "\n" );
	}
}

/**
 * Assert a condition holds.
 *
 * @param bool   $cond  Condition.
 * @param string $label Human label.
 * @return void
 */
function check_true( $cond, $label ) {
	global $failures, $passes;
	if ( $cond ) {
		$passes++;
		fwrite( STDOUT, "  ✓ $label\n" );
	} else {
		$failures[] = $label;
		fwrite( STDOUT, "  ✗ $label\n" );
	}
}

// The adversarial payload: every byte sequence the bug class destroys.
$evil = "Line1\r\nLine2 with \"double\" and 'single' quotes, a Windows path C:\\Users\\name, "
	. "a comment breakout --> and a forged open <!-- wp:core/html, an accent café, and emoji 🚀.";

// ─── Case 1: block-attribute JSON survives wp_insert_post + parse_blocks ─────
fwrite( STDOUT, "\nCase 1 — block-attribute JSON round-trip (incident #1 + comment breakout):\n" );

$attrs  = array( 'name' => 'acf/test', 'data' => array( 'text' => $evil ), 'mode' => 'preview' );
$json   = Arcadia_Block_Serializer::encode_attributes( $attrs );
$markup = '<!-- wp:acf/test ' . $json . ' /-->';

$post_id = wp_insert_post(
	wp_slash(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'AA fidelity check',
			'post_content' => $markup,
		)
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	fwrite( STDERR, 'Error inserting post: ' . $post_id->get_error_message() . "\n" );
	exit( 1 );
}

$stored = get_post( $post_id )->post_content;
$blocks = parse_blocks( $stored );

// The breakout sequence must not appear raw; only the block's own self-close `/-->`.
check_true( substr_count( $stored, '-->' ) === 1, 'stored markup has no comment breakout (only the block close)' );

// parse_blocks must recover the block and its attributes byte-for-byte.
$parsed_text = isset( $blocks[0]['attrs']['data']['text'] ) ? $blocks[0]['attrs']['data']['text'] : null;
check_same( $evil, $parsed_text, 'block attribute text survives wp_insert_post + parse_blocks intact' );

wp_delete_post( $post_id, true );

// ─── Case 2: JSON meta survives update_post_meta + json_decode ──────────────
fwrite( STDOUT, "\nCase 2 — JSON post-meta round-trip (incident #2):\n" );

$meta_post = wp_insert_post(
	wp_slash( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'AA meta check' ) ),
	true
);

$payload = array( 'body' => array( 'content' => $evil ), 'meta' => array( 'title' => $evil ) );
update_post_meta( $meta_post, '_aa_revision_meta', wp_slash( wp_json_encode( $payload ) ) );

$read    = get_post_meta( $meta_post, '_aa_revision_meta', true );
$decoded = json_decode( $read, true );

check_true( null !== $decoded, 'stored JSON meta is still valid (json_decode non-null)' );
check_same( $evil, $decoded['body']['content'] ?? null, 'JSON meta value survives update_post_meta + json_decode' );

wp_delete_post( $meta_post, true );

// ─── Case 3: full create_revision() round-trip on real WordPress ─────────────
fwrite( STDOUT, "\nCase 3 — create_revision() stored payload round-trip:\n" );

$target = wp_insert_post(
	wp_slash( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'AA revision target' ) ),
	true
);

$revisions = Arcadia_Revisions::get_instance();
$result    = $revisions->create_revision(
	$target,
	array( 'title' => $evil, 'revision_notes' => $evil ),
	array( 'description' => $evil ),
	'<p>' . esc_html( $evil ) . '</p>'
);

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Error creating revision: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

$rev_id   = $result['revision_id'];
$rev_meta = json_decode( get_post_meta( $rev_id, '_aa_revision_meta', true ), true );

check_true( null !== $rev_meta, 'revision _aa_revision_meta is valid JSON (json_decode non-null)' );
check_same( $evil, $rev_meta['body']['title'] ?? null, 'revision stored body.title survives intact' );
check_same( $evil, $rev_meta['meta']['description'] ?? null, 'revision stored meta.description survives intact' );

wp_delete_post( $rev_id, true );
wp_delete_post( $target, true );

// ─── Case 4: a list read back and pushed back keeps its items inside (Phase 52) ─
fwrite( STDOUT, "\nCase 4 — round-trip container keeps its children inside (trottinette, 19 pages):\n" );

$list_markup = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Un</li>\n<!-- /wp:list-item -->\n\n"
	. "<!-- wp:list-item -->\n<li>Deux</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
$list_parsed = parse_blocks( $list_markup )[0];

/**
 * A parsed block as AA pushes it back: type + inner_content + inner_blocks.
 *
 * @param array $parsed     A real parse_blocks() node.
 * @param bool  $keep_nulls True = innerContent with its placeholders (what the
 *                          read now exposes); false = innerHTML as one chunk
 *                          (what the read used to expose, the broken case).
 * @return array
 */
function aa_roundtrip_node( $parsed, $keep_nulls ) {
	$node = array(
		'type'          => $parsed['blockName'],
		'inner_content' => $keep_nulls ? $parsed['innerContent'] : array( $parsed['innerHTML'] ),
	);
	if ( ! empty( $parsed['innerBlocks'] ) ) {
		$node['inner_blocks'] = array_map(
			static fn( $child ) => aa_roundtrip_node( $child, $keep_nulls ),
			$parsed['innerBlocks']
		);
	}
	return $node;
}

foreach ( array( 'innerHTML as one chunk' => false, 'innerContent with nulls' => true ) as $shape => $keep_nulls ) {
	$written = Arcadia_Blocks::get_instance()->json_to_blocks(
		array( 'children' => array( aa_roundtrip_node( $list_parsed, $keep_nulls ) ) )
	);
	if ( is_wp_error( $written ) ) {
		check_true( false, "[$shape] write accepted (got " . $written->get_error_code() . ')' );
		continue;
	}
	$reparsed = parse_blocks( $written )[0] ?? array();
	$html     = preg_replace( '/\s+/', '', render_block( $reparsed ) );

	check_same( 2, count( $reparsed['innerBlocks'] ?? array() ), "[$shape] list still holds its 2 items as inner blocks" );
	check_same( '<ulclass="wp-block-list"><li>Un</li><li>Deux</li></ul>', $html, "[$shape] rendered items sit inside the <ul>" );
}

// ─── Case 5: an unchanged block goes back byte for byte (`stored`, iSelection 114236) ─
fwrite( STDOUT, "\nCase 5 — read then push back unchanged: post_content identical (iSelection 114236):\n" );

require_once ABSPATH . 'wp-admin/includes/user.php';

$fixture = file_get_contents( dirname( __DIR__ ) . '/tests/fixtures/iselection-114236.html' );
$api     = Arcadia_API::get_instance();

// Two authors: an administrator (unfiltered_html, the single-site case) and an
// author without it — where kses filters every write, as for every non super
// admin on a multisite. write_post() writes as the post's author.
$no_unfiltered = wp_insert_user(
	array(
		'user_login' => 'aa_fidelity_author_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'author',
	)
);
check_true( ! user_can( $no_unfiltered, 'unfiltered_html' ), 'the second author really lacks unfiltered_html' );

/**
 * GET /contents/{id}/blocks → the PUT children AA sends for unchanged blocks.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function aa_unchanged_children( $post_id ) {
	$request = new WP_REST_Request( 'GET' );
	$request->set_param( 'id', $post_id );
	$read = Arcadia_API::get_instance()->get_article_blocks( $request )->get_data();

	return array_map(
		static fn( $block ) => array(
			'type'       => $block['blockName'],
			'properties' => $block['attrs']['data'] ?? array(),
			'stored'     => $block['stored'] ?? null,
		),
		$read['blocks']
	);
}

/**
 * PUT /contents/{id} with a children body.
 *
 * @param int   $post_id  Post ID.
 * @param array $children Children.
 * @param bool  $dry_run  Dry-run flag.
 * @return array Response data.
 */
function aa_put_children( $post_id, $children, $dry_run = false ) {
	$request = new WP_REST_Request( 'PUT' );
	$request->set_param( 'id', $post_id );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'children' => $children, 'dry_run' => $dry_run ) ) );
	$response = Arcadia_API::get_instance()->update_post( $request );
	return is_wp_error( $response ) ? array( 'error' => $response->get_error_code() ) : $response->get_data();
}

$force_draft_before = get_option( 'aa_force_draft', false );
update_option( 'aa_force_draft', false );

foreach ( array( 'administrator' => 1, 'author without unfiltered_html' => $no_unfiltered ) as $who => $author ) {
	wp_set_current_user( 1 ); // Seed as admin: the stored state the client's editor produced.
	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'AA stored fidelity',
				'post_author'  => $author,
				'post_content' => $fixture,
			)
		),
		true
	);
	check_same( $fixture, get_post( $post_id )->post_content, "[$who] fixture seeded intact" );

	$children = aa_unchanged_children( $post_id );
	check_same( 28, count( array_filter( array_column( $children, 'stored' ), 'is_string' ) ), "[$who] read hands all 28 top-level blocks their `stored`" );

	$dry = aa_put_children( $post_id, $children, true );
	check_true( empty( $dry['warnings'] ) && ! empty( $dry['valid'] ), "[$who] dry-run: valid, no warning" );

	$put = aa_put_children( $post_id, $children );
	check_true( ! empty( $put['success'] ) && empty( $put['warnings'] ), "[$who] PUT accepted without warning" );
	check_same( $fixture, get_post( $post_id )->post_content, "[$who] post_content identical byte for byte after the push" );

	// Control: the same push without `stored` is the incident — the bytes change.
	// Without this, an always-identical result would pass the check above.
	if ( 1 === $author ) {
		$bare = array_map(
			static function ( $child ) {
				unset( $child['stored'] );
				return $child;
			},
			$children
		);
		// Without ACF Pro (this dev stack) the acf/* blocks are unregistered and
		// the push is refused; on a client site they are re-rendered, which is
		// the incident. Either way, no successful push leaves the post intact.
		// StoredBlocksTest::test_acf_rerender_is_the_incident reproduces the
		// re-render itself through the ACF adapter.
		$control = aa_put_children( $post_id, $bare );
		check_true(
			isset( $control['error'] ) || $fixture !== get_post( $post_id )->post_content,
			"[$who] control: without `stored` the same push is refused or rewrites the post"
		);
		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $fixture ) ) );
		check_same( $fixture, get_post( $post_id )->post_content, "[$who] control: fixture restored" );
	}

	// One block changed: only it differs.
	$changed      = $children;
	$changed[3]   = array( 'type' => 'core/paragraph', 'content' => 'Chiffre mis à jour : 42 %' );
	$put          = aa_put_children( $post_id, $changed );
	$after        = get_post( $post_id )->post_content;
	$slices       = array_column( Arcadia_Block_Slicer::top_level_slices( $fixture ), 'markup' );
	$prefix       = implode( '', array_slice( $slices, 0, 3 ) );
	$suffix       = implode( '', array_slice( $slices, 4 ) );
	check_true(
		0 === strpos( $after, $prefix ) && str_ends_with( $after, $suffix ) && false !== strpos( $after, 'Chiffre mis à jour : 42 %' ),
		"[$who] one block modified: the 27 others are untouched"
	);

	// Force Draft: the unchanged push becomes a pending revision whose content —
	// what approval writes to the live post — is the post itself.
	if ( 1 === $author ) {
		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $fixture ) ) );
		update_option( 'aa_force_draft', true );
		$rev = aa_put_children( $post_id, $children );
		update_option( 'aa_force_draft', false );
		check_true( ! empty( $rev['revision_created'] ) && empty( $rev['warnings'] ), "[$who] Force Draft: revision created without warning" );
		if ( ! empty( $rev['revision_id'] ) ) {
			check_same( $fixture, get_post( $rev['revision_id'] )->post_content, "[$who] Force Draft: the revision holds the post byte for byte" );
			wp_delete_post( $rev['revision_id'], true );
		}
	}

	wp_delete_post( $post_id, true );
}

wp_set_current_user( 1 );
wp_delete_user( $no_unfiltered );
update_option( 'aa_force_draft', $force_draft_before );

// ─── Summary ────────────────────────────────────────────────────────────────
fwrite( STDOUT, "\n" );
if ( empty( $failures ) ) {
	fwrite( STDOUT, "fidelity-check: OK — {$passes} assertions passed on real WordPress.\n" );
	exit( 0 );
}
fwrite( STDOUT, 'fidelity-check: FAILED — ' . count( $failures ) . " corruption(s) detected:\n" );
foreach ( $failures as $f ) {
	fwrite( STDOUT, "  - $f\n" );
}
exit( 1 );
