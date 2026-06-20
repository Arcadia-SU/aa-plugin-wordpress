<?php
/**
 * Tests for GET /articles/{id}/blocks endpoint.
 *
 * Tests the block parsing and formatting logic.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

// Load the posts trait for format_parsed_blocks testing.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';

// Phase 29: format_parsed_blocks now coerces ACF block field values to canonical
// types via the same helper PUT relies on. Pull in registry + coercer + adapters.
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';

/**
 * Minimal class to expose trait methods for testing.
 */
class ArticleBlocksTestHelper {
    use \Arcadia_API_Formatters;
    use \Arcadia_API_Posts_Handler;
    use \Arcadia_API_Field_Schema_Handler;

    /**
     * Expose the private format_parsed_blocks method for testing.
     *
     * @param array $parsed_blocks Parsed blocks.
     * @return array Formatted blocks.
     */
    public function test_format_parsed_blocks( $parsed_blocks ) {
        $reflection = new \ReflectionMethod( $this, 'format_parsed_blocks' );
        $reflection->setAccessible( true );
        return $reflection->invoke( $this, $parsed_blocks );
    }
}

/**
 * Test class for article blocks endpoint.
 */
class ArticleBlocksTest extends TestCase {

    /**
     * @var ArticleBlocksTestHelper
     */
    private $helper;

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $_test_posts, $_test_parse_blocks_results, $_test_get_fields_results;
        global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

        $_test_posts                = array();
        $_test_parse_blocks_results = array();
        $_test_get_fields_results   = array();
        $_test_acf_block_types      = array();
        $_test_acf_field_groups     = array();
        $_test_acf_fields_by_group  = array();

        // Reset singletons so registry picks up freshly registered ACF blocks.
        foreach ( array( 'Arcadia_Block_Registry', 'Arcadia_Blocks', 'Arcadia_ACF_Validator' ) as $klass ) {
            $ref  = new ReflectionClass( $klass );
            $prop = $ref->getProperty( 'instance' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }

        $this->helper = new ArticleBlocksTestHelper();
    }

    /**
     * Register an ACF block + field schema in the test stubs so the registry
     * exposes it via get_block_schema().
     *
     * @param string $block_name Block name (e.g. 'acf/text-image').
     * @param array  $fields     Field schema (list of {name, type, ...}).
     */
    private function register_acf_block( $block_name, $fields ): void {
        global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

        $short_name = preg_replace( '/^acf\//', '', $block_name );

        $_test_acf_block_types[ $block_name ] = array(
            'title' => ucfirst( $short_name ) . ' Block',
        );

        $group_key = 'group_' . $short_name;

        $_test_acf_field_groups[] = array(
            'key'      => $group_key,
            'title'    => ucfirst( $short_name ) . ' Fields',
            'location' => array(
                array(
                    array( 'param' => 'block', 'operator' => '==', 'value' => $block_name ),
                ),
            ),
        );

        $_test_acf_fields_by_group[ $group_key ] = $fields;
    }

    /**
     * Test 404 when post doesn't exist.
     */
    public function test_get_article_blocks_404_for_missing_post(): void {
        // No post in $_test_posts.
        $request = new \WP_REST_Request();
        $request->set_param( 'id', '999' );

        $result = $this->helper->get_article_blocks( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'post_not_found', $result->get_error_code() );
    }

    /**
     * Test empty blocks for post with no content.
     */
    public function test_get_article_blocks_empty_content(): void {
        global $_test_posts;
        $_test_posts[1] = (object) array(
            'ID'           => 1,
            'post_type'    => 'post',
            'post_content' => '',
            'post_title'   => 'Empty Post',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '1' );

        $result = $this->helper->get_article_blocks( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $data = $result->get_data();
        $this->assertEquals( 1, $data['post_id'] );
        $this->assertEmpty( $data['blocks'] );
    }

    /**
     * Test block structure with a simple block.
     */
    public function test_format_parsed_blocks_simple_structure(): void {
        $parsed = array(
            array(
                'blockName'   => 'core/paragraph',
                'attrs'       => array(),
                'innerBlocks' => array(),
                'innerHTML'   => '<p>Hello World</p>',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertCount( 1, $blocks );
        $this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
        $this->assertEquals( '<p>Hello World</p>', $blocks[0]['innerHTML'] );
    }

    /**
     * Test innerBlocks are included recursively.
     */
    public function test_format_parsed_blocks_with_inner_blocks(): void {
        $parsed = array(
            array(
                'blockName'   => 'core/group',
                'attrs'       => array( 'layout' => 'constrained' ),
                'innerBlocks' => array(
                    array(
                        'blockName'   => 'core/heading',
                        'attrs'       => array( 'level' => 2 ),
                        'innerBlocks' => array(),
                        'innerHTML'   => '<h2>Title</h2>',
                    ),
                    array(
                        'blockName'   => 'core/paragraph',
                        'attrs'       => array(),
                        'innerBlocks' => array(),
                        'innerHTML'   => '<p>Content</p>',
                    ),
                ),
                'innerHTML' => '',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertCount( 1, $blocks );
        $this->assertEquals( 'core/group', $blocks[0]['blockName'] );
        $this->assertCount( 2, $blocks[0]['innerBlocks'] );
        $this->assertEquals( 'core/heading', $blocks[0]['innerBlocks'][0]['blockName'] );
        $this->assertEquals( 2, $blocks[0]['innerBlocks'][0]['attrs']['level'] );
    }

    /**
     * Test null blockName blocks are skipped.
     */
    public function test_format_parsed_blocks_skips_null_blockname(): void {
        $parsed = array(
            array(
                'blockName'   => null,
                'attrs'       => array(),
                'innerBlocks' => array(),
                'innerHTML'   => "\n\n",
            ),
            array(
                'blockName'   => 'core/paragraph',
                'attrs'       => array(),
                'innerBlocks' => array(),
                'innerHTML'   => '<p>Visible</p>',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertCount( 1, $blocks );
        $this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
    }

    /**
     * Test empty attrs returns stdClass (JSON object, not array).
     */
    public function test_format_parsed_blocks_empty_attrs_is_object(): void {
        $parsed = array(
            array(
                'blockName'   => 'core/separator',
                'attrs'       => array(),
                'innerBlocks' => array(),
                'innerHTML'   => '<hr />',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertInstanceOf( \stdClass::class, $blocks[0]['attrs'] );
    }

    // =========================================================================
    // Phase 29: ACF block field values are coerced to canonical PHP types
    // at GET time, mirroring the PUT-side coercion in Arcadia_ACF_Validator.
    // =========================================================================

    /**
     * iSelection regression: acf/text-image with stringy is_lightbox + numeric
     * image must come out canonical (bool + int) — the original Phase 29 trigger.
     */
    public function test_format_parsed_blocks_coerces_acf_text_image_to_canonical(): void {
        $this->register_acf_block(
            'acf/text-image',
            array(
                array( 'name' => 'is_lightbox', 'type' => 'true_false', 'key' => 'field_lightbox' ),
                array( 'name' => 'image',       'type' => 'image',      'key' => 'field_image' ),
                array( 'name' => 'caption',     'type' => 'text',       'key' => 'field_caption' ),
            )
        );

        $parsed = array(
            array(
                'blockName'   => 'acf/text-image',
                'attrs'       => array(
                    'id'   => 'block_xyz',
                    'name' => 'acf/text-image',
                    'mode' => 'preview',
                    'data' => array(
                        'is_lightbox'  => '1',
                        '_is_lightbox' => 'field_lightbox',
                        'image'        => '30225',
                        '_image'       => 'field_image',
                        'caption'      => 'A caption',
                    ),
                ),
                'innerBlocks' => array(),
                'innerHTML'   => '',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertSame( true, $blocks[0]['attrs']['data']['is_lightbox'] );
        $this->assertSame( 30225, $blocks[0]['attrs']['data']['image'] );
        $this->assertSame( 'A caption', $blocks[0]['attrs']['data']['caption'] );
        // Field-key references survive (kept for ACF internal use; AA strips them).
        $this->assertSame( 'field_lightbox', $blocks[0]['attrs']['data']['_is_lightbox'] );
        // Non-data attrs (id/name/mode) untouched.
        $this->assertSame( 'block_xyz', $blocks[0]['attrs']['id'] );
        $this->assertSame( 'preview', $blocks[0]['attrs']['mode'] );
    }

    /**
     * Identity round-trip sentinel: GET output is already canonical, so feeding
     * it back through the coercer is a no-op (idempotence proven end-to-end).
     */
    public function test_format_parsed_blocks_identity_roundtrip(): void {
        $this->register_acf_block(
            'acf/text-image',
            array(
                array( 'name' => 'is_lightbox', 'type' => 'true_false', 'key' => 'field_lightbox' ),
                array( 'name' => 'image',       'type' => 'image',      'key' => 'field_image' ),
            )
        );

        $parsed = array(
            array(
                'blockName'   => 'acf/text-image',
                'attrs'       => array(
                    'data' => array( 'is_lightbox' => '0', 'image' => '0' ),
                ),
                'innerBlocks' => array(),
                'innerHTML'   => '',
            ),
        );

        $first  = $this->helper->test_format_parsed_blocks( $parsed );
        $second = $this->helper->test_format_parsed_blocks( $parsed );
        $this->assertSame( $first, $second );

        // Re-coerce the canonical output: must be a no-op.
        $registry = \Arcadia_Block_Registry::get_instance();
        $coercer  = new \Arcadia_ACF_Coercer();
        $data     = $first[0]['attrs']['data'];
        $coercer->coerce_properties_to_canonical( $data, $registry->get_block_schema( 'acf/text-image' ) );
        $this->assertSame( $first[0]['attrs']['data'], $data );
    }

    /**
     * Non-ACF blocks (core/*, theme blocks without acf/ prefix) are untouched.
     */
    public function test_format_parsed_blocks_leaves_non_acf_blocks_unchanged(): void {
        $parsed = array(
            array(
                'blockName'   => 'core/heading',
                'attrs'       => array( 'level' => 2, 'content' => '1' ),
                'innerBlocks' => array(),
                'innerHTML'   => '<h2>1</h2>',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        $this->assertSame( 2, $blocks[0]['attrs']['level'] );
        // String '1' must NOT be coerced — no ACF schema lookup happens.
        $this->assertSame( '1', $blocks[0]['attrs']['content'] );
    }

    /**
     * Unknown ACF block (not in registry) → pass-through, no crash.
     */
    public function test_format_parsed_blocks_unknown_acf_block_passes_through(): void {
        $parsed = array(
            array(
                'blockName'   => 'acf/never-registered',
                'attrs'       => array(
                    'data' => array( 'flag' => '1' ),
                ),
                'innerBlocks' => array(),
                'innerHTML'   => '',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );

        // No schema → coercer not invoked → string survives verbatim.
        $this->assertSame( '1', $blocks[0]['attrs']['data']['flag'] );
    }

    /**
     * Coercion recurses into innerBlocks (nested ACF blocks get coerced too).
     */
    public function test_format_parsed_blocks_coerces_nested_acf_inner_blocks(): void {
        $this->register_acf_block(
            'acf/inner-card',
            array(
                array( 'name' => 'featured', 'type' => 'true_false', 'key' => 'field_featured' ),
            )
        );

        $parsed = array(
            array(
                'blockName'   => 'core/group',
                'attrs'       => array(),
                'innerBlocks' => array(
                    array(
                        'blockName'   => 'acf/inner-card',
                        'attrs'       => array(
                            'data' => array( 'featured' => '1' ),
                        ),
                        'innerBlocks' => array(),
                        'innerHTML'   => '',
                    ),
                ),
                'innerHTML'   => '',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );
        $this->assertSame( true, $blocks[0]['innerBlocks'][0]['attrs']['data']['featured'] );
    }

    /**
     * Repeater field at GET: rows are walked, sub-field values coerced.
     */
    public function test_format_parsed_blocks_coerces_acf_repeater_rows(): void {
        $this->register_acf_block(
            'acf/faq',
            array(
                array(
                    'name'       => 'list',
                    'type'       => 'repeater',
                    'key'        => 'field_list',
                    'sub_fields' => array(
                        array( 'name' => 'question', 'type' => 'text',       'key' => 'field_q' ),
                        array( 'name' => 'open',     'type' => 'true_false', 'key' => 'field_o' ),
                    ),
                ),
            )
        );

        $parsed = array(
            array(
                'blockName'   => 'acf/faq',
                'attrs'       => array(
                    'data' => array(
                        'list' => array(
                            array( 'question' => 'Q1', 'open' => '1' ),
                            array( 'question' => 'Q2', 'open' => '0' ),
                        ),
                    ),
                ),
                'innerBlocks' => array(),
                'innerHTML'   => '',
            ),
        );

        $blocks = $this->helper->test_format_parsed_blocks( $parsed );
        $this->assertSame( true,  $blocks[0]['attrs']['data']['list'][0]['open'] );
        $this->assertSame( false, $blocks[0]['attrs']['data']['list'][1]['open'] );
    }

    // =========================================================================
    // Phase 33 — field_values in GET /articles/{id}/blocks
    // =========================================================================

    /**
     * Blocks response carries post-level field_values (ACF), so a consumer reads
     * structure + field_values in one call instead of a second listing request.
     */
    public function test_get_article_blocks_includes_field_values(): void {
        global $_test_posts, $_test_parse_blocks_results, $_test_get_fields_results;

        $content        = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';
        $_test_posts[7] = (object) array(
            'ID'           => 7,
            'post_type'    => 'post',
            'post_content' => $content,
            'post_title'   => 'With ACF',
        );
        $_test_parse_blocks_results[ $content ] = array(
            array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>Hi</p>', 'innerBlocks' => array() ),
        );
        $_test_get_fields_results[7] = array( 'subtitle' => 'A subtitle', 'rating' => 5 );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '7' );

        $result = $this->helper->get_article_blocks( $request );
        $data   = $result->get_data();

        $this->assertArrayHasKey( 'field_values', $data );
        $this->assertEquals( 'A subtitle', $data['field_values']->subtitle );
        $this->assertEquals( 5, $data['field_values']->rating );
        $this->assertNotEmpty( $data['blocks'] );
    }

    /**
     * field_values is present even when the post has no block content.
     */
    public function test_get_article_blocks_empty_content_includes_field_values(): void {
        global $_test_posts, $_test_get_fields_results;

        $_test_posts[8]              = (object) array(
            'ID'           => 8,
            'post_type'    => 'post',
            'post_content' => '',
            'post_title'   => 'Empty',
        );
        $_test_get_fields_results[8] = array( 'color' => 'red' );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '8' );

        $result = $this->helper->get_article_blocks( $request );
        $data   = $result->get_data();

        $this->assertEmpty( $data['blocks'] );
        $this->assertEquals( 'red', $data['field_values']->color );
    }

    /**
     * No ACF on the post → field_values is an empty object (not null or array),
     * matching the listing's contract.
     */
    public function test_get_article_blocks_no_acf_returns_empty_field_values_object(): void {
        global $_test_posts;

        $_test_posts[9] = (object) array(
            'ID'           => 9,
            'post_type'    => 'post',
            'post_content' => '',
            'post_title'   => 'No ACF',
        );

        $request = new \WP_REST_Request();
        $request->set_param( 'id', '9' );

        $result = $this->helper->get_article_blocks( $request );
        $data   = $result->get_data();

        $this->assertInstanceOf( \stdClass::class, $data['field_values'] );
        $this->assertEquals( new \stdClass(), $data['field_values'] );
    }
}
