<?php
/**
 * API response formatters.
 *
 * Provides consistent formatting for API responses.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Formatters
 *
 * Provides methods for formatting API responses.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Formatters {

	/**
	 * Format a post for API response.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted post data.
	 */
	private function format_post( $post ) {
		$featured_image_id  = get_post_thumbnail_id( $post->ID );
		$featured_image_url = $featured_image_id ? wp_get_attachment_url( $featured_image_id ) : null;

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'url'                => get_permalink( $post->ID ),
			'excerpt'            => $post->post_excerpt,
			'content'            => $post->post_content,
			'author'             => (int) $post->post_author,
			'date'               => $post->post_date,
			'date_gmt'           => $post->post_date_gmt,
			'modified'           => $post->post_modified,
			'modified_gmt'       => $post->post_modified_gmt,
			'featured_image_id'  => $featured_image_id ? (int) $featured_image_id : null,
			'featured_image_url' => $featured_image_url,
			'categories'         => wp_get_post_categories( $post->ID ),
			'tags'               => wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) ),
		);
	}

	/**
	 * Format a page for API response.
	 *
	 * @param WP_Post $page The page object.
	 * @return array Formatted page data.
	 */
	private function format_page( $page ) {
		return array(
			'id'         => $page->ID,
			'title'      => $page->post_title,
			'slug'       => $page->post_name,
			'status'     => $page->post_status,
			'url'        => get_permalink( $page->ID ),
			'parent'     => $page->post_parent,
			'menu_order' => $page->menu_order,
			'template'   => get_page_template_slug( $page->ID ),
			'date'       => $page->post_date,
			'modified'   => $page->post_modified,
		);
	}

	/**
	 * Format a term for API response.
	 *
	 * @param WP_Term $term The term object.
	 * @return array Formatted term data.
	 */
	private function format_term( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);
	}
}
