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

// Load the posts trait for format_parsed_blocks testing.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-formatters.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-posts.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-field-schema.php';

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
        global $_test_posts, $_test_parse_blocks_results;
        $_test_posts                = array();
        $_test_parse_blocks_results = array();
        $this->helper               = new ArticleBlocksTestHelper();
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
}
