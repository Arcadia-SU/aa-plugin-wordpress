<?php
/**
 * Taxonomies API handlers.
 *
 * Handles categories and tags operations via REST API.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Taxonomies_Handler
 *
 * Provides methods for handling taxonomies endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Taxonomies_Handler {

	/**
	 * Get categories.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( $request ) {
		$args = array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$parent = $request->get_param( 'parent' );
		if ( null !== $parent ) {
			$args['parent'] = (int) $parent;
		}

		$terms      = get_terms( $args );
		$categories = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = $this->format_term( $term );
			}
		}

		return new WP_REST_Response(
			array(
				'categories' => $categories,
				'total'      => count( $categories ),
			),
			200
		);
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Category name is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$args = array(
			'slug' => isset( $body['slug'] ) ? sanitize_title( $body['slug'] ) : '',
		);

		if ( ! empty( $body['parent'] ) ) {
			$args['parent'] = (int) $body['parent'];
		}

		if ( ! empty( $body['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $body['description'] );
		}

		$result = wp_insert_term(
			sanitize_text_field( $body['name'] ),
			'category',
			$args
		);

		if ( is_wp_error( $result ) ) {
			// If term already exists, return it.
			if ( 'term_exists' === $result->get_error_code() ) {
				$term = get_term( $result->get_error_data(), 'category' );
				return new WP_REST_Response(
					array(
						'success'     => true,
						'category_id' => $term->term_id,
						'category'    => $this->format_term( $term ),
						'existing'    => true,
					),
					200
				);
			}
			return $result;
		}

		$term = get_term( $result['term_id'], 'category' );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'category_id' => $result['term_id'],
				'category'    => $this->format_term( $term ),
			),
			201
		);
	}

	/**
	 * Get tags.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tags( $request ) {
		$args = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$terms = get_terms( $args );
		$tags  = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$tags[] = $this->format_term( $term );
			}
		}

		return new WP_REST_Response(
			array(
				'tags'  => $tags,
				'total' => count( $tags ),
			),
			200
		);
	}

	/**
	 * Get or create terms by name.
	 *
	 * @param array  $names    Array of term names.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Array of term IDs.
	 */
	private function get_or_create_terms( $names, $taxonomy ) {
		$term_ids = array();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			$term = get_term_by( 'name', $name, $taxonomy );

			if ( $term ) {
				$term_ids[] = $term->term_id;
			} else {
				$result = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $result ) ) {
					$term_ids[] = $result['term_id'];
				}
			}
		}

		return $term_ids;
	}
}
