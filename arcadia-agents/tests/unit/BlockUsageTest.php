<?php
/**
 * Tests for GET /blocks/usage endpoint.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load the trait under test.
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-blocks.php';

/**
 * Test harness that exposes the trait methods.
 */
class BlockUsageTestHarness {
	use \Arcadia_API_Blocks_Handler;

	/**
	 * Mock blocks handler (unused for usage endpoint).
	 */
	private $blocks;

	/**
	 * Mock registry (unused for usage endpoint).
	 */
	private $registry;

	/**
	 * Public proxy for get_blocks_usage().
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function test_get_blocks_usage( $request ) {
		return $this->get_blocks_usage( $request );
	}
}

/**
 * Test class for blocks usage endpoint.
 */
class BlockUsageTest extends TestCase {

	/**
	 * Test harness instance.
	 *
	 * @var BlockUsageTestHarness
	 */
	private $harness;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		$this->harness = new BlockUsageTestHarness();

		// Reset globals.
		global $_test_options, $_test_parse_blocks_results;
		$_test_options              = array();
		$_test_parse_blocks_results = array();

		\WP_Query::reset();
	}

	/**
	 * Create a mock post object.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 * @param string $content Post content (used as parse_blocks key).
	 * @return object
	 */
	private function make_post( $id, $title, $content = '' ) {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => $content,
			'post_type'    => 'post',
			'post_status'  => 'publish',
		);
	}

	/**
	 * Create a request with optional params.
	 *
	 * @param array $params Request params.
	 * @return \WP_REST_Request
	 */
	private function make_request( $params = array() ) {
		$request = new \WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// =========================================================================
	// Test cases
	// =========================================================================

	/**
	 * Test empty site returns zero posts and empty blocks array.
	 */
	public function test_empty_site_returns_zero_posts(): void {
		\WP_Query::set_next_result( array() );

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 0, $data['total_posts_analyzed'] );
		$this->assertEmpty( $data['blocks'] );
	}

	/**
	 * Test single post with one block type returns correct stats.
	 */
	public function test_single_post_single_block(): void {
		global $_test_parse_blocks_results;

		$post    = $this->make_post( 1, 'Test Post', 'content_1' );
		$blocks  = array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array( 'align' => 'center' ),
				'innerBlocks' => array(),
			),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['content_1'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertEquals( 1, $data['total_posts_analyzed'] );
		$this->assertCount( 1, $data['blocks'] );
		$this->assertEquals( 'core/paragraph', $data['blocks'][0]['type'] );
		$this->assertEquals( 1, $data['blocks'][0]['count'] );
		$this->assertEquals( 1, $data['blocks'][0]['posts_with_block'] );

		// Check example.
		$example = $data['blocks'][0]['examples'][0];
		$this->assertEquals( 1, $example['post_id'] );
		$this->assertEquals( 'Test Post', $example['post_title'] );
		$this->assertEquals( (object) array( 'align' => 'center' ), $example['block_data'] );
	}

	/**
	 * Test multiple blocks of the same type in one post aggregate count.
	 */
	public function test_multiple_blocks_same_type_aggregate_count(): void {
		global $_test_parse_blocks_results;

		$post   = $this->make_post( 1, 'Post 1', 'content_agg' );
		$blocks = array(
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['content_agg'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertEquals( 3, $data['blocks'][0]['count'] );
		$this->assertEquals( 1, $data['blocks'][0]['posts_with_block'] );
	}

	/**
	 * Test multiple posts contribute distinct posts_with_block counts.
	 */
	public function test_multiple_posts_distinct_posts_with_block(): void {
		global $_test_parse_blocks_results;

		$post1 = $this->make_post( 1, 'Post 1', 'c1' );
		$post2 = $this->make_post( 2, 'Post 2', 'c2' );

		\WP_Query::set_next_result( array( $post1, $post2 ) );

		$_test_parse_blocks_results['c1'] = array(
			array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array() ),
		);
		$_test_parse_blocks_results['c2'] = array(
			array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertEquals( 2, $data['total_posts_analyzed'] );
		$this->assertEquals( 2, $data['blocks'][0]['count'] );
		$this->assertEquals( 2, $data['blocks'][0]['posts_with_block'] );
	}

	/**
	 * Test sample_size limits the number of examples collected.
	 */
	public function test_sample_size_limits_examples(): void {
		global $_test_parse_blocks_results;

		// Create 5 posts, each with a paragraph block.
		$posts = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$posts[]                                     = $this->make_post( $i, "Post $i", "cs_$i" );
			$_test_parse_blocks_results[ "cs_$i" ] = array(
				array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			);
		}

		\WP_Query::set_next_result( $posts );

		// Request with sample_size=2.
		$response = $this->harness->test_get_blocks_usage( $this->make_request( array( 'sample_size' => 2 ) ) );
		$data     = $response->get_data();

		$this->assertEquals( 5, $data['blocks'][0]['count'] );
		$this->assertCount( 2, $data['blocks'][0]['examples'] );
	}

	/**
	 * Test sample_size is clamped to [1, 10].
	 */
	public function test_sample_size_clamped(): void {
		global $_test_parse_blocks_results;

		// 15 posts to exceed max sample_size of 10.
		$posts = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$posts[]                                   = $this->make_post( $i, "P$i", "clamp_$i" );
			$_test_parse_blocks_results[ "clamp_$i" ] = array(
				array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			);
		}

		\WP_Query::set_next_result( $posts );

		// sample_size=99 should be clamped to 10.
		$response = $this->harness->test_get_blocks_usage( $this->make_request( array( 'sample_size' => 99 ) ) );
		$data     = $response->get_data();

		$this->assertCount( 10, $data['blocks'][0]['examples'] );

		// Reset and test sample_size=0 clamped to 1.
		\WP_Query::set_next_result( $posts );
		global $_test_options;
		$_test_options = array(); // Clear cache.

		$response = $this->harness->test_get_blocks_usage( $this->make_request( array( 'sample_size' => 0 ) ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['blocks'][0]['examples'] );
	}

	/**
	 * Test nested innerBlocks are collected with correct parent context.
	 */
	public function test_nested_inner_blocks_with_parent_context(): void {
		global $_test_parse_blocks_results;

		$post   = $this->make_post( 1, 'Nested', 'nested_content' );
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array( 'layout' => 'flex' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/heading',
						'attrs'       => array( 'level' => 2 ),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/paragraph',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				),
			),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['nested_content'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		// 3 block types: group, heading, paragraph.
		$this->assertCount( 3, $data['blocks'] );

		// Find the heading block.
		$heading = null;
		foreach ( $data['blocks'] as $b ) {
			if ( 'core/heading' === $b['type'] ) {
				$heading = $b;
				break;
			}
		}

		$this->assertNotNull( $heading );
		$this->assertEquals( 1, $heading['count'] );
		$this->assertEquals( 'core/group', $heading['examples'][0]['context']['parent_block'] );
	}

	/**
	 * Test null blockName blocks are skipped.
	 */
	public function test_null_block_name_skipped(): void {
		global $_test_parse_blocks_results;

		$post   = $this->make_post( 1, 'Whitespace', 'ws_content' );
		$blocks = array(
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array() ),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['ws_content'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['blocks'] );
		$this->assertEquals( 'core/paragraph', $data['blocks'][0]['type'] );
		$this->assertEquals( 1, $data['blocks'][0]['count'] );
	}

	/**
	 * Test blocks are sorted by count descending.
	 */
	public function test_sorted_by_count_descending(): void {
		global $_test_parse_blocks_results;

		$post   = $this->make_post( 1, 'Sort Test', 'sort_content' );
		$blocks = array(
			array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['sort_content'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertEquals( 'core/paragraph', $data['blocks'][0]['type'] );
		$this->assertEquals( 3, $data['blocks'][0]['count'] );
		$this->assertEquals( 'core/image', $data['blocks'][1]['type'] );
		$this->assertEquals( 2, $data['blocks'][1]['count'] );
		$this->assertEquals( 'core/heading', $data['blocks'][2]['type'] );
		$this->assertEquals( 1, $data['blocks'][2]['count'] );
	}

	/**
	 * Test null block_data attrs fallback to empty object.
	 */
	public function test_null_attrs_fallback_to_empty_object(): void {
		global $_test_parse_blocks_results;

		$post   = $this->make_post( 1, 'No Attrs', 'null_attrs' );
		$blocks = array(
			array( 'blockName' => 'core/paragraph', 'attrs' => null, 'innerBlocks' => array() ),
		);

		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['null_attrs'] = $blocks;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		$this->assertEquals( (object) array(), $data['blocks'][0]['examples'][0]['block_data'] );
	}

	/**
	 * Test transient cache is used when present.
	 */
	public function test_transient_cache_used_when_present(): void {
		global $_test_options;

		$cached_data = array(
			'total_posts_analyzed' => 99,
			'blocks'              => array(
				array( 'type' => 'cached/block', 'count' => 50 ),
			),
		);

		// Set the transient directly.
		$_test_options['_transient_arcadia_blocks_usage_all_3'] = $cached_data;

		$response = $this->harness->test_get_blocks_usage( $this->make_request() );
		$data     = $response->get_data();

		// Should return cached data without querying.
		$this->assertEquals( 99, $data['total_posts_analyzed'] );
		$this->assertEquals( 'cached/block', $data['blocks'][0]['type'] );
	}

	/**
	 * Test post_type filter is passed to cache key.
	 */
	public function test_post_type_filter(): void {
		global $_test_parse_blocks_results;

		$post = $this->make_post( 1, 'Filtered', 'filtered_content' );
		\WP_Query::set_next_result( array( $post ) );
		$_test_parse_blocks_results['filtered_content'] = array(
			array( 'blockName' => 'acf/text', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		$response = $this->harness->test_get_blocks_usage(
			$this->make_request( array( 'post_type' => 'article' ) )
		);
		$data = $response->get_data();

		$this->assertEquals( 1, $data['total_posts_analyzed'] );
		$this->assertEquals( 'acf/text', $data['blocks'][0]['type'] );

		// Verify the cache was set with the correct key.
		global $_test_options;
		$this->assertArrayHasKey( '_transient_arcadia_blocks_usage_article_3', $_test_options );
	}
}
