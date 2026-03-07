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
	 * Returns enriched data including resolved author name, category/tag names,
	 * word count, block detection, and SEO metadata.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted post data.
	 */
	private function format_post( $post ) {
		$featured_image_id  = get_post_thumbnail_id( $post->ID );
		$featured_image_url = $featured_image_id ? wp_get_attachment_url( $featured_image_id ) : null;
		$featured_image_alt = $featured_image_id ? (string) get_post_meta( $featured_image_id, '_wp_attachment_image_alt', true ) : '';

		// Resolve author display name.
		$author_data = get_userdata( (int) $post->post_author );
		$author_name = $author_data ? $author_data->display_name : '';

		// Resolve category and tag names.
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		// Word count from stripped content.
		$stripped    = wp_strip_all_tags( $post->post_content );
		$word_count  = str_word_count( $stripped );

		// Block detection.
		$has_blocks = has_blocks( $post->post_content );

		// SEO metadata via multi-plugin detection.
		$seo = Arcadia_SEO_Meta::get_seo_meta( $post->ID );

		// Preview URL (reuses existing valid token).
		$preview      = Arcadia_Preview::get_instance();
		$token        = $preview->get_or_create_token( $post->ID );
		$preview_url  = add_query_arg(
			array(
				'p'          => $post->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'post_type'          => $post->post_type,
			'status'             => $post->post_status,
			'url'                => get_permalink( $post->ID ),
			'excerpt'            => $post->post_excerpt,
			'content'            => $post->post_content,
			'author'             => $author_name,
			'published_at'       => $post->post_date,
			'last_modified'      => $post->post_modified,
			'word_count'         => $word_count,
			'has_blocks'         => $has_blocks,
			'featured_image_id'  => $featured_image_id ? (int) $featured_image_id : null,
			'featured_image_url' => $featured_image_url,
			'featured_image_alt' => $featured_image_alt,
			'categories'         => is_array( $categories ) ? $categories : array(),
			'tags'               => is_array( $tags ) ? $tags : array(),
			'seo'                => $seo,
			'preview_url'        => $preview_url,
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
