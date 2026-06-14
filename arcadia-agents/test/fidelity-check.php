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
