<?php
/**
 * Tests for Arcadia_Markdown_Parser block-level parser (Phase 36).
 *
 * The agent stores STRUCTURAL MARKDOWN in ACF wysiwyg fields (headings, lists,
 * GFM tables, blockquotes + inline tokens), never HTML (ADR-013/ADR-022).
 * parse_block_markdown() turns that markdown into block+inline HTML and
 * parse_rich() sanitises it with wp_kses_post(). This matrix is derived from the
 * GFM/CommonMark edge-case research: heading/list/table/quote dispatch, the
 * inline binding order, the `---` ambiguity, PCRE safety (FR accents, CRLF,
 * large input), the HTML passthrough net, and the skip_markdown round-trip.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-markdown-parser.php';

/**
 * Test class for the block markdown parser.
 */
class MarkdownBlockParserTest extends TestCase {

	/**
	 * Parse to raw (unsanitised) block HTML — for structural assertions.
	 *
	 * @param string $md Markdown.
	 * @return string Block HTML.
	 */
	private function parse( $md ) {
		return \Arcadia_Markdown_Parser::parse_block_markdown( $md );
	}

	// =====================================================================
	// Headings
	// =====================================================================

	public function test_h2_heading(): void {
		$this->assertStringContainsString( '<h2>Titre</h2>', $this->parse( '## Titre' ) );
	}

	public function test_heading_levels(): void {
		$this->assertStringContainsString( '<h3>X</h3>', $this->parse( '### X' ) );
		$this->assertStringContainsString( '<h6>Y</h6>', $this->parse( '###### Y' ) );
	}

	public function test_no_space_after_hash_is_paragraph(): void {
		$out = $this->parse( '##NoSpace' );
		$this->assertStringNotContainsString( '<h2>', $out );
		$this->assertStringContainsString( '<p>##NoSpace</p>', $out );
	}

	public function test_seven_hashes_is_paragraph(): void {
		$out = $this->parse( '####### TooDeep' );
		$this->assertStringNotContainsString( '<h7>', $out );
		$this->assertStringNotContainsString( '<h6>', $out );
		$this->assertStringContainsString( '<p>####### TooDeep</p>', $out );
	}

	public function test_heading_with_inline_link(): void {
		$out = $this->parse( '## See [docs](https://ext.example.com)' );
		$this->assertStringContainsString( '<h2>', $out );
		$this->assertStringContainsString( '<a href="https://ext.example.com"', $out );
	}

	public function test_heading_strips_trailing_hashes(): void {
		$this->assertStringContainsString( '<h2>Titre</h2>', $this->parse( '## Titre ##' ) );
	}

	// =====================================================================
	// Lists
	// =====================================================================

	public function test_unordered_list(): void {
		$out = $this->parse( "- a\n- b" );
		$this->assertStringContainsString( '<ul><li>a</li><li>b</li></ul>', $out );
	}

	public function test_ordered_list(): void {
		$out = $this->parse( "1. a\n2. b" );
		$this->assertStringContainsString( '<ol><li>a</li><li>b</li></ol>', $out );
	}

	public function test_nested_list_one_level(): void {
		$out = $this->parse( "- parent\n  - child" );
		$this->assertStringContainsString( '<li>parent<ul><li>child</li></ul></li>', $out );
	}

	public function test_list_items_are_tight_no_inner_paragraph(): void {
		$out = $this->parse( "- a\n- b" );
		$this->assertStringNotContainsString( '<li><p>', $out );
	}

	public function test_two_dot_in_paragraph_does_not_open_list(): void {
		// An ordered list interrupts a paragraph only when it starts at 1.
		$out = $this->parse( "En 2024\n2. année" );
		$this->assertStringNotContainsString( '<ol>', $out );
		$this->assertStringContainsString( '<p>En 2024 2. année</p>', $out );
	}

	public function test_list_with_inline_markdown(): void {
		$out = $this->parse( '- a **bold** item' );
		$this->assertStringContainsString( '<li>a <strong>bold</strong> item</li>', $out );
	}

	// =====================================================================
	// Tables (GFM)
	// =====================================================================

	public function test_table_full(): void {
		$md  = "| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<table>', $out );
		$this->assertStringContainsString( '<thead>', $out );
		$this->assertStringContainsString( '<th>A</th>', $out );
		$this->assertStringContainsString( '<th>B</th>', $out );
		$this->assertStringContainsString( '<tbody>', $out );
		$this->assertStringContainsString( '<td>1</td>', $out );
		$this->assertStringContainsString( '<td>4</td>', $out );
	}

	public function test_table_cell_count_mismatch_is_not_a_table(): void {
		// Header has 2 cells, delimiter has 3 → NOT a table → paragraph.
		$md  = "| A | B |\n| --- | --- | --- |\n| 1 | 2 |";
		$out = $this->parse( $md );
		$this->assertStringNotContainsString( '<table>', $out );
		$this->assertStringContainsString( '<p>', $out );
	}

	public function test_table_single_column(): void {
		$md  = "| H |\n| --- |\n| v |";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<th>H</th>', $out );
		$this->assertStringContainsString( '<td>v</td>', $out );
	}

	public function test_table_escaped_pipe_in_cell(): void {
		$md  = "| A | B |\n| --- | --- |\n| a \\| b | c |";
		$out = $this->parse( $md );
		// The escaped pipe stays a literal pipe inside one cell (not a splitter).
		$this->assertStringContainsString( '<td>a | b</td>', $out );
		$this->assertStringContainsString( '<td>c</td>', $out );
	}

	public function test_table_alignment(): void {
		$md  = "| L | C | R |\n| :-- | :-: | --: |\n| a | b | c |";
		$out = $this->parse( $md );
		$this->assertStringContainsString( 'text-align:left', $out );
		$this->assertStringContainsString( 'text-align:center', $out );
		$this->assertStringContainsString( 'text-align:right', $out );
	}

	public function test_table_cell_inline_markdown(): void {
		$md  = "| H |\n| --- |\n| **bold** [x](https://ext.example.com) |";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( '<a href="https://ext.example.com"', $out );
	}

	public function test_table_body_row_padded_to_header_count(): void {
		$md  = "| A | B |\n| --- | --- |\n| only |";
		$out = $this->parse( $md );
		// Missing second cell padded to an empty <td>.
		$this->assertStringContainsString( '<td>only</td>', $out );
		$this->assertStringContainsString( '<td></td>', $out );
	}

	// =====================================================================
	// Blockquotes
	// =====================================================================

	public function test_blockquote(): void {
		$out = $this->parse( '> une citation' );
		$this->assertStringContainsString( '<blockquote>', $out );
		$this->assertStringContainsString( 'une citation', $out );
		$this->assertStringContainsString( '</blockquote>', $out );
	}

	public function test_blockquote_multiline(): void {
		$out = $this->parse( "> ligne un\n> ligne deux" );
		$this->assertStringContainsString( '<blockquote>', $out );
		$this->assertStringContainsString( 'ligne un ligne deux', $out );
	}

	public function test_blockquote_closed_by_non_quote_line(): void {
		$out = $this->parse( "> cited\n\nnormal" );
		$this->assertStringContainsString( '<blockquote>', $out );
		$this->assertStringContainsString( '<p>normal</p>', $out );
	}

	// =====================================================================
	// Paragraphs
	// =====================================================================

	public function test_paragraph(): void {
		$this->assertStringContainsString( '<p>Hello world</p>', $this->parse( 'Hello world' ) );
	}

	public function test_blank_line_separates_paragraphs(): void {
		$out = $this->parse( "first\n\nsecond" );
		$this->assertStringContainsString( '<p>first</p>', $out );
		$this->assertStringContainsString( '<p>second</p>', $out );
	}

	public function test_soft_wrapped_lines_join_into_one_paragraph(): void {
		$out = $this->parse( "line one\nline two" );
		$this->assertStringContainsString( '<p>line one line two</p>', $out );
	}

	// =====================================================================
	// Inline binding order
	// =====================================================================

	public function test_code_span_binds_before_emphasis(): void {
		// `*x*` inside a code span stays literal — code is processed first.
		$out = $this->parse( 'use `*ptr*` here' );
		$this->assertStringContainsString( '<code>*ptr*</code>', $out );
		$this->assertStringNotContainsString( '<em>ptr</em>', $out );
	}

	public function test_bold_and_italic(): void {
		$out = $this->parse( 'a **b** and *c*' );
		$this->assertStringContainsString( '<strong>b</strong>', $out );
		$this->assertStringContainsString( '<em>c</em>', $out );
	}

	// =====================================================================
	// Thematic break / code fence
	// =====================================================================

	public function test_thematic_break_dashes(): void {
		$this->assertStringContainsString( '<hr>', $this->parse( "a\n\n---\n\nb" ) );
	}

	public function test_thematic_break_stars(): void {
		$this->assertStringContainsString( '<hr>', $this->parse( '***' ) );
	}

	public function test_code_fence_has_no_internal_parsing(): void {
		$md  = "```\n## not a heading\n- not a list\n```";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<pre><code>', $out );
		$this->assertStringNotContainsString( '<h2>', $out );
		$this->assertStringNotContainsString( '<ul>', $out );
		// The `#` is escaped text inside the code block.
		$this->assertStringContainsString( '## not a heading', $out );
	}

	// =====================================================================
	// HTML passthrough net (incidental already-HTML without skip_markdown)
	// =====================================================================

	public function test_html_passthrough_survives_without_double_wrapping(): void {
		// The old Phase 35 input shape: already-HTML structural content.
		$md  = "<h2>Titre</h2>\n<ul><li>un</li><li>deux</li></ul>";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<h2>Titre</h2>', $out );
		$this->assertStringContainsString( '<ul><li>un</li><li>deux</li></ul>', $out );
		// Not wrapped in a stray paragraph.
		$this->assertStringNotContainsString( '<p><h2>', $out );
	}

	// =====================================================================
	// skip_markdown (round-trip already-HTML, aa-u6nl)
	// =====================================================================

	public function test_skip_markdown_does_not_parse(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( '## not a heading', true );
		$this->assertStringNotContainsString( '<h2>', $out );
		$this->assertStringContainsString( '## not a heading', $out );
	}

	public function test_skip_markdown_still_sanitises(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( '<p>ok</p><script>alert(1)</script>', true );
		$this->assertStringContainsString( '<p>ok</p>', $out );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	public function test_parse_rich_default_parses_markdown(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( '## Titre' );
		$this->assertStringContainsString( '<h2>Titre</h2>', $out );
	}

	// =====================================================================
	// Security (parse_rich → wp_kses_post boundary)
	// =====================================================================

	public function test_script_tag_stripped(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( "## Title\n\n<script>alert(1)</script>" );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	public function test_onerror_handler_stripped(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( 'text **<img src=x onerror=alert(1)>** more' );
		$this->assertStringNotContainsString( 'onerror', $out );
	}

	// =====================================================================
	// External vs internal links (#6)
	// =====================================================================

	public function test_external_link_gets_rel_and_target(): void {
		$out = $this->parse( '[ext](https://external.example.com/page)' );
		$this->assertStringContainsString( 'target="_blank"', $out );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $out );
	}

	public function test_internal_link_has_no_target(): void {
		// home_url() mock returns http://localhost.
		$out = $this->parse( '[home](http://localhost/about)' );
		$this->assertStringNotContainsString( 'target="_blank"', $out );
		$this->assertStringNotContainsString( 'rel="noopener', $out );
	}

	/**
	 * #6: a URL containing balanced parentheses (Wikipedia-style) is not truncated
	 * at the first `)`, and no stray `)` leaks out as text.
	 */
	public function test_link_url_with_balanced_parens_not_truncated(): void {
		$out = $this->parse( '[PHP](https://en.wikipedia.org/wiki/PHP_(programming_language))' );
		$this->assertStringContainsString( 'href="https://en.wikipedia.org/wiki/PHP_(programming_language)"', $out );
		$this->assertStringContainsString( '>PHP</a>', $out );
		// The trailing `)` must not leak as literal text after the anchor.
		$this->assertStringNotContainsString( '</a>)', $out );
	}

	/**
	 * #7: an asterisk inside a URL must NOT be rewritten to <em> (which esc_url
	 * would then mangle) — the link is extracted before the emphasis pass.
	 */
	public function test_link_url_with_asterisk_not_emphasised(): void {
		$out = $this->parse( '[doc](http://localhost/path/*v1*/file)' );
		$this->assertStringContainsString( 'path/*v1*/file', $out );
		$this->assertStringNotContainsString( '<em>', $out );
	}

	/**
	 * #7: emphasis INSIDE link text still renders (extraction does not lose it).
	 */
	public function test_link_text_emphasis_still_applies(): void {
		$out = $this->parse( '[**bold** label](http://localhost/x)' );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( '<a href=', $out );
	}

	// =====================================================================
	// skip_markdown fallback net — inline-leading HTML (#8)
	// =====================================================================

	/**
	 * #8: even without skip_markdown, a round-trip line that STARTS with an inline
	 * HTML tag is passed through verbatim — its stray `*promo*` / `[x](y)` must not
	 * be re-parsed into <em>/<a> and corrupt the site's existing content.
	 */
	public function test_inline_leading_html_line_is_passthrough(): void {
		$out = $this->parse( '<a href="/x">lien</a> tarif *promo* [2024](/y)' );
		$this->assertStringContainsString( '*promo*', $out );
		$this->assertStringNotContainsString( '<em>promo</em>', $out );
		$this->assertStringContainsString( '[2024](/y)', $out );
	}

	// =====================================================================
	// img survives in wysiwyg (#5 — decision: accepted)
	// =====================================================================

	public function test_wysiwyg_img_survives(): void {
		$out = \Arcadia_Markdown_Parser::parse_rich( '<img src="https://cdn.example.com/a.jpg" alt="a">' );
		$this->assertStringContainsString( '<img', $out );
		$this->assertStringContainsString( 'a.jpg', $out );
	}

	// =====================================================================
	// PCRE safety
	// =====================================================================

	public function test_french_accents_preserved(): void {
		$out = $this->parse( '## Café société à Noël' );
		$this->assertStringContainsString( '<h2>Café société à Noël</h2>', $out );
	}

	public function test_crlf_input_is_normalised(): void {
		$out = $this->parse( "## A\r\n\r\nbody" );
		$this->assertStringContainsString( '<h2>A</h2>', $out );
		$this->assertStringContainsString( '<p>body</p>', $out );
	}

	public function test_large_input_is_not_dropped(): void {
		// Guards against catastrophic backtracking returning null (= content loss).
		$big = str_repeat( 'mot ', 5000 );
		$out = $this->parse( '## Titre' . "\n\n" . $big );
		$this->assertStringContainsString( '<h2>Titre</h2>', $out );
		$this->assertStringContainsString( 'mot', $out );
		$this->assertStringContainsString( '<p>', $out );
	}

	public function test_empty_and_non_string_inputs(): void {
		$this->assertSame( '', $this->parse( '' ) );
		$this->assertSame( '', \Arcadia_Markdown_Parser::parse_block_markdown( null ) );
	}

	// =====================================================================
	// Combined document
	// =====================================================================

	public function test_full_document_renders_all_constructs(): void {
		$md = "## Titre\n\nUn paragraphe avec **gras**.\n\n"
			. "- item un\n- item deux\n\n"
			. "| A | B |\n| --- | --- |\n| 1 | 2 |\n\n"
			. "> une citation\n\n---";
		$out = $this->parse( $md );
		$this->assertStringContainsString( '<h2>Titre</h2>', $out );
		$this->assertStringContainsString( '<strong>gras</strong>', $out );
		$this->assertStringContainsString( '<ul><li>item un</li><li>item deux</li></ul>', $out );
		$this->assertStringContainsString( '<table>', $out );
		$this->assertStringContainsString( '<blockquote>', $out );
		$this->assertStringContainsString( '<hr>', $out );
	}
}
