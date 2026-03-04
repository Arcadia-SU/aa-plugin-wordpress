<?php
/**
 * Redirects management via hidden Custom Post Type.
 *
 * Registers the arcadia_redirect CPT, handles template_redirect
 * for serving 301/302 redirects, and manages a transient cache
 * for fast lookups.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Redirects
 *
 * Manages redirect rules stored as a hidden Custom Post Type.
 */
class Arcadia_Redirects {

	/**
	 * Single instance.
	 *
	 * @var Arcadia_Redirects|null
	 */
	private static $instance = null;

	/**
	 * Transient cache key for the redirect map.
	 *
	 * @var string
	 */
	private $cache_key = 'arcadia_redirects_map';

	/**
	 * Get single instance.
	 *
	 * @return Arcadia_Redirects
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register the arcadia_redirect CPT.
	 *
	 * Post title stores the source path, meta fields store
	 * the target URL and redirect type (301/302).
	 */
	public function register_post_type() {
		register_post_type(
			'arcadia_redirect',
			array(
				'labels'       => array( 'name' => 'Arcadia Redirects' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Handle template_redirect hook: serve redirects.
	 *
	 * Checks the current request path against the cached redirect map.
	 * If a match is found, sends the appropriate redirect header and exits.
	 */
	public function handle_redirect() {
		if ( is_admin() ) {
			return;
		}

		$map = $this->get_redirect_map();
		if ( empty( $map ) ) {
			return;
		}

		$request_path = $this->normalize_path( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
		if ( empty( $request_path ) ) {
			return;
		}

		if ( isset( $map[ $request_path ] ) ) {
			$redirect = $map[ $request_path ];
			wp_redirect( $redirect['target'], $redirect['type'] );
			exit;
		}
	}

	/**
	 * Get the redirect map (cached via transient).
	 *
	 * Builds a map of normalized source path => { target, type, id }
	 * from the arcadia_redirect CPT and caches it for 24 hours.
	 *
	 * @return array Redirect map.
	 */
	public function get_redirect_map() {
		$map = get_transient( $this->cache_key );
		if ( false !== $map ) {
			return $map;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'arcadia_redirect',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$map = array();
		foreach ( $query->posts as $post ) {
			$source = $this->normalize_path( $post->post_title );
			if ( ! empty( $source ) ) {
				$map[ $source ] = array(
					'target' => get_post_meta( $post->ID, '_redirect_target', true ),
					'type'   => (int) get_post_meta( $post->ID, '_redirect_type', true ),
					'id'     => (int) $post->ID,
				);
			}
		}

		set_transient( $this->cache_key, $map, DAY_IN_SECONDS );
		return $map;
	}

	/**
	 * Invalidate the redirect map cache.
	 *
	 * Called after every CRUD operation on redirects.
	 */
	public function invalidate_cache() {
		delete_transient( $this->cache_key );
	}

	/**
	 * Normalize a URL path for consistent matching.
	 *
	 * Strips query string, fragment, and trailing slash.
	 * Returns empty string for root path (no homepage redirect).
	 *
	 * @param string $path The URL or path.
	 * @return string Normalized path or empty string.
	 */
	private function normalize_path( $path ) {
		// Strip query string and fragment.
		$path = strtok( $path, '?' );
		$path = strtok( $path, '#' );

		// Ensure leading slash, remove trailing slash.
		$path = '/' . trim( $path, '/' );

		// Root path → empty (no redirect on homepage).
		if ( '/' === $path ) {
			return '';
		}

		return $path;
	}
}
