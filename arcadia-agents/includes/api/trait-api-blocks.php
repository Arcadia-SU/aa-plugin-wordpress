<?php
/**
 * Blocks discovery and usage API handlers.
 *
 * Provides the GET /blocks endpoint for block type introspection
 * and GET /blocks/usage for block usage analysis across posts.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Blocks_Handler
 *
 * Provides methods for handling blocks endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Blocks_Handler {

	/**
	 * Get available block types.
	 *
	 * Returns builtin and custom blocks with their field schemas.
	 * Custom blocks are discovered dynamically via ACF or Gutenberg introspection.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_blocks( $request ) {
		return new WP_REST_Response(
			array(
				'adapter' => $this->blocks->get_adapter_name(),
				'blocks'  => array(
					'builtin' => $this->registry->get_builtin_blocks(),
					'custom'  => $this->registry->get_custom_blocks(),
				),
			),
			200
		);
	}

	/**
	 * Get block usage statistics across published posts.
	 *
	 * Analyzes the most recent published posts and returns which block types
	 * are used, how often, and provides sample examples with context.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_blocks_usage( $request ) {
		$post_type   = $request->get_param( 'post_type' );
		$sample_size = (int) ( $request->get_param( 'sample_size' ) ?? 3 );
		$sample_size = max( 1, min( 10, $sample_size ) );

		// Build cache key.
		$cache_post_type = ! empty( $post_type ) ? sanitize_text_field( $post_type ) : 'all';
		$cache_key       = 'arcadia_blocks_usage_' . $cache_post_type . '_' . $sample_size;

		// Check transient cache.
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		// Build query args.
		$args = array(
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $post_type ) ) {
			$args['post_type'] = sanitize_text_field( $post_type );
		} else {
			// Query all public post types.
			$public_types      = get_post_types( array( 'public' => true ), 'names' );
			$args['post_type'] = array_values( array_diff( $public_types, array( 'attachment' ) ) );
		}

		$query = new WP_Query( $args );
		$stats = array();

		foreach ( $query->posts as $post ) {
			$blocks = parse_blocks( $post->post_content );
			$this->collect_block_stats( $blocks, $post, $stats, $sample_size, null, 0 );
		}

		// Sort by count descending.
		usort(
			$stats,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		// Convert posts_with_block sets to counts.
		foreach ( $stats as &$block_stat ) {
			$block_stat['posts_with_block'] = count( $block_stat['_post_ids'] );
			unset( $block_stat['_post_ids'] );
		}
		unset( $block_stat );

		$response = array(
			'total_posts_analyzed' => count( $query->posts ),
			'blocks'              => $stats,
		);

		// Cache for 24 hours.
		set_transient( $cache_key, $response, DAY_IN_SECONDS );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Recursively collect block usage statistics.
	 *
	 * Walks through blocks (including nested innerBlocks) and aggregates
	 * counts, post associations, and sample examples.
	 *
	 * @param array    $blocks      Array of parsed blocks.
	 * @param WP_Post  $post        The post being analyzed.
	 * @param array    &$stats      Reference to the stats accumulator (keyed by index).
	 * @param int      $sample_size Maximum number of examples to collect per block type.
	 * @param string|null $parent   Parent block name (null for top-level).
	 * @param int      $position    Position index within the parent.
	 */
	private function collect_block_stats( $blocks, $post, &$stats, $sample_size, $parent, $position ) {
		foreach ( $blocks as $index => $block ) {
			// Skip whitespace/empty blocks (blockName is null).
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$block_type = $block['blockName'];

			// Find or create stat entry for this block type.
			$stat_index = null;
			foreach ( $stats as $i => $existing ) {
				if ( $existing['type'] === $block_type ) {
					$stat_index = $i;
					break;
				}
			}

			if ( null === $stat_index ) {
				$stats[] = array(
					'type'            => $block_type,
					'count'           => 0,
					'_post_ids'       => array(),
					'posts_with_block' => 0,
					'examples'        => array(),
				);
				$stat_index = count( $stats ) - 1;
			}

			// Increment count.
			$stats[ $stat_index ]['count']++;

			// Track unique post IDs.
			$stats[ $stat_index ]['_post_ids'][ $post->ID ] = true;

			// Collect example if under sample_size limit.
			if ( count( $stats[ $stat_index ]['examples'] ) < $sample_size ) {
				$block_data = isset( $block['attrs'] ) && ! empty( $block['attrs'] )
					? (object) $block['attrs']
					: (object) array();

				$context = array(
					'parent_block' => $parent,
					'position'     => $parent !== null ? $position + $index : $index,
				);

				$stats[ $stat_index ]['examples'][] = array(
					'post_id'    => $post->ID,
					'post_title' => $post->post_title,
					'block_data' => $block_data,
					'context'    => $context,
				);
			}

			// Recurse into innerBlocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_block_stats(
					$block['innerBlocks'],
					$post,
					$stats,
					$sample_size,
					$block_type,
					$position + $index
				);
			}
		}
	}
}
