<?php
/**
 * Counter for agent-created content (arcadia_source taxonomy).
 *
 * Live count, no cache — a single COUNT over the indexed term_relationships
 * table (~1 ms at client-site scale; core precedent: wp_count_posts() runs
 * live on every edit.php load). Never stale, nothing to invalidate, nothing
 * to clean up at uninstall.
 *
 * @package ArcadiaAgents
 * @since   0.6.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Content_Counter
 *
 * Counts posts carrying the 'arcadia' term of the arcadia_source taxonomy.
 */
class Arcadia_Content_Counter {

	/**
	 * Count agent-created contents of a post type.
	 *
	 * @param string $post_type Post type to count ('post', 'page', …).
	 * @param string $status    Post status, or 'any'. Unknown values fall back to 'any'.
	 * @return int
	 */
	public static function count( $post_type, $status = 'any' ) {
		if ( 'any' !== $status && ! array_key_exists( $status, get_post_stati() ) ) {
			$status = 'any';
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => $status,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Hardcoded term, never user input (same pattern as trait-api-posts).
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- indexed COUNT, one row a page.
					array(
						'taxonomy' => 'arcadia_source',
						'field'    => 'slug',
						'terms'    => 'arcadia',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}
}
