<?php
/**
 * Preview URL handler.
 *
 * Generates time-limited preview tokens for draft/private posts,
 * allowing the SEO agent to take screenshots without authentication.
 *
 * @package ArcadiaAgents
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Preview
 *
 * Manages preview tokens stored as post meta.
 */
class Arcadia_Preview {

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Preview|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Preview
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
	 * Get an existing valid token or generate a new one.
	 *
	 * Reuses a valid (non-expired) token if one exists, avoiding
	 * unnecessary DB writes when listing multiple articles.
	 *
	 * @param int $post_id The post ID.
	 * @return string The token (existing or newly generated).
	 */
	public function get_or_create_token( $post_id ) {
		$stored_token = get_post_meta( $post_id, '_aa_preview_token', true );
		$expires      = (int) get_post_meta( $post_id, '_aa_preview_expires', true );

		if ( ! empty( $stored_token ) && ! empty( $expires ) && time() < $expires ) {
			return $stored_token;
		}

		return $this->generate_token( $post_id );
	}

	/**
	 * Generate a preview token for a post.
	 *
	 * Creates a random token, stores it in post meta with an expiry timestamp.
	 * If a valid token already exists, it is replaced.
	 *
	 * @param int $post_id The post ID.
	 * @return string The generated token.
	 */
	public function generate_token( $post_id ) {
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = time() + DAY_IN_SECONDS;

		update_post_meta( $post_id, '_aa_preview_token', $token );
		update_post_meta( $post_id, '_aa_preview_expires', $expires );

		return $token;
	}

	/**
	 * Validate a preview token for a post.
	 *
	 * Uses timing-safe comparison to prevent timing attacks.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $token   The token to validate.
	 * @return bool True if token is valid and not expired.
	 */
	public function validate_token( $post_id, $token ) {
		$stored_token = get_post_meta( $post_id, '_aa_preview_token', true );
		$expires      = (int) get_post_meta( $post_id, '_aa_preview_expires', true );

		if ( empty( $stored_token ) || empty( $expires ) ) {
			return false;
		}

		if ( time() > $expires ) {
			// Clean up expired token.
			delete_post_meta( $post_id, '_aa_preview_token' );
			delete_post_meta( $post_id, '_aa_preview_expires' );
			return false;
		}

		return hash_equals( $stored_token, $token );
	}

	/**
	 * Handle preview requests on template_redirect.
	 *
	 * Checks for `?aa_preview=TOKEN&p=ID` in the URL, validates the token,
	 * and renders the post using the theme's single template.
	 */
	public function handle_preview() {
		if ( empty( $_GET['aa_preview'] ) || empty( $_GET['p'] ) ) {
			return;
		}

		$token   = sanitize_text_field( $_GET['aa_preview'] );
		$post_id = (int) $_GET['p'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( ! $this->validate_token( $post_id, $token ) ) {
			return;
		}

		// Force the post to appear published for rendering.
		$post->post_status = 'publish';
		setup_postdata( $post );

		// Use the theme's single post template.
		$template = get_single_template();
		if ( ! $template ) {
			$template = get_index_template();
		}

		if ( $template ) {
			include $template;
			exit;
		}
	}

	/**
	 * Clean up expired preview tokens.
	 *
	 * Queries for posts with expired `_aa_preview_expires` and removes
	 * both the token and expiry meta.
	 */
	public function cleanup_expired_tokens() {
		global $wpdb;

		$now = time();

		// Find all posts with expired preview tokens.
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_aa_preview_expires'
				 AND CAST(meta_value AS UNSIGNED) < %d",
				$now
			)
		);

		if ( ! empty( $expired ) ) {
			foreach ( $expired as $post_id ) {
				delete_post_meta( (int) $post_id, '_aa_preview_token' );
				delete_post_meta( (int) $post_id, '_aa_preview_expires' );
			}
		}
	}

	/**
	 * Schedule the daily cleanup cron event.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'arcadia_preview_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'arcadia_preview_cleanup' );
		}
	}

	/**
	 * Unschedule the cleanup cron event.
	 */
	public static function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( 'arcadia_preview_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'arcadia_preview_cleanup' );
		}
	}
}
