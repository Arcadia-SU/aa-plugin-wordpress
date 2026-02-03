<?php
/**
 * Tests for Arcadia_Blocks class.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load the class file directly for testing parse_markdown.
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

        $result = \Arcadia_Blocks::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test italic markdown conversion.
     */
    public function test_parse_markdown_italic(): void {
        $input    = 'This is *italic* text.';
        $expected = 'This is <em>italic</em> text.';

        $result = \Arcadia_Blocks::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test inline code markdown conversion.
     */
    public function test_parse_markdown_code(): void {
        $input    = 'Use `console.log()` for debugging.';
        $expected = 'Use <code>console.log()</code> for debugging.';

        $result = \Arcadia_Blocks::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test link markdown conversion.
     */
    public function test_parse_markdown_link(): void {
        $input  = 'Visit [example](https://example.com) for more.';
        $result = \Arcadia_Blocks::parse_markdown( $input );

        $this->assertStringContainsString( '<a href="https://example.com"', $result );
        $this->assertStringContainsString( '>example</a>', $result );
    }

    /**
     * Test external link gets target="_blank".
     */
    public function test_parse_markdown_external_link(): void {
        $input  = 'Visit [Google](https://google.com) site.';
        $result = \Arcadia_Blocks::parse_markdown( $input );

        $this->assertStringContainsString( 'target="_blank"', $result );
        $this->assertStringContainsString( 'rel="noopener noreferrer"', $result );
    }

    /**
     * Test internal link doesn't get target="_blank".
     */
    public function test_parse_markdown_internal_link(): void {
        $input  = 'Go to [home](http://localhost/page) page.';
        $result = \Arcadia_Blocks::parse_markdown( $input );

        $this->assertStringNotContainsString( 'target="_blank"', $result );
    }

    /**
     * Test combined markdown formatting.
     */
    public function test_parse_markdown_combined(): void {
        $input = 'Text with **bold**, *italic*, and `code`.';
        $result = \Arcadia_Blocks::parse_markdown( $input );

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

        $result = \Arcadia_Blocks::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test code escapes HTML inside.
     */
    public function test_parse_markdown_code_escapes_html(): void {
        $input  = 'Use `<script>alert("xss")</script>` tag.';
        $result = \Arcadia_Blocks::parse_markdown( $input );

        $this->assertStringContainsString( '&lt;script&gt;', $result );
        $this->assertStringNotContainsString( '<script>', $result );
    }

    /**
     * Test no markdown in plain text.
     */
    public function test_parse_markdown_plain_text(): void {
        $input    = 'Just plain text without markdown.';
        $expected = 'Just plain text without markdown.';

        $result = \Arcadia_Blocks::parse_markdown( $input );
        $this->assertEquals( $expected, $result );
    }

    /**
     * Test multiple links in same text.
     */
    public function test_parse_markdown_multiple_links(): void {
        $input = 'Visit [one](https://one.com) and [two](https://two.com).';
        $result = \Arcadia_Blocks::parse_markdown( $input );

        $this->assertStringContainsString( 'href="https://one.com"', $result );
        $this->assertStringContainsString( 'href="https://two.com"', $result );
        $this->assertStringContainsString( '>one</a>', $result );
        $this->assertStringContainsString( '>two</a>', $result );
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
}
