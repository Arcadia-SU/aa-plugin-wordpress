<?php
/**
 * Tests for the `stored` contract: an unchanged block goes back byte for byte.
 *
 * Incident (2026-11-05, backend bead aa-ywq8c): on iSelection post 114236 a PUT
 * that changed one figure in two blocks re-rendered six other acf/* blocks the
 * client had written by hand (`\r\n` → `\n`, bare text wrapped in `<p>`, an
 * acf/title inside a `<p>`). The read now hands each top-level block its slice
 * of post_content, and the write copies a slice it recognises instead of
 * rendering the block again.
 *
 * The real-WordPress half (parse_blocks, the REST handlers, kses as a user
 * without unfiltered_html) is case 5 of test/fidelity-check.php.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/includes/class-markdown-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';

/**
 * Test class for Arcadia_Block_Slicer and the stored write path.
 */
class StoredBlocksTest extends TestCase {

    /**
     * The incident post, as stored before the push (from the backend fixture).
     *
     * @var string
     */
    private $fixture;

    /**
     * Set up.
     */
    protected function setUp(): void {
        $this->fixture = file_get_contents( dirname( __DIR__ ) . '/fixtures/iselection-114236.html' );

        foreach ( array( 'Arcadia_Block_Registry', 'Arcadia_Blocks' ) as $klass ) {
            $ref  = new ReflectionClass( $klass );
            $prop = $ref->getProperty( 'instance' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }

    // =========================================================================
    // Read: Arcadia_Block_Slicer
    // =========================================================================

    /**
     * The slices of the incident post put back together are the post.
     */
    public function test_slices_concatenate_to_the_post_byte_for_byte(): void {
        $slices = \Arcadia_Block_Slicer::top_level_slices( $this->fixture );

        $this->assertNotNull( $slices );
        $this->assertCount( 28, $slices );
        $this->assertSame( $this->fixture, implode( '', array_column( $slices, 'markup' ) ) );
    }

    /**
     * The incident post's \r\n inside ACF data survive in the slice.
     */
    public function test_slices_keep_crlf_escapes_of_acf_data(): void {
        $slices = \Arcadia_Block_Slicer::top_level_slices( $this->fixture );

        $this->assertSame( 'acf/alert', $slices[1]['name'] );
        $this->assertStringContainsString( '\r\n \t' . '\\' . 'u003cli' . '\\' . 'u003e', $slices[1]['markup'] );
    }

    /**
     * Text before the first block and between blocks belongs to a slice.
     */
    public function test_free_text_is_carried_by_the_neighbouring_slice(): void {
        $content = "intro\n<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<hr>\n\n<!-- wp:acf/title {\"name\":\"acf/title\"} /-->\n";

        $slices = \Arcadia_Block_Slicer::top_level_slices( $content );

        $this->assertSame(
            array(
                array(
                    'name'   => 'core/paragraph',
                    'markup' => "intro\n<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<hr>\n\n",
                ),
                array(
                    'name'   => 'acf/title',
                    'markup' => "<!-- wp:acf/title {\"name\":\"acf/title\"} /-->\n",
                ),
            ),
            $slices
        );
    }

    /**
     * A container is one slice: its inner blocks are not cut out of it.
     */
    public function test_nested_blocks_stay_inside_their_parent_slice(): void {
        $list    = "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>Un</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n";
        $content = $list . "<!-- wp:separator /-->";

        $slices = \Arcadia_Block_Slicer::top_level_slices( $content );

        $this->assertSame( array( 'core/list', 'core/separator' ), array_column( $slices, 'name' ) );
        $this->assertSame( $list, $slices[0]['markup'] );
    }

    /**
     * Content core would parse differently yields no slices at all.
     */
    public function test_untrustworthy_content_yields_no_slices(): void {
        $this->assertNull( \Arcadia_Block_Slicer::top_level_slices( '' ) );
        $this->assertNull( \Arcadia_Block_Slicer::top_level_slices( '<p>classic editor</p>' ) );
        $this->assertNull( \Arcadia_Block_Slicer::top_level_slices( "<!-- /wp:paragraph -->\n<!-- wp:separator /-->" ) );
    }

    /**
     * Slices are attached to the formatted blocks only when they line up.
     */
    public function test_attach_stored_is_all_or_nothing(): void {
        $content = "<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:separator /-->";
        $blocks  = array(
            array( 'blockName' => 'core/paragraph' ),
            array( 'blockName' => 'core/separator' ),
        );

        $attached = \Arcadia_Block_Slicer::attach_stored( $blocks, $content );
        $this->assertSame( "<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n", $attached[0]['stored'] );
        $this->assertSame( '<!-- wp:separator /-->', $attached[1]['stored'] );

        // Names disagree with the formatter: no block gets a slice.
        $wrong = array(
            array( 'blockName' => 'core/paragraph' ),
            array( 'blockName' => 'core/quote' ),
        );
        $this->assertSame( $wrong, \Arcadia_Block_Slicer::attach_stored( $wrong, $content ) );

        // Counts disagree: same.
        $short = array( array( 'blockName' => 'core/paragraph' ) );
        $this->assertSame( $short, \Arcadia_Block_Slicer::attach_stored( $short, $content ) );
    }

    // =========================================================================
    // The incident itself
    // =========================================================================

    /**
     * Re-rendering a client block from its data does not give its bytes back.
     *
     * This is why `stored` exists: the ACF adapter sends the acf/alert wysiwyg
     * through parse_rich(), and the `\r\n` the client's editor stored are gone.
     */
    public function test_acf_rerender_is_the_incident(): void {
        global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
        $_test_acf_block_types     = array( 'acf/alert' => array( 'name' => 'acf/alert', 'title' => 'Alert' ) );
        $_test_acf_field_groups    = array(
            array(
                'key'      => 'group_acf_alert',
                'title'    => 'Alert',
                'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/alert' ) ) ),
            ),
        );
        $_test_acf_fields_by_group = array(
            'group_acf_alert' => array(
                array( 'name' => 'text', 'type' => 'wysiwyg', 'key' => 'field_689c216ff48bf', 'label' => 'Text', 'required' => false ),
                array( 'name' => 'title', 'type' => 'wysiwyg', 'key' => 'field_689c2156f48be', 'label' => 'Title', 'required' => false ),
            ),
        );

        $stored = \Arcadia_Block_Slicer::top_level_slices( $this->fixture )[1]['markup'];
        $parsed = json_decode( preg_replace( '/^<!-- wp:acf\/alert (.*) \/-->\s*$/s', '$1', $stored ), true );
        $this->assertIsArray( $parsed['data'] ?? null );

        $rerendered = ( new \Arcadia_ACF_Adapter() )->custom_block( 'acf/alert', $parsed['data'] );

        $this->assertNotSame( $stored, $rerendered );
        $this->assertStringContainsString( '\r\n', $stored );
        $this->assertStringNotContainsString( '\r\n', $rerendered );
    }

    // =========================================================================
    // Write: Arcadia_Blocks::json_to_blocks() with `stored`
    // =========================================================================

    /**
     * Build the PUT children AA sends for an unchanged post: every block with
     * its stored slice (type kept, properties deliberately bogus — they must
     * not be looked at).
     *
     * @param string $content Post content.
     * @return array
     */
    private function unchanged_children( $content ) {
        return array_map(
            static fn( $slice ) => array(
                'type'       => $slice['name'],
                'properties' => array( 'not' => 'rendered' ),
                'stored'     => $slice['markup'],
            ),
            \Arcadia_Block_Slicer::top_level_slices( $content )
        );
    }

    /**
     * Read then write back unchanged: the post comes out byte for byte.
     */
    public function test_unchanged_push_rewrites_the_post_byte_for_byte(): void {
        $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array( 'children' => $this->unchanged_children( $this->fixture ) ),
            'post',
            false,
            $this->fixture,
            $warnings
        );

        $this->assertSame( $this->fixture, $written );
        $this->assertSame( array(), $warnings );
    }

    /**
     * One block modified: only its bytes change, the others stay stored.
     */
    public function test_only_the_modified_block_is_rendered(): void {
        $children    = $this->unchanged_children( $this->fixture );
        $children[3] = array(
            'type'    => 'core/paragraph',
            'content' => 'Nouveau chiffre : 42 %',
        );

        $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array( 'children' => $children ),
            'post',
            false,
            $this->fixture,
            $warnings
        );

        $slices   = array_column( \Arcadia_Block_Slicer::top_level_slices( $this->fixture ), 'markup' );
        $rendered = \Arcadia_Blocks::get_instance()->json_to_blocks( array( 'children' => array( $children[3] ) ) );
        $expected = implode( '', array_slice( $slices, 0, 3 ) ) . $rendered . implode( '', array_slice( $slices, 4 ) );

        $this->assertSame( $expected, $written );
        $this->assertStringContainsString( 'Nouveau chiffre : 42 %', $written );
        $this->assertSame( array(), $warnings );
    }

    /**
     * A stored block skips validation: it is the site's content, not generation.
     */
    public function test_stored_block_is_not_validated(): void {
        $content = '<!-- wp:acf/never-registered {"name":"acf/never-registered"} /-->';
        $block   = array(
            'type'   => 'acf/never-registered',
            'stored' => $content,
        );

        $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array( 'children' => array( $block ) ),
            'post',
            false,
            $content
        );

        $this->assertSame( $content, $written );
    }

    /**
     * A `stored` the post does not contain is dropped, with a warning, and the
     * block goes through the normal path — including its validation.
     */
    public function test_unknown_stored_is_rendered_and_reported(): void {
        $block = array(
            'type'    => 'core/paragraph',
            'content' => 'Rendu',
            'stored'  => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->',
        );

        $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array( 'children' => array( $block ) ),
            'post',
            false,
            $this->fixture,
            $warnings
        );

        $this->assertStringNotContainsString( '<script>', $written );
        $this->assertStringContainsString( 'Rendu', $written );
        $this->assertCount( 1, $warnings );
        $this->assertStringContainsString( 'children[0] (core/paragraph)', $warnings[0] );
        $this->assertStringContainsString( 'does not match the current content', $warnings[0] );

        // Same arbitrary markup on an unregistered type: the normal path refuses it.
        $refused = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array(
                'children' => array(
                    array(
                        'type'   => 'acf/never-registered',
                        'stored' => '<!-- wp:acf/never-registered /-->',
                    ),
                ),
            ),
            'post',
            false,
            $this->fixture
        );
        $this->assertInstanceOf( \WP_Error::class, $refused );
        $this->assertSame( 'unknown_block_type', $refused->get_error_code() );
    }

    /**
     * Create mode accepts no `stored`: there is no post content to match.
     */
    public function test_stored_is_ignored_on_create(): void {
        $block = array(
            'type'    => 'core/paragraph',
            'content' => 'Rendu',
            'stored'  => $this->fixture,
        );

        $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
            array( 'children' => array( $block ) ),
            'post',
            false,
            null,
            $warnings
        );

        $this->assertStringNotContainsString( 'acf/alert', $written );
        $this->assertCount( 1, $warnings );
        $this->assertStringContainsString( 'ignored when creating a post', $warnings[0] );
    }

    /**
     * Empty or non-string `stored` never matches (an empty string is in every post).
     */
    public function test_empty_or_non_string_stored_never_matches(): void {
        foreach ( array( '', null, 42, array( 'x' ) ) as $stored ) {
            $written = \Arcadia_Blocks::get_instance()->json_to_blocks(
                array(
                    'children' => array(
                        array(
                            'type'    => 'core/paragraph',
                            'content' => 'Rendu',
                            'stored'  => $stored,
                        ),
                    ),
                ),
                'post',
                false,
                $this->fixture,
                $warnings
            );

            $this->assertStringContainsString( 'Rendu', $written, var_export( $stored, true ) );
            $this->assertCount( 1, $warnings, var_export( $stored, true ) );
        }
    }
}
