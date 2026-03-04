<?php
/**
 * Site structure API handlers.
 *
 * Handles menus and users endpoints.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Arcadia_API_Site_Handler
 *
 * Provides methods for handling site structure endpoints.
 * Used by Arcadia_API class.
 */
trait Arcadia_API_Site_Handler {

	/**
	 * Get navigation menus with hierarchical items.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_menus( $request ) {
		$menus  = wp_get_nav_menus();
		$result = array();

		if ( ! is_array( $menus ) ) {
			return new WP_REST_Response(
				array(
					'menus' => array(),
					'total' => 0,
				),
				200
			);
		}

		foreach ( $menus as $menu ) {
			$items     = wp_get_nav_menu_items( $menu );
			$tree      = $this->build_menu_tree( is_array( $items ) ? $items : array() );
			$result[]  = array(
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'items' => $tree,
			);
		}

		return new WP_REST_Response(
			array(
				'menus' => $result,
				'total' => count( $result ),
			),
			200
		);
	}

	/**
	 * Build hierarchical menu tree from flat items.
	 *
	 * @param array $items Flat menu items from wp_get_nav_menu_items().
	 * @return array Hierarchical menu tree.
	 */
	private function build_menu_tree( $items ) {
		$tree       = array();
		$children   = array();

		// Index items by ID and group children by parent.
		foreach ( $items as $item ) {
			$parent_id = (int) $item->menu_item_parent;

			$formatted = array(
				'id'       => (int) $item->ID,
				'title'    => $item->title,
				'url'      => $item->url,
				'type'     => $item->type,
				'object'   => $item->object,
				'parent'   => $parent_id,
				'children' => array(),
			);

			if ( 0 === $parent_id ) {
				$tree[ $item->ID ] = $formatted;
			} else {
				$children[ $parent_id ][] = $formatted;
			}
		}

		// Attach children to parents.
		foreach ( $children as $parent_id => $child_items ) {
			if ( isset( $tree[ $parent_id ] ) ) {
				$tree[ $parent_id ]['children'] = $child_items;
			}
		}

		return array_values( $tree );
	}

	/**
	 * Get users list (editors, authors, administrators).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_users_list( $request ) {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$result = array();
		foreach ( $users as $user ) {
			$result[] = array(
				'id'          => (int) $user->ID,
				'email'       => $user->user_email,
				'name'        => $user->display_name,
				'role'        => ! empty( $user->roles ) ? $user->roles[0] : 'none',
				'posts_count' => (int) count_user_posts( $user->ID ),
			);
		}

		return new WP_REST_Response(
			array(
				'users' => $result,
				'total' => count( $result ),
			),
			200
		);
	}
}
