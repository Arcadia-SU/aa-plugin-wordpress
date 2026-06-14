<?php
/**
 * Remote URL guard (SSRF protection).
 *
 * Single source of truth for validating remote URLs before the plugin
 * fetches them (image sideload, etc.). Centralising the check makes it
 * impossible for one sideload path to forget the guard while another has it.
 *
 * @package ArcadiaAgents
 * @since   0.1.30
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Url_Guard
 *
 * Stateless validator. All public methods are static.
 */
final class Arcadia_Url_Guard {

	/**
	 * Validate a remote URL before fetching it.
	 *
	 * Rejects non-HTTP(S) schemes and private/reserved hosts (SSRF) by
	 * delegating host validation to WordPress core (wp_http_validate_url),
	 * which honours the same allow/deny rules WordPress uses internally.
	 *
	 * @param string $url The URL to validate.
	 * @return true|WP_Error True when safe to fetch, WP_Error (status 400) otherwise.
	 */
	public static function validate_remote_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'invalid_url_scheme',
				__( 'Only HTTP and HTTPS URLs are allowed.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		// Blocks private/reserved IPs and disallowed hosts — SSRF protection.
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'URL is not allowed (private/reserved IP).', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
