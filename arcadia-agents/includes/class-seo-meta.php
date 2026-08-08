<?php
/**
 * SEO meta reader with multi-plugin detection.
 *
 * Reads SEO metadata from whichever SEO plugin is active,
 * with priority: Yoast > RankMath > AIOSEO > WP native fallback.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_SEO_Meta
 *
 * Detects active SEO plugin and reads meta fields accordingly.
 */
class Arcadia_SEO_Meta {

	/**
	 * Get SEO metadata for a post.
	 *
	 * Detection priority: Yoast > RankMath > AIOSEO > WP native.
	 *
	 * @param int $post_id The post ID.
	 * @return array SEO metadata.
	 */
	public static function get_seo_meta( $post_id ) {
		if ( self::is_yoast_active() ) {
			return self::get_yoast_meta( $post_id );
		}

		if ( self::is_rankmath_active() ) {
			return self::get_rankmath_meta( $post_id );
		}

		if ( self::is_aioseo_active() ) {
			return self::get_aioseo_meta( $post_id );
		}

		return self::get_native_meta( $post_id );
	}

	/**
	 * The two meta keys this plugin reads AND writes for the search snippet.
	 *
	 * Single source of truth for both directions. Until Phase 43.5 the write path
	 * hardcoded the Yoast keys (Arcadia_Post_Builder::finalize_post) while the read
	 * path went through get_seo_meta() above — so on a Rank Math site the API
	 * reported `rank_math_title` as the current value and then wrote
	 * `_yoast_wpseo_title`, a key Rank Math never reads. The write landed nowhere
	 * visible and the revision diff promised a change that approval never made.
	 *
	 * 'none' maps to the Yoast keys on purpose: that is what every site written by
	 * this plugin already carries, and it means the values survive installing Yoast
	 * later. It also keeps meta.title/meta.description out of post_title/post_excerpt,
	 * which are the H1 and the excerpt and belong to body.* alone (Phase 42.3).
	 *
	 * Caveat, deliberately not papered over: AIOSEO keeps its authoritative copy in
	 * its own table, so writing `_aioseo_title` post meta may not reach its UI. That
	 * mirrors what the reader already assumes; making the two agree is the point here,
	 * and a real AIOSEO integration is a separate piece of work.
	 *
	 * @return array{meta_title:string, meta_description:string}
	 */
	public static function storage_keys() {
		switch ( self::get_active_plugin() ) {
			case 'rankmath':
				return array(
					'meta_title'       => 'rank_math_title',
					'meta_description' => 'rank_math_description',
				);
			case 'aioseo':
				return array(
					'meta_title'       => '_aioseo_title',
					'meta_description' => '_aioseo_description',
				);
			case 'yoast':
			default:
				return array(
					'meta_title'       => '_yoast_wpseo_title',
					'meta_description' => '_yoast_wpseo_metadesc',
				);
		}
	}

	/**
	 * Read back exactly what this plugin would overwrite.
	 *
	 * get_seo_meta() answers "what does the site say today", which on a bare site
	 * means post_title/post_excerpt. That is the right answer for the API's read
	 * endpoints and the WRONG one for a before/after diff: approval will not touch
	 * post_title, it will write the key storage_keys() names. A diff must compare
	 * against the cell it is about to overwrite, not against a display fallback.
	 *
	 * @param int $post_id The post ID.
	 * @return array{meta_title:string, meta_description:string}
	 */
	public static function get_stored_seo_meta( $post_id ) {
		$keys = self::storage_keys();

		return array(
			'meta_title'       => (string) get_post_meta( $post_id, $keys['meta_title'], true ),
			'meta_description' => (string) get_post_meta( $post_id, $keys['meta_description'], true ),
		);
	}

	/**
	 * Detect which SEO plugin is active (if any).
	 *
	 * @return string Plugin identifier: 'yoast', 'rankmath', 'aioseo', or 'none'.
	 */
	public static function get_active_plugin() {
		if ( self::is_yoast_active() ) {
			return 'yoast';
		}
		if ( self::is_rankmath_active() ) {
			return 'rankmath';
		}
		if ( self::is_aioseo_active() ) {
			return 'aioseo';
		}
		return 'none';
	}

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Check if Rank Math is active.
	 *
	 * @return bool
	 */
	public static function is_rankmath_active() {
		return class_exists( 'RankMath' );
	}

	/**
	 * Check if All in One SEO is active.
	 *
	 * @return bool
	 */
	public static function is_aioseo_active() {
		return defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Get SEO meta from Yoast SEO.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private static function get_yoast_meta( $post_id ) {
		return array(
			'plugin'           => 'yoast',
			'meta_title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'canonical_url'    => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'og_title'         => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
			'og_description'   => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
			'robots'           => self::get_yoast_robots( $post_id ),
		);
	}

	/**
	 * Get robots directive from Yoast meta.
	 *
	 * @param int $post_id The post ID.
	 * @return string Robots directive string.
	 */
	private static function get_yoast_robots( $post_id ) {
		$noindex  = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$nofollow = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

		$index  = '1' === $noindex ? 'noindex' : 'index';
		$follow = '1' === $nofollow ? 'nofollow' : 'follow';

		return $index . ',' . $follow;
	}

	/**
	 * Get SEO meta from Rank Math.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private static function get_rankmath_meta( $post_id ) {
		$robots_meta = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots      = '';
		if ( is_array( $robots_meta ) ) {
			$robots = implode( ',', $robots_meta );
		} elseif ( is_string( $robots_meta ) ) {
			$robots = $robots_meta;
		}

		return array(
			'plugin'           => 'rankmath',
			'meta_title'       => (string) get_post_meta( $post_id, 'rank_math_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'canonical_url'    => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
			'og_title'         => (string) get_post_meta( $post_id, 'rank_math_facebook_title', true ),
			'og_description'   => (string) get_post_meta( $post_id, 'rank_math_facebook_description', true ),
			'robots'           => $robots,
		);
	}

	/**
	 * Get SEO meta from All in One SEO.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private static function get_aioseo_meta( $post_id ) {
		return array(
			'plugin'           => 'aioseo',
			'meta_title'       => (string) get_post_meta( $post_id, '_aioseo_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_aioseo_description', true ),
			'canonical_url'    => (string) get_post_meta( $post_id, '_aioseo_canonical_url', true ),
			'og_title'         => (string) get_post_meta( $post_id, '_aioseo_og_title', true ),
			'og_description'   => (string) get_post_meta( $post_id, '_aioseo_og_description', true ),
			'robots'           => self::get_aioseo_robots( $post_id ),
		);
	}

	/**
	 * Get robots directive from AIOSEO meta.
	 *
	 * @param int $post_id The post ID.
	 * @return string Robots directive string.
	 */
	private static function get_aioseo_robots( $post_id ) {
		$noindex  = (string) get_post_meta( $post_id, '_aioseo_noindex', true );
		$nofollow = (string) get_post_meta( $post_id, '_aioseo_nofollow', true );

		$index  = '1' === $noindex ? 'noindex' : 'index';
		$follow = '1' === $nofollow ? 'nofollow' : 'follow';

		return $index . ',' . $follow;
	}

	/**
	 * Get native WP fallback SEO meta (no SEO plugin).
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private static function get_native_meta( $post_id ) {
		$post = get_post( $post_id );

		return array(
			'plugin'           => 'none',
			'meta_title'       => $post ? $post->post_title : '',
			'meta_description' => $post ? $post->post_excerpt : '',
			'canonical_url'    => get_permalink( $post_id ),
			'og_title'         => '',
			'og_description'   => '',
			'robots'           => 'index,follow',
		);
	}
}
