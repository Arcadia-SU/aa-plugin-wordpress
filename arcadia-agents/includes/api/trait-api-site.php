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
	 * Get site information.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_site_info( $request ) {
		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'name'           => get_bloginfo( 'name' ),
				'description'    => get_bloginfo( 'description' ),
				'url'            => get_site_url(),
				'home'           => get_home_url(),
				'admin_email'    => get_option( 'admin_email' ),
				'language'       => get_locale(),
				'timezone'       => wp_timezone_string(),
				'date_format'    => get_option( 'date_format' ),
				'time_format'    => get_option( 'time_format' ),
				'posts_per_page' => (int) get_option( 'posts_per_page' ),
				'authors'        => $this->get_authors(),
				'post_types'     => $this->get_post_types(),
				'theme'          => array(
					'name'   => $theme->get( 'Name' ),
					'author' => $theme->get( 'Author' ),
				),
				'plugin'         => array(
					'version' => ARCADIA_AGENTS_VERSION,
					'adapter' => $this->blocks->get_adapter_name(),
				),
				'settings'         => array(
					'force_draft'        => (bool) get_option( 'aa_force_draft', false ),
					'pending_revisions'  => (bool) get_option( 'aa_pending_revisions', false ),
					'enabled_scopes'     => $this->auth->get_enabled_scopes(),
				),
				'acf_available'    => Arcadia_Blocks::is_acf_available(),
				'acf_field_groups' => $this->get_acf_field_groups_for_post_types(),
				'permalink'        => get_option( 'permalink_structure' ),
			),
			200
		);
	}

	/**
	 * Get authors who can publish posts.
	 *
	 * Returns users with the 'edit_posts' capability (administrators, editors, authors).
	 *
	 * @return array List of authors with email, name, and role.
	 */
	private function get_authors() {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$authors = array();
		foreach ( $users as $user ) {
			$authors[] = array(
				'email' => $user->user_email,
				'name'  => $user->display_name,
				'role'  => ! empty( $user->roles ) ? $user->roles[0] : 'none',
			);
		}

		return $authors;
	}

	/**
	 * Get public post types that support the editor.
	 *
	 * Returns post types where content can be created/edited via the API.
	 * Excludes built-in non-content types (attachment, revision, nav_menu_item, etc.).
	 *
	 * @return array List of post types with name, label, and hierarchical flag.
	 */
	private function get_post_types() {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$excluded = array( 'attachment' );
		$result   = array();

		foreach ( $types as $type ) {
			if ( in_array( $type->name, $excluded, true ) ) {
				continue;
			}

			$counts = wp_count_posts( $type->name );

			$result[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'hierarchical' => $type->hierarchical,
				'count'        => array(
					'publish' => (int) ( $counts->publish ?? 0 ),
					'draft'   => (int) ( $counts->draft ?? 0 ),
					'total'   => (int) ( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) + ( $counts->pending ?? 0 ) + ( $counts->private ?? 0 ) ),
				),
			);
		}

		return $result;
	}

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
