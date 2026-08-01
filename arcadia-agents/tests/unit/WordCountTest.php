<?php
/**
 * Test: word_count is honest or absent (Phase 41.3).
 *
 * `str_word_count( wp_strip_all_tags( $post->post_content ) )` had two
 * defects:
 *
 * 1. It returned 0 for every ACF-block post. The content lives in block
 *    attributes; post_content holds only block comments, which strip_tags()
 *    removes. That is a *false signal*, not a missing value — an audit
 *    reading `word_count: 0` concludes "thin content" on a 30k-character
 *    business page.
 *
 * 2. str_word_count() is not UTF-8 safe: it treats accented bytes as word
 *    separators, so every accented French post was inflated.
 *
 * Also pins the payload shape of format_post(), which FormattersTest could
 * not do — it asserted a hand-written array against itself and would have
 * stayed green through any change to the formatter.
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
 * Minimal helper exposing the formatters trait.
 */
class WordCountHelper {
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
				return '';
			}
		};
	}

	public function expose_format_post( $post ) {
		return $this->format_post( $post );
	}
}

class WordCountTest extends TestCase {

	/** @var WordCountHelper */
	private $helper;

	protected function setUp(): void {
		global $_test_options, $_test_posts, $_test_post_meta, $_test_post_categories,
			$_test_post_tags, $_test_taxonomies, $_test_users;

		$_test_options         = array();
		$_test_posts           = array();
		$_test_post_meta       = array();
		$_test_post_categories = array();
		$_test_post_tags       = array();
		$_test_taxonomies      = array();

		$reflection = new \ReflectionClass( \Arcadia_Preview::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->helper = new WordCountHelper();
	}

	private function format( string $content, int $id = 200 ): array {
		global $_test_posts, $_test_post_meta;

		$post = (object) array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_title'    => 'T',
			'post_name'     => 't',
			'post_status'   => 'publish',
			'post_content'  => $content,
			'post_excerpt'  => '',
			'post_author'   => 1,
			'post_date'     => '2026-04-01 00:00:00',
			'post_modified' => '2026-04-01 00:00:00',
			'post_parent'   => 0,
		);

		$_test_posts[ $id ]     = $post;
		$_test_post_meta[ $id ] = array();

		return $this->helper->expose_format_post( $post );
	}

	// ---------------------------------------------------------------------
	// The false zero
	// ---------------------------------------------------------------------

	/**
	 * The reported defect: an ACF-block page whose prose lives entirely in
	 * block attributes.
	 *
	 * @dataProvider prose_free_content
	 */
	public function test_word_count_is_omitted_when_not_computable( string $content, string $label ): void {
		$formatted = $this->format( $content );

		$this->assertArrayNotHasKey(
			'word_count',
			$formatted,
			"word_count must be absent, not zero, for {$label}."
		);
	}

	public static function prose_free_content(): array {
		return array(
			'ACF block, prose in attributes' => array(
				'<!-- wp:acf/hero {"name":"acf/hero","data":{"title":"Investir dans le neuf","text":"Un texte long."},"mode":"preview"} /-->',
				'an ACF-block post',
			),
			'several ACF blocks'             => array(
				'<!-- wp:acf/hero {"data":{"title":"A"}} /-->' . "\n" . '<!-- wp:acf/faq {"data":{"q":"B"}} /-->',
				'a multi-ACF-block post',
			),
			'empty content'                  => array( '', 'an empty post' ),
			'whitespace only'                => array( "  \n\t ", 'a whitespace-only post' ),
			'markup with no text'            => array( '<div class="wrap"><span></span></div>', 'markup carrying no text' ),
		);
	}

	// ---------------------------------------------------------------------
	// …while a real count is still reported
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider countable_content
	 */
	public function test_word_count_is_reported_when_computable( string $content, int $expected ): void {
		$formatted = $this->format( $content );

		$this->assertArrayHasKey( 'word_count', $formatted );
		$this->assertSame( $expected, $formatted['word_count'] );
	}

	public static function countable_content(): array {
		return array(
			'plain text'                => array( 'one two three', 3 ),
			'gutenberg paragraph'       => array( '<!-- wp:paragraph --><p>one two three</p><!-- /wp:paragraph -->', 3 ),
			'tags do not split words'   => array( '<p>hello <strong>world</strong></p>', 2 ),
			'newlines and tabs'         => array( "one\ntwo\tthree   four", 4 ),
			'single word'               => array( 'mot', 1 ),
			// str_word_count() split on every accent: this counted 4.
			'accented french'           => array( 'Réhabilitation énergétique', 2 ),
			// …and 6 here.
			'accented with apostrophes' => array( "L'énergie d'aujourd'hui à Nîmes", 4 ),
			'punctuation attached'      => array( 'Bonjour, monde !', 3 ),
		);
	}

	/**
	 * Guards the specific regression, in the terms it was reported in: the old
	 * implementation returned 4 for this string, the new one returns 2.
	 */
	public function test_accents_are_no_longer_word_separators(): void {
		$formatted = $this->format( 'Réhabilitation énergétique' );

		$this->assertSame( 2, $formatted['word_count'] );
		$this->assertNotSame(
			str_word_count( 'Réhabilitation énergétique' ),
			$formatted['word_count'],
			'If these agree, the UTF-8 fix has been reverted.'
		);
	}

	// ---------------------------------------------------------------------
	// Payload shape
	// ---------------------------------------------------------------------

	public function test_format_post_payload_shape(): void {
		$expected = array(
			'id',
			'title',
			'slug',
			'post_type',
			'status',
			'url',
			'excerpt',
			'content',
			'author',
			'published_at',
			'last_modified',
			'word_count',
			'has_blocks',
			'featured_image_id',
			'featured_image_url',
			'featured_image_alt',
			'categories',
			'tags',
			'seo',
			'preview_url',
			'field_values',
		);

		$this->assertSame( $expected, array_keys( $this->format( 'du contenu' ) ) );
	}

	/**
	 * Only `word_count` disappears — dropping it must not disturb the order or
	 * presence of anything else.
	 */
	public function test_only_word_count_is_dropped(): void {
		$with    = array_keys( $this->format( 'du contenu', 201 ) );
		$without = array_keys( $this->format( '<!-- wp:acf/hero {"data":{"a":"b"}} /-->', 202 ) );

		$this->assertSame(
			array_values( array_diff( $with, array( 'word_count' ) ) ),
			$without
		);
	}
}
