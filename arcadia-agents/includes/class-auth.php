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

		// Pin the site identity from this authenticated response (when present).
		$this->pin_identity_from_handshake( $data );

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
	 * Pin the site identity from a trusted handshake response.
	 *
	 * The handshake is the authenticated channel — the single-use connection key
	 * proved who this site is — so the `site_id`/`issuer` it returns are the
	 * authoritative binding. Pinning them here closes the trust-on-first-use race
	 * in validate_claims() (the first token can no longer set the identity).
	 *
	 * Backends that predate these fields (see auth.md) simply omit them; in that
	 * case nothing is pinned here and validate_claims() falls back to TOFU.
	 *
	 * @param array $data Decoded handshake response body.
	 * @return void
	 */
	public function pin_identity_from_handshake( $data ) {
		if ( ! empty( $data['site_id'] ) ) {
			update_option( 'arcadia_agents_site_id', (string) $data['site_id'], false );
		}
		if ( ! empty( $data['issuer'] ) ) {
			update_option( 'arcadia_agents_issuer', (string) $data['issuer'], false );
		}
	}

	/**
	 * Validate the identity claims of an already signature-verified payload.
	 *
	 * Signature + expiry are checked by validate_jwt(); this enforces *who* the
	 * caller is. Each site has its own RSA keypair, so the signature already binds
	 * the token to this site — these claim checks are defense-in-depth.
	 *
	 * Identity sources, in order of trust:
	 *   1. The handshake response pins (site_id, issuer) from its authenticated
	 *      channel — see pin_identity_from_handshake(). No race.
	 *   2. If the handshake predates those fields, the first token pins the
	 *      identity trust-on-first-use (TOFU) as a fallback.
	 *
	 * Claim rules:
	 *   - `sub` (site_id) is always present and is required; it must match the pin.
	 *   - `iss` is only emitted by newer backends. A *missing* iss must NOT lock
	 *     out a legitimate caller (older backends never send it), so we enforce it
	 *     only once an issuer is pinned — at which point this backend is known to
	 *     emit iss, and a token without one is rejected.
	 *
	 * disconnect() clears both pins so a fresh handshake can re-pin a re-provisioned
	 * site.
	 *
	 * @param array $payload Decoded JWT payload (associative array).
	 * @return true|WP_Error True when the identity is acceptable, WP_Error otherwise.
	 */
	public function validate_claims( $payload ) {
		$iss = isset( $payload['iss'] ) ? (string) $payload['iss'] : '';
		$sub = isset( $payload['sub'] ) ? (string) $payload['sub'] : '';

		// sub identifies the site and is present in every token — required.
		if ( '' === $sub ) {
			return $this->error_response(
				'missing_subject',
				__( 'Token does not identify a site (missing sub claim).', 'arcadia-agents' ),
				401
			);
		}

		$pinned_sub = (string) get_option( 'arcadia_agents_site_id', '' );
		$pinned_iss = (string) get_option( 'arcadia_agents_issuer', '' );

		// Site binding (sub). Pinned by the handshake when available; otherwise
		// trust-on-first-use on the first token.
		if ( '' === $pinned_sub ) {
			update_option( 'arcadia_agents_site_id', $sub, false );
		} elseif ( ! hash_equals( $pinned_sub, $sub ) ) {
			return $this->error_response(
				'site_mismatch',
				__( 'Token was issued for a different site.', 'arcadia-agents' ),
				401
			);
		}

		// Issuer binding (iss). Once an issuer is pinned (from the handshake or a
		// prior token), this backend is known to emit iss, so require a match —
		// this also blocks an attacker stripping iss to skip the check. When no
		// issuer is pinned yet, a token that carries one pins it; a legacy token
		// without iss is accepted (the backend hasn't started sending it).
		if ( '' !== $pinned_iss ) {
			if ( '' === $iss || ! hash_equals( $pinned_iss, $iss ) ) {
				return $this->error_response(
					'invalid_issuer',
					__( 'Token issuer does not match the connected issuer.', 'arcadia-agents' ),
					401
				);
			}
		} elseif ( '' !== $iss ) {
			update_option( 'arcadia_agents_issuer', $iss, false );
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
		delete_option( 'arcadia_agents_issuer' );
		return true;
	}
}
