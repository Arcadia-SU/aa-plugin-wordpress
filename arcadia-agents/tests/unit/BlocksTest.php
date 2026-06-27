<?php
/**
 * Tests for Arcadia_Blocks class.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load the class files directly for testing parse_markdown + block processing.
require_once dirname( __DIR__, 2 ) . '/includes/class-markdown-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';

/**
 * Test class for block processing functions.
 */
class BlocksTest extends TestCase {

    // =========================================================================
    // parse_markdown tests
    // =========================================================================

    /**
     * Test bold markdown conversion.
     */
    public function test_parse_markdown_bold(): void {
        $input    = 'This is **bold** text.';
        $expected = 'This is <strong>bold</strong> text.';

        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test italic markdown conversion.
     */
    public function test_parse_markdown_italic(): void {
        $input    = 'This is *italic* text.';
        $expected = 'This is <em>italic</em> text.';

        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test inline code markdown conversion.
     */
    public function test_parse_markdown_code(): void {
        $input    = 'Use `console.log()` for debugging.';
        $expected = 'Use <code>console.log()</code> for debugging.';

        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test link markdown conversion.
     */
    public function test_parse_markdown_link(): void {
        $input  = 'Visit [example](https://example.com) for more.';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( '<a href="https://example.com"', $result );
        $this->assertStringContainsString( '>example</a>', $result );
    }

    /**
     * Test external link gets target="_blank".
     */
    public function test_parse_markdown_external_link(): void {
        $input  = 'Visit [Google](https://google.com) site.';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( 'target="_blank"', $result );
        $this->assertStringContainsString( 'rel="noopener noreferrer"', $result );
    }

    /**
     * Test internal link doesn't get target="_blank".
     */
    public function test_parse_markdown_internal_link(): void {
        $input  = 'Go to [home](http://localhost/page) page.';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringNotContainsString( 'target="_blank"', $result );
    }

    /**
     * Test combined markdown formatting.
     */
    public function test_parse_markdown_combined(): void {
        $input = 'Text with **bold**, *italic*, and `code`.';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( '<strong>bold</strong>', $result );
        $this->assertStringContainsString( '<em>italic</em>', $result );
        $this->assertStringContainsString( '<code>code</code>', $result );
    }

    /**
     * Test bold and italic together.
     */
    public function test_parse_markdown_bold_before_italic(): void {
        $input    = 'This is **bold** and *italic*.';
        $expected = 'This is <strong>bold</strong> and <em>italic</em>.';

        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test code escapes HTML inside.
     */
    public function test_parse_markdown_code_escapes_html(): void {
        $input  = 'Use `<script>alert("xss")</script>` tag.';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( '&lt;script&gt;', $result );
        $this->assertStringNotContainsString( '<script>', $result );
    }

    /**
     * Test no markdown in plain text.
     */
    public function test_parse_markdown_plain_text(): void {
        $input    = 'Just plain text without markdown.';
        $expected = 'Just plain text without markdown.';

        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test multiple links in same text.
     */
    public function test_parse_markdown_multiple_links(): void {
        $input = 'Visit [one](https://one.com) and [two](https://two.com).';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( 'href="https://one.com"', $result );
        $this->assertStringContainsString( 'href="https://two.com"', $result );
        $this->assertStringContainsString( '>one</a>', $result );
        $this->assertStringContainsString( '>two</a>', $result );
    }

    // =========================================================================
    // Stored-XSS regression tests (A3) — the bold/italic/link rules interpolate
    // raw agent text, so parse_markdown() must wp_kses() its output to an inline
    // allowlist. These prove disallowed markup is neutralised while formatting
    // survives. Reverting the wp_kses() wrap makes them fail.
    // =========================================================================

    /**
     * An injected <img onerror> inside bold markup must be stripped.
     */
    public function test_parse_markdown_strips_injected_img_xss(): void {
        $input  = '**<img src=x onerror=alert(1)>**';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        // The <img> tag is not in the allowlist → removed entirely.
        $this->assertStringNotContainsString( '<img', $result );
        $this->assertStringNotContainsString( 'onerror', $result );
        // The legitimate formatting wrapper survives.
        $this->assertStringContainsString( '<strong>', $result );
    }

    /**
     * An injected <script> tag must not survive in the stored output.
     */
    public function test_parse_markdown_strips_injected_script(): void {
        $input  = 'Hello *<script>alert(1)</script>* world';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringNotContainsString( '<script>', $result );
        $this->assertStringNotContainsString( '</script>', $result );
        $this->assertStringContainsString( '<em>', $result );
    }

    /**
     * The allowlisted inline tags (strong/em/code/a) must pass through intact.
     */
    public function test_parse_markdown_keeps_allowlisted_formatting(): void {
        $input  = '**bold** and *italic* and `snippet` and [link](https://ext.example.com)';
        $result = \Arcadia_Markdown_Parser::parse_markdown( $input );

        $this->assertStringContainsString( '<strong>bold</strong>', $result );
        $this->assertStringContainsString( '<em>italic</em>', $result );
        $this->assertStringContainsString( '<code>snippet</code>', $result );
        $this->assertStringContainsString( 'href="https://ext.example.com"', $result );
    }

    // =========================================================================
    // Block structure tests (documentation tests)
    // =========================================================================

    /**
     * Test ADR-013 unified block model structure.
     */
    public function test_adr013_block_structure(): void {
        // ADR-013 specifies blocks have type, content/children.
        $paragraph_block = array(
            'type'    => 'paragraph',
            'content' => 'Some text',
        );

        $section_block = array(
            'type'     => 'section',
            'heading'  => 'Section Title',
            'level'    => 2,
            'children' => array(
                array( 'type' => 'paragraph', 'content' => 'Content' ),
            ),
        );

        $list_block = array(
            'type'     => 'list',
            'ordered'  => false,
            'children' => array(
                array( 'type' => 'text', 'content' => 'Item 1' ),
                array( 'type' => 'text', 'content' => 'Item 2' ),
            ),
        );

        // Verify structure.
        $this->assertArrayHasKey( 'type', $paragraph_block );
        $this->assertArrayHasKey( 'content', $paragraph_block );
        $this->assertArrayHasKey( 'children', $section_block );
        $this->assertArrayHasKey( 'children', $list_block );
    }

    /**
     * Test image block structure.
     */
    public function test_image_block_structure(): void {
        $image_block = array(
            'type'    => 'image',
            'url'     => 'https://example.com/image.jpg',
            'alt'     => 'Alt text',
            'caption' => 'Image caption',
        );

        $this->assertEquals( 'image', $image_block['type'] );
        $this->assertArrayHasKey( 'url', $image_block );
        $this->assertArrayHasKey( 'alt', $image_block );
        $this->assertArrayHasKey( 'caption', $image_block );
    }

    /**
     * Test heading block structure.
     */
    public function test_heading_block_structure(): void {
        $heading_block = array(
            'type'    => 'heading',
            'level'   => 2,
            'content' => 'Heading Text',
        );

        $this->assertEquals( 'heading', $heading_block['type'] );
        $this->assertEquals( 2, $heading_block['level'] );
        $this->assertEquals( 'Heading Text', $heading_block['content'] );
    }

    // =========================================================================
    // core/* prefix normalization tests
    // =========================================================================

    /**
     * Test json_to_blocks accepts core/paragraph and produces same output as paragraph.
     */
    public function test_core_paragraph_produces_same_output(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json_short = array(
            'children' => array(
                array( 'type' => 'paragraph', 'content' => 'Hello' ),
            ),
        );

        $json_core = array(
            'children' => array(
                array( 'type' => 'core/paragraph', 'content' => 'Hello' ),
            ),
        );

        $output_short = $blocks->json_to_blocks( $json_short );
        $output_core  = $blocks->json_to_blocks( $json_core );

        $this->assertEquals( $output_short, $output_core );
    }

    /**
     * Test json_to_blocks accepts core/heading and produces same output as heading.
     */
    public function test_core_heading_produces_same_output(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json_short = array(
            'children' => array(
                array( 'type' => 'heading', 'content' => 'Title', 'level' => 2 ),
            ),
        );

        $json_core = array(
            'children' => array(
                array( 'type' => 'core/heading', 'content' => 'Title', 'level' => 2 ),
            ),
        );

        $output_short = $blocks->json_to_blocks( $json_short );
        $output_core  = $blocks->json_to_blocks( $json_core );

        $this->assertEquals( $output_short, $output_core );
    }

    /**
     * Test json_to_blocks does NOT reject core/* blocks (no 422 error).
     */
    public function test_core_blocks_not_rejected(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/paragraph', 'content' => 'text' ),
                array( 'type' => 'core/heading', 'content' => 'title', 'level' => 2 ),
                array(
                    'type'     => 'core/list',
                    'ordered'  => false,
                    'children' => array(
                        array( 'type' => 'text', 'content' => 'item 1' ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        // Should return string content, not WP_Error.
        $this->assertIsString( $result );
        $this->assertNotEmpty( $result );
    }

    /**
     * Phase 34 — the non-builtin core blocks (group/quote/table/separator) must
     * NOT 422. These strip to short names absent from BUILTIN_BLOCKS, which is
     * exactly the historical bug (test_core_blocks_not_rejected only covered
     * core blocks whose stripped name IS a builtin, hiding the defect).
     */
    public function test_core_group_quote_table_separator_not_rejected(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'     => 'core/group',
                    'children' => array(
                        array( 'type' => 'paragraph', 'content' => 'inside group' ),
                    ),
                ),
                array( 'type' => 'core/quote', 'content' => 'a quote' ),
                array(
                    'type'       => 'core/table',
                    'properties' => array(
                        'headers' => null,
                        'rows'    => array( array( 'a', 'b' ) ),
                    ),
                ),
                array( 'type' => 'core/separator' ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        // No WP_Error → a string came back.
        $this->assertIsString( $result );
    }

    /**
     * Phase 34 — core/table renders a native WP table block with thead/tbody and
     * inline markdown converted in cells.
     */
    public function test_core_table_renders_native_table(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'       => 'core/table',
                    'properties' => array(
                        'headers' => array( 'Ville', 'Prix' ),
                        'rows'    => array(
                            array( 'Paris', '**1200**€' ),
                            array( 'Lyon', '800€' ),
                        ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
        $this->assertStringContainsString( '<!-- wp:table -->', $result );
        $this->assertStringContainsString( '<thead>', $result );
        $this->assertStringContainsString( '<th>Ville</th>', $result );
        $this->assertStringContainsString( '<tbody>', $result );
        $this->assertStringContainsString( '<td>Paris</td>', $result );
        // Inline markdown inside a cell is converted (no double-escape).
        $this->assertStringContainsString( '<strong>1200</strong>', $result );
    }

    /**
     * Phase 34 — core/table without headers omits the <thead>.
     */
    public function test_core_table_without_headers(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'       => 'core/table',
                    'properties' => array(
                        'headers' => null,
                        'rows'    => array( array( 'A', 'B' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( '<!-- wp:table -->', $result );
        $this->assertStringNotContainsString( '<thead>', $result );
        $this->assertStringContainsString( '<td>A</td>', $result );
    }

    /**
     * Phase 34 — core/quote renders a native blockquote, inline markdown kept.
     */
    public function test_core_quote_renders_blockquote(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/quote', 'content' => 'A **strong** quote' ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( '<!-- wp:quote -->', $result );
        $this->assertStringContainsString( '<blockquote class="wp-block-quote">', $result );
        $this->assertStringContainsString( '<strong>strong</strong>', $result );
    }

    /**
     * Phase 34 — core/separator renders a native hr.
     */
    public function test_core_separator_renders_hr(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/separator' ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( '<!-- wp:separator -->', $result );
        $this->assertStringContainsString( '<hr', $result );
    }

    /**
     * Phase 34 — an arbitrary unknown core/* block must not 422 (graceful
     * pass-through; content-bearing ones fall back to a paragraph).
     */
    public function test_core_unknown_block_passes_through(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/whatever', 'content' => 'hello' ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
    }

    // =========================================================================
    // Phase 37 #1 — container round-trip (read/write symmetry, never empty)
    // =========================================================================

    /**
     * A round-trip container (core/group with inner_blocks) reproduces its
     * wrapper AND its children — never silently emptied (backend backlog).
     */
    public function test_roundtrip_container_preserves_children(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'          => 'core/group',
                    'properties'    => array(),
                    'inner_content' => array( '<div class="wp-block-group">', '</div>' ),
                    'inner_blocks'  => array(
                        array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>Un</p>' ) ),
                        array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>Deux</p>' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
        // Wrapper preserved.
        $this->assertStringContainsString( '<!-- wp:group', $result );
        $this->assertStringContainsString( '<div class="wp-block-group">', $result );
        $this->assertStringContainsString( '<!-- /wp:group -->', $result );
        // Both children present, in order, never dropped.
        $this->assertStringContainsString( '<p>Un</p>', $result );
        $this->assertStringContainsString( '<p>Deux</p>', $result );
        $this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
        $this->assertLessThan(
            strpos( $result, '<p>Deux</p>' ),
            strpos( $result, '<p>Un</p>' ),
            'Children must keep document order.'
        );
    }

    /**
     * Read/write symmetry: a block carrying inner_blocks (the key the READ path
     * emits) is accepted on write without a 422 — the site's own content
     * round-trips instead of being rejected against the registry.
     */
    public function test_roundtrip_block_with_inner_blocks_not_rejected(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'         => 'core/columns',
                    'inner_blocks' => array(
                        array( 'type' => 'acme/third-party', 'inner_content' => array( '<div>x</div>' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        // A third-party nested block must NOT 422 on round-trip (verbatim).
        $this->assertIsString( $result );
        $this->assertStringContainsString( '<!-- wp:columns', $result );
        $this->assertStringContainsString( '<div>x</div>', $result );
    }

    /**
     * A round-trip core/* leaf (text lives in inner_content, not content) is
     * reproduced verbatim — not rendered as an empty node.
     */
    public function test_roundtrip_leaf_paragraph_is_verbatim(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>Bonjour</p>' ) ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
        $this->assertStringContainsString( '<p>Bonjour</p>', $result );
    }

    /**
     * Generation-shape container (children, no inner_blocks) must also never
     * drop its content: the never-empty guard renders the children.
     */
    public function test_generation_container_children_not_dropped(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'     => 'core/group',
                    'children' => array(
                        array( 'type' => 'paragraph', 'content' => 'inside' ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( 'inside', $result );
    }

    // =========================================================================
    // Phase 37 review — round-trip hardening (security + no silent loss)
    // =========================================================================

    /**
     * #1: a forged block `type` containing comment-breakout characters must not
     * escape the `<!-- wp:NAME -->` delimiter — the name is reduced to its slug.
     */
    public function test_roundtrip_block_name_cannot_break_out_of_comment(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'          => "x -->\n<script>alert(document.cookie)</script>\n<!-- wp:y",
                    'inner_content' => array( 'safe' ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
        // No script tag, and no comment-closing breakout from the name.
        $this->assertStringNotContainsString( '<script', $result );
        $this->assertStringNotContainsString( "-->\n<script", $result );
    }

    /**
     * #2: agent-supplied inner_content HTML is sanitised (wp_kses_post) — an
     * injected <script> chunk does not reach stored post_content.
     */
    public function test_roundtrip_inner_content_script_is_stripped(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'          => 'core/group',
                    'inner_content' => array(
                        '<div class="wp-block-group">',
                        '<script>fetch("//evil")</script>',
                        '</div>',
                    ),
                    'inner_blocks'  => array(
                        array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>ok</p>' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringNotContainsString( '<script', $result );
        // The legitimate wrapper + child still survive.
        $this->assertStringContainsString( '<div class="wp-block-group">', $result );
        $this->assertStringContainsString( '<p>ok</p>', $result );
    }

    /**
     * #4: when inner_content carries WP-grammar null placeholders, children are
     * interleaved at their positions — not all forced before the trailing chunks.
     */
    public function test_roundtrip_null_placeholders_interleave_children(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'          => 'core/group',
                    // open, child0, raw <hr> between, child1, close
                    'inner_content' => array( '<div>', null, '<hr/>', null, '</div>' ),
                    'inner_blocks'  => array(
                        array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>Un</p>' ) ),
                        array( 'type' => 'core/paragraph', 'inner_content' => array( '<p>Deux</p>' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        // Order must be Un, then <hr>, then Deux (interleaved, not Un/Deux/<hr>).
        $pos_un   = strpos( $result, '<p>Un</p>' );
        $pos_hr   = strpos( $result, '<hr' );
        $pos_deux = strpos( $result, '<p>Deux</p>' );
        $this->assertNotFalse( $pos_un );
        $this->assertNotFalse( $pos_hr );
        $this->assertNotFalse( $pos_deux );
        $this->assertLessThan( $pos_hr, $pos_un, 'First child before the interleaved <hr>.' );
        $this->assertLessThan( $pos_deux, $pos_hr, 'Interleaved <hr> before the second child.' );
    }

    /**
     * #3: a content-less core/* leaf the renderer has no native method for is
     * preserved as a native block comment, not silently dropped.
     */
    public function test_unrenderable_core_leaf_is_preserved(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'core/spacer', 'properties' => array( 'height' => '50px' ) ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
        $this->assertStringContainsString( '<!-- wp:spacer', $result );
    }

    /**
     * #5: a malformed nested child (unknown non-namespaced type) inside a
     * round-trip container surfaces a 422 instead of vanishing at render.
     */
    public function test_roundtrip_child_unknown_type_is_rejected(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'         => 'core/group',
                    'inner_blocks' => array(
                        array( 'type' => 'definitely_not_a_block' ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * #5: a self-closing third-party leaf nested in a round-trip container is the
     * site's own content — accepted (not 422'd) and preserved as native markup.
     */
    public function test_roundtrip_child_third_party_leaf_preserved(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'         => 'core/group',
                    'inner_blocks' => array(
                        array( 'type' => 'acme/spacer', 'properties' => array( 'size' => 'L' ) ),
                    ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertIsString( $result );
        $this->assertStringContainsString( '<!-- wp:acme/spacer', $result );
    }

    /**
     * #9: a fallback block whose content is the single character "0" must render
     * (empty('0') === true would have dropped it).
     */
    public function test_zero_content_is_not_dropped(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'paragraph', 'content' => '0' ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( '0', $result );
    }

    /**
     * #13: a malformed payload mixing `content` with a stray inner_content renders
     * the `content` (generation discriminator) rather than dropping it.
     */
    public function test_content_takes_precedence_over_stray_inner_content(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'          => 'paragraph',
                    'content'       => 'real text',
                    'inner_content' => array( '<p>stray</p>' ),
                ),
            ),
        );

        $result = $blocks->json_to_blocks( $json );

        $this->assertStringContainsString( 'real text', $result );
    }

}
