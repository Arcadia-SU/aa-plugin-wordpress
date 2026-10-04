<?php
/**
 * Test: where each nested round-trip block goes in its parent's markup (Phase 52).
 *
 * On trottinette-tout-terrain.fr, 19 pages pushed back by AA rendered
 * `<ul class="wp-block-list"></ul>` followed by the `<li>` outside the list. A
 * container read back from the site is a single chunk — the whole envelope —
 * and the fallback took that chunk as the opening and appended every child
 * after it.
 *
 * The wrappers below are AA's fixtures (tests/unit/domain/seo/value_objects/
 * test_block_children.py), read from the sites it pushes to. The rule is
 * ported from the same module, so both sides must cut every one of them at the
 * same place.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-markdown-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';

class ChildPlacementTest extends TestCase {

	// =========================================================================
	// split_at_slot — the rule, on the real envelopes
	// =========================================================================

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function envelope_provider(): array {
		return array(
			'list read back from the site'         => array(
				'<ul class="wp-block-list">' . "\n\n" . '</ul>',
				'<ul class="wp-block-list">',
				'</ul>',
			),
			'list with the padding of a broken page' => array(
				"<ol>\n</ol>",
				'<ol>',
				'</ol>',
			),
			'uagb container inner wrap'             => array(
				'<div class="wp-block-uagb-container"><div class="uagb-container-inner-blocks-wrap">' . "\n\n" . '</div></div>',
				'<div class="wp-block-uagb-container"><div class="uagb-container-inner-blocks-wrap">',
				'</div></div>',
			),
			'cover skips its empty background span'  => array(
				'<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span><img src="x.jpg" alt=""/><div class="wp-block-cover__inner-container">' . "\n\n" . '</div></div>',
				'<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span><img src="x.jpg" alt=""/><div class="wp-block-cover__inner-container">',
				'</div></div>',
			),
			'media-text fills its content column'    => array(
				'<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="x.jpg" alt="" /></figure><div class="wp-block-media-text__content">' . "\n\n" . '</div></div>',
				'<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="x.jpg" alt="" /></figure><div class="wp-block-media-text__content">',
				'</div></div>',
			),
			'single child, nothing between the tags' => array(
				'<div class="wp-block-buttons"></div>',
				'<div class="wp-block-buttons">',
				'</div>',
			),
			'list item keeps its text first'         => array(
				'<li><strong>Titre</strong></li>',
				'<li><strong>Titre</strong>',
				'</li>',
			),
			'quote keeps its citation last'          => array(
				'<blockquote class="wp-block-quote"><cite>Auteur</cite></blockquote>',
				'<blockquote class="wp-block-quote">',
				'<cite>Auteur</cite></blockquote>',
			),
			'figure keeps its figcaption last'       => array(
				'<figure class="wp-block-gallery"><figcaption>Légende</figcaption></figure>',
				'<figure class="wp-block-gallery">',
				'<figcaption>Légende</figcaption></figure>',
			),
			'on a whitespace tie, the last wins'     => array(
				"<div><div class=\"a\">\n</div><div class=\"b\">\n</div></div>",
				'<div><div class="a">' . "\n" . '</div><div class="b">',
				'</div></div>',
			),
			'no element at all: children after'      => array(
				'texte libre',
				'texte libre',
				'',
			),
		);
	}

	/**
	 * @dataProvider envelope_provider
	 */
	public function test_the_envelope_is_cut_at_the_slot( string $wrapper, string $open, string $close ): void {
		$this->assertSame( array( $open, $close ), \Arcadia_Block_Children::split_at_slot( $wrapper ) );
	}

	// =========================================================================
	// The write path — children land inside their parent
	// =========================================================================

	private static function li( string $text ): array {
		return array(
			'type'          => 'core/list-item',
			'inner_content' => array( "<li>{$text}</li>" ),
		);
	}

	private static function p( string $text ): array {
		return array(
			'type'          => 'core/paragraph',
			'inner_content' => array( "<p>{$text}</p>" ),
		);
	}

	/**
	 * Rendered HTML with the block-comment delimiters and newlines removed, so
	 * the assertion reads as the page a visitor gets.
	 */
	private static function page( string $markup ): string {
		return str_replace( "\n", '', preg_replace( '/<!--.*?-->/s', '', $markup ) );
	}

	private function render( array $block ): string {
		$result = \Arcadia_Blocks::get_instance()->json_to_blocks( array( 'children' => array( $block ) ) );
		$this->assertIsString( $result, 'The block was refused: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
		return $result;
	}

	/**
	 * The production case: what GET /contents/{id}/blocks returns for a list —
	 * one chunk, the whole envelope.
	 */
	public function test_a_list_read_back_keeps_its_items_inside(): void {
		$html = self::page(
			$this->render(
				array(
					'type'          => 'core/list',
					'inner_content' => array( "\n<ul class=\"wp-block-list\">\n\n</ul>\n" ),
					'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
				)
			)
		);

		$this->assertSame( '<ul class="wp-block-list"><li>a</li><li>b</li></ul>', $html );
	}

	public function test_a_cover_fills_its_inner_container_not_its_background(): void {
		$markup = $this->render(
			array(
				'type'          => 'core/cover',
				'inner_content' => array( '<div class="wp-block-cover"><span class="wp-block-cover__background"></span><div class="wp-block-cover__inner-container">' . "\n\n" . '</div></div>' ),
				'inner_blocks'  => array( self::p( 'a' ) ),
			)
		);

		$this->assertStringContainsString(
			'<div class="wp-block-cover__inner-container"><p>a</p></div></div>',
			self::page( $markup )
		);
	}

	public function test_nested_parents_are_placed_too(): void {
		$html = self::page(
			$this->render(
				array(
					'type'          => 'core/group',
					'inner_content' => array( "<div class=\"wp-block-group\">\n\n</div>" ),
					'inner_blocks'  => array(
						array(
							'type'          => 'core/list',
							'inner_content' => array( "<ul>\n</ul>" ),
							'inner_blocks'  => array( self::li( 'a' ) ),
						),
					),
				)
			)
		);

		$this->assertSame( '<div class="wp-block-group"><ul><li>a</li></ul></div>', $html );
	}

	/**
	 * The shape the legacy fallback handled: nulls stripped by AA's reader
	 * from a multi-chunk envelope. Joined then cut, it lands the same way.
	 */
	public function test_a_multi_chunk_envelope_without_nulls_still_works(): void {
		$html = self::page(
			$this->render(
				array(
					'type'          => 'core/list',
					'inner_content' => array( '<ul>', "\n\n", '</ul>' ),
					'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
				)
			)
		);

		$this->assertSame( '<ul><li>a</li><li>b</li></ul>', $html );
	}

	public function test_children_without_markup_nest_in_the_comment(): void {
		$markup = $this->render(
			array(
				'type'          => 'core/columns',
				'inner_content' => array( '' ),
				'inner_blocks'  => array( self::p( 'a' ) ),
			)
		);

		$this->assertSame( '<p>a</p>', self::page( $markup ) );
		$this->assertStringContainsString( '<!-- wp:columns -->', $markup );
	}

	public function test_placeholders_matching_the_children_are_used_as_is(): void {
		$html = self::page(
			$this->render(
				array(
					'type'          => 'core/list',
					'inner_content' => array( '<ul>', null, "\n\n", null, '</ul>' ),
					'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
				)
			)
		);

		$this->assertSame( '<ul><li>a</li><li>b</li></ul>', $html );
	}

	// =========================================================================
	// What is refused — no reading says where the children go
	// =========================================================================

	/**
	 * @return array<string, array{0:int}>
	 */
	public static function disagreeing_positions(): array {
		return array(
			'one position for two children'    => array( 1 ),
			'three positions for two children' => array( 3 ),
		);
	}

	/**
	 * @dataProvider disagreeing_positions
	 */
	public function test_placeholders_disagreeing_with_the_children_are_refused( int $positions ): void {
		$chunks = array( '<ul>' );
		for ( $i = 0; $i < $positions; $i++ ) {
			$chunks[] = null;
			$chunks[] = "\n";
		}
		$chunks[] = '</ul>';

		$result = \Arcadia_Blocks::get_instance()->json_to_blocks(
			array(
				'children' => array(
					array(
						'type'          => 'core/list',
						'inner_content' => $chunks,
						'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'child_position_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] );
		$this->assertSame( 'core/list', $data['block_type'] );
		$this->assertSame( 2, $data['children'] );
		$this->assertSame( $positions, $data['positions'] );
	}

	/**
	 * A mismatch one level down is refused too: the validation pass recurses.
	 */
	public function test_a_nested_mismatch_is_refused(): void {
		$result = \Arcadia_Blocks::get_instance()->json_to_blocks(
			array(
				'children' => array(
					array(
						'type'          => 'core/group',
						'inner_content' => array( '<div>', null, '</div>' ),
						'inner_blocks'  => array(
							array(
								'type'          => 'core/list',
								'inner_content' => array( '<ul>', null, '</ul>' ),
								'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
							),
						),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'child_position_mismatch', $result->get_error_code() );
	}

	public function test_no_placeholder_at_all_is_not_a_mismatch(): void {
		$this->assertNull(
			\Arcadia_Block_Children::placeholder_mismatch(
				array(
					'inner_content' => array( "<ul>\n\n</ul>" ),
					'inner_blocks'  => array( self::li( 'a' ), self::li( 'b' ) ),
				)
			)
		);
	}
}
