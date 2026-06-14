<?php
/**
 * JWT Authentication handler.
 *
 * @package ArcadiaAgents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;

/**
 * Class Arcadia_Auth
 *
 * Handles JWT validation (RS256) and scope verification.
 */
class Arcadia_Auth {

	/**
	 * Issuer that must appear in the `iss` claim of every token (spec: auth.md).
	 *
	 * @var string
	 */
	const EXPECTED_ISSUER = 'arcadia-agents';

	/**
	 * Single instance of the class.
	 *
	 * @var Arcadia_Auth|null
	 */
	private static $instance = null;

	/**
	 * ArcadiaAgents API base URL.
	 *
	 * @var string
	 */
	private $api_base_url = 'https://api.arcadia-agents.com';

	/**
	 * Get single instance of the class.
	 *
	 * @return Arcadia_Auth
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
	private function __construct() {
		// Nothing to initialize.
	}

	/**
	 * Perform handshake with ArcadiaAgents to exchange connection key for public key.
	 *
	 * @param string $connection_key The connection key from ArcadiaAgents.
	 * @return array|WP_Error Result array with success status or WP_Error.
	 */
	public function handshake( $connection_key ) {
		if ( empty( $connection_key ) ) {
			return new WP_Error(
				'missing_connection_key',
				__( 'Connection key is required.', 'arcadia-agents' ),
				array( 'status' => 400 )
			);
		}

		$site_url  = get_site_url();
		$site_name = get_bloginfo( 'name' );

		$response = wp_remote_post(
			$this->api_base_url . '/v1/wordpress/handshake',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'connection_key' => $connection_key,
						'site_url'       => $site_url,
						'site_name'      => $site_name,
						'plugin_version' => ARCADIA_AGENTS_VERSION,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'handshake_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to ArcadiaAgents: %s', 'arcadia-agents' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$error_message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown error', 'arcadia-agents' );
			return new WP_Error(
				'handshake_rejected',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		if ( empty( $data['public_key'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from ArcadiaAgents: missing public key.', 'arcadia-agents' ),
				array( 'status' => 500 )
			);
		}

		// Store the public key.
		update_option( 'arcadia_agents_public_key', $data['public_key'], false );
		update_option( 'arcadia_agents_connected', true, false );
		update_option( 'arcadia_agents_connected_at', current_time( 'mysql' ), false );

		// Remove the connection key — it's single-use and no longer needed.
		delete_option( 'arcadia_agents_connection_key' );

		return array(
			'success'   => true,
			'connected' => true,
		);
	}

	/**
	 * Validate a JWT token using firebase/php-jwt.
	 *
	 * @param string $token The JWT token to validate.
	 * @return array|WP_Error Decoded payload or WP_Error.
	 */
	public function validate_jwt( $token ) {
		$public_key = get_option( 'arcadia_agents_public_key', '' );

		if ( empty( $public_key ) ) {
			return $this->error_response(
				'not_configured',
				__( 'Plugin not configured. Please complete the handshake first.', 'arcadia-agents' ),
				401
			);
		}

		try {
			// Allow ±30s clock drift between servers (finding #3).
			JWT::$leeway = 30;

			$decoded = JWT::decode( $token, new Key( $public_key, 'RS256' ) );

			// Convert stdClass to array for consistent return type.
			$payload = json_decode( wp_json_encode( $decoded ), true );

			// A valid signature proves the token was minted by Arcadia's private
			// key, but NOT that it was minted for *this* site. Verify the identity
			// claims (issuer + site binding) before trusting the payload.
			$claims_check = $this->validate_claims( $payload );
			if ( is_wp_error( $claims_check ) ) {
				return $claims_check;
			}

			// Update last activity.
			update_option( 'arcadia_agents_last_activity', current_time( 'mysql' ), false );

			return $payload;

		} catch ( ExpiredException $e ) {
			return $this->error_response(
				'token_expired',
				__( 'Token has expired.', 'arcadia-agents' ),
				401
			);
		} catch ( BeforeValidException $e ) {
			return $this->error_response(
				'token_not_valid_yet',
				__( 'Token is not valid yet.', 'arcadia-agents' ),
				401
			);
		} catch ( SignatureInvalidException $e ) {
			return $this->error_response(
				'invalid_signature',
				__( 'Token signature verification failed.', 'arcadia-agents' ),
				401
			);
		} catch ( \UnexpectedValueException $e ) {
			return $this->error_response(
				'invalid_token',
				__( 'Invalid token format or algorithm.', 'arcadia-agents' ),
				401
			);
		} catch ( \Exception $e ) {
			return $this->error_response(
				'jwt_error',
				sprintf(
					/* translators: %s: error message */
					__( 'JWT validation error: %s', 'arcadia-agents' ),
					$e->getMessage()
				),
				401
			);
		}
	}

	/**
	 * Validate the identity claims of an already signature-verified payload.
	 *
	 * Signature + expiry are checked by validate_jwt(); this enforces *who* the
	 * caller is, which a valid signature alone does not guarantee:
	 *   1. `iss` must be the Arcadia issuer (spec-conformance / defense in depth).
	 *   2. `sub` (the site_id the token was minted for) must match the site this
	 *      install is pinned to. The handshake response does not carry a site_id
	 *      (see auth.md), so we pin trust-on-first-use: the first valid token
	 *      records its `sub`, and every later token must match it. This blocks a
	 *      token minted for another site (same Arcadia keypair) from being
	 *      replayed against this install. disconnect() clears the pin so a fresh
	 *      handshake can re-pin a re-provisioned site.
	 *
	 * @param array $payload Decoded JWT payload (associative array).
	 * @return true|WP_Error True when the identity is acceptable, WP_Error otherwise.
	 */
	public function validate_claims( $payload ) {
		// 1. Issuer.
		$iss = isset( $payload['iss'] ) ? (string) $payload['iss'] : '';
		if ( self::EXPECTED_ISSUER !== $iss ) {
			return $this->error_response(
				'invalid_issuer',
				__( 'Token issuer is not recognized.', 'arcadia-agents' ),
				401
			);
		}

		// 2. Subject (site identity), pinned trust-on-first-use.
		$sub = isset( $payload['sub'] ) ? (string) $payload['sub'] : '';
		if ( '' === $sub ) {
			return $this->error_response(
				'missing_subject',
				__( 'Token does not identify a site (missing sub claim).', 'arcadia-agents' ),
				401
			);
		}

		$pinned_site_id = (string) get_option( 'arcadia_agents_site_id', '' );
		if ( '' === $pinned_site_id ) {
			// First valid token after connection — pin this site identity.
			update_option( 'arcadia_agents_site_id', $sub, false );
		} elseif ( ! hash_equals( $pinned_site_id, $sub ) ) {
			return $this->error_response(
				'site_mismatch',
				__( 'Token was issued for a different site.', 'arcadia-agents' ),
				401
			);
		}

		return true;
	}

	/**
	 * All scopes supported by the plugin.
	 *
	 * @var array
	 */
	private static $all_scopes = array(
		'articles:read',
		'articles:write',
		'articles:delete',
		'media:read',
		'media:write',
		'media:delete',
		'taxonomies:read',
		'taxonomies:write',
		'taxonomies:delete',
		'site:read',
		'redirects:read',
		'redirects:write',
		'settings:write',
	);

	/**
	 * Check if a required scope is enabled in WP admin settings.
	 *
	 * Permissions are controlled exclusively by the admin checkboxes.
	 * The JWT proves identity only — it no longer carries scopes.
	 *
	 * @param string $required_scope The scope to check.
	 * @return true|WP_Error True if scope is allowed, WP_Error otherwise.
	 */
	public function check_scope( $required_scope ) {
		$enabled_scopes = $this->get_enabled_scopes();

		if ( ! in_array( $required_scope, $enabled_scopes, true ) ) {
			return new WP_Error(
				'scope_denied',
				sprintf(
					/* translators: %s: scope name */
					__( "This action requires the '%s' scope which is not enabled in WordPress settings.", 'arcadia-agents' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
				)
			);
		}

		return true;
	}

	/**
	 * Get the list of scopes currently enabled in WP admin settings.
	 *
	 * @return array Enabled scope strings.
	 */
	public function get_enabled_scopes() {
		return get_option( 'arcadia_agents_scopes', self::$all_scopes );
	}

	/**
	 * Extract bearer token from request headers.
	 *
	 * Checks two sources in order of priority:
	 * 1. Authorization: Bearer <jwt> — standard method
	 * 2. X-AA-Token: Bearer <jwt> — fallback for environments where
	 *    the Authorization header is intercepted (Apache Basic Auth,
	 *    shared hosting, CDN, WAF).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return string|WP_Error The token or WP_Error.
	 */
	public function get_bearer_token( $request ) {
		// Priority 1: Standard Authorization header with Bearer token.
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! empty( $auth_header ) && preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return $matches[1];
		}

		// Priority 2: Fallback X-AA-Token header.
		$aa_token_header = $request->get_header( 'X-AA-Token' );
		if ( ! empty( $aa_token_header ) && preg_match( '/^Bearer\s+(.+)$/i', $aa_token_header, $matches ) ) {
			return $matches[1];
		}

		// Neither header contained a valid Bearer token.
		return $this->error_response(
			'missing_authorization',
			__( 'No valid Bearer token found. Send it via Authorization or X-AA-Token header.', 'arcadia-agents' ),
			401
		);
	}

	/**
	 * Authenticate a REST request.
	 *
	 * @param WP_REST_Request $request        The REST request.
	 * @param string          $required_scope The required scope for this endpoint.
	 * @return array|WP_Error Decoded JWT payload or WP_Error.
	 */
	public function authenticate_request( $request, $required_scope = null ) {
		$token = $this->get_bearer_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = $this->validate_jwt( $token );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Defensive: ensure payload is a valid array (finding #6).
		if ( ! is_array( $payload ) ) {
			return $this->error_response(
				'invalid_payload',
				__( 'JWT payload is not a valid array.', 'arcadia-agents' ),
				401
			);
		}

		// Check scope if required (WP admin settings only — JWT no longer carries scopes).
		if ( null !== $required_scope ) {
			$scope_check = $this->check_scope( $required_scope );
			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}
		}

		return $payload;
	}

	/**
	 * Create a standardized error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	public function error_response( $code, $message, $status = 400 ) {
		return new WP_Error(
			$code,
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Disconnect the plugin (clear stored data).
	 *
	 * @return bool True on success.
	 */
	public function disconnect() {
		delete_option( 'arcadia_agents_public_key' );
		delete_option( 'arcadia_agents_connected' );
		delete_option( 'arcadia_agents_connected_at' );
		delete_option( 'arcadia_agents_last_activity' );
		delete_option( 'arcadia_agents_connection_key' );
		delete_option( 'arcadia_agents_site_id' );
		return true;
	}
}
