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

    // =========================================================================
    // Dual-write: ACF block data collected for post_meta (P22)
    // =========================================================================

    /**
     * Test json_to_blocks collects ACF block properties for dual-write.
     */
    public function test_acf_block_data_collected_after_json_to_blocks(): void {
        global $_test_acf_block_types;
        $_test_acf_block_types = array(
            'acf/faq' => array( 'title' => 'FAQ Block' ),
        );

        // Reset singletons so they pick up the new ACF block type.
        $ref = new \ReflectionClass( \Arcadia_Block_Registry::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $ref = new \ReflectionClass( \Arcadia_Blocks::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'       => 'acf/faq',
                    'properties' => array(
                        'faq' => array(
                            array( 'title' => 'Q1', 'text' => 'A1' ),
                        ),
                        'title' => 'FAQ',
                    ),
                ),
                array(
                    'type'    => 'paragraph',
                    'content' => 'Regular paragraph',
                ),
            ),
        );

        $blocks->json_to_blocks( $json );

        // Use reflection to check collected acf_block_data.
        $ref  = new \ReflectionClass( $blocks );
        $prop = $ref->getProperty( 'acf_block_data' );
        $prop->setAccessible( true );
        $data = $prop->getValue( $blocks );

        $this->assertCount( 1, $data );
        $this->assertEquals( 'acf/faq', $data[0]['block_name'] );
        $this->assertIsArray( $data[0]['properties']['faq'] );
        $this->assertEquals( 'FAQ', $data[0]['properties']['title'] );
    }

    /**
     * Test write_acf_block_meta writes properties to post_meta.
     */
    public function test_write_acf_block_meta_calls_update_field(): void {
        global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
        global $_test_acf_update_field_calls;

        $_test_acf_update_field_calls = array();

        // Register an ACF block with known field keys.
        $_test_acf_block_types = array(
            'acf/faq' => array( 'title' => 'FAQ Block' ),
        );
        $_test_acf_field_groups = array(
            array(
                'key'      => 'group_faq',
                'title'    => 'FAQ Fields',
                'location' => array(
                    array(
                        array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/faq' ),
                    ),
                ),
            ),
        );
        $_test_acf_fields_by_group = array(
            'group_faq' => array(
                array( 'name' => 'faq', 'type' => 'repeater', 'key' => 'field_faq_rep', 'required' => 0, 'label' => 'FAQ' ),
                array( 'name' => 'title', 'type' => 'text', 'key' => 'field_faq_title', 'required' => 0, 'label' => 'Title' ),
            ),
        );

        // Reset registry to pick up new stubs.
        $ref  = new \ReflectionClass( \Arcadia_Block_Registry::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        // Reset Blocks singleton.
        $ref  = new \ReflectionClass( \Arcadia_Blocks::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'       => 'acf/faq',
                    'properties' => array(
                        'faq'   => array( array( 'title' => 'Q1', 'text' => 'A1' ) ),
                        'title' => 'FAQ Section',
                    ),
                ),
            ),
        );

        $blocks->json_to_blocks( $json );
        $blocks->write_acf_block_meta( 12345 );

        // Verify update_field was called with correct field keys.
        $this->assertNotEmpty( $_test_acf_update_field_calls );

        $field_keys_written = array_column( $_test_acf_update_field_calls, 'field_name' );
        $this->assertContains( 'field_faq_rep', $field_keys_written );
        $this->assertContains( 'field_faq_title', $field_keys_written );

        // Verify post_id was correct.
        foreach ( $_test_acf_update_field_calls as $call ) {
            $this->assertEquals( 12345, $call['post_id'] );
        }
    }

    /**
     * Test non-ACF blocks are not collected.
     */
    public function test_non_acf_blocks_not_collected(): void {
        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array( 'type' => 'paragraph', 'content' => 'text' ),
                array( 'type' => 'heading', 'content' => 'title', 'level' => 2 ),
            ),
        );

        $blocks->json_to_blocks( $json );

        $ref  = new \ReflectionClass( $blocks );
        $prop = $ref->getProperty( 'acf_block_data' );
        $prop->setAccessible( true );
        $data = $prop->getValue( $blocks );

        $this->assertEmpty( $data );
    }

    /**
     * Test nested ACF blocks in sections are collected.
     */
    public function test_nested_acf_blocks_collected(): void {
        global $_test_acf_block_types;
        $_test_acf_block_types = array(
            'acf/hero' => array( 'title' => 'Hero Block' ),
        );

        $ref = new \ReflectionClass( \Arcadia_Block_Registry::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $ref = new \ReflectionClass( \Arcadia_Blocks::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $blocks = \Arcadia_Blocks::get_instance();

        $json = array(
            'children' => array(
                array(
                    'type'     => 'section',
                    'heading'  => 'Section 1',
                    'children' => array(
                        array(
                            'type'       => 'acf/hero',
                            'properties' => array( 'image' => 42, 'text' => 'Hello' ),
                        ),
                    ),
                ),
            ),
        );

        $blocks->json_to_blocks( $json );

        $ref  = new \ReflectionClass( $blocks );
        $prop = $ref->getProperty( 'acf_block_data' );
        $prop->setAccessible( true );
        $data = $prop->getValue( $blocks );

        $this->assertCount( 1, $data );
        $this->assertEquals( 'acf/hero', $data[0]['block_name'] );
    }
}
