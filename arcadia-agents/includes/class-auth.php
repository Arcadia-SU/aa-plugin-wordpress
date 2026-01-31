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
	private $api_base_url = 'https://api.arcadiaagents.com';

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

		$site_url = get_site_url();
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
		update_option( 'arcadia_agents_public_key', $data['public_key'] );
		update_option( 'arcadia_agents_connected', true );
		update_option( 'arcadia_agents_connected_at', current_time( 'mysql' ) );

		return array(
			'success'   => true,
			'connected' => true,
		);
	}

	/**
	 * Validate a JWT token.
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

		// Split the token.
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return $this->error_response(
				'invalid_token',
				__( 'Invalid token format.', 'arcadia-agents' ),
				401
			);
		}

		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		// Decode header.
		$header = json_decode( $this->base64url_decode( $header_b64 ), true );
		if ( ! $header || ! isset( $header['alg'] ) || 'RS256' !== $header['alg'] ) {
			return $this->error_response(
				'invalid_algorithm',
				__( 'Invalid or unsupported algorithm. Only RS256 is supported.', 'arcadia-agents' ),
				401
			);
		}

		// Decode payload.
		$payload = json_decode( $this->base64url_decode( $payload_b64 ), true );
		if ( ! $payload ) {
			return $this->error_response(
				'invalid_payload',
				__( 'Invalid token payload.', 'arcadia-agents' ),
				401
			);
		}

		// Verify signature.
		$signature = $this->base64url_decode( $signature_b64 );
		$data      = $header_b64 . '.' . $payload_b64;

		$public_key_resource = openssl_pkey_get_public( $public_key );
		if ( false === $public_key_resource ) {
			return $this->error_response(
				'invalid_public_key',
				__( 'Invalid public key configuration.', 'arcadia-agents' ),
				500
			);
		}

		$verify_result = openssl_verify( $data, $signature, $public_key_resource, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $verify_result ) {
			return $this->error_response(
				'invalid_signature',
				__( 'Token signature verification failed.', 'arcadia-agents' ),
				401
			);
		}

		// Check expiration.
		if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
			return $this->error_response(
				'token_expired',
				__( 'Token has expired.', 'arcadia-agents' ),
				401
			);
		}

		// Check not before.
		if ( isset( $payload['nbf'] ) && $payload['nbf'] > time() ) {
			return $this->error_response(
				'token_not_valid_yet',
				__( 'Token is not valid yet.', 'arcadia-agents' ),
				401
			);
		}

		// Update last activity.
		update_option( 'arcadia_agents_last_activity', current_time( 'mysql' ) );

		return $payload;
	}

	/**
	 * Check if a required scope is enabled.
	 *
	 * @param string $required_scope The scope to check.
	 * @param array  $token_scopes   Scopes from the JWT token.
	 * @return true|WP_Error True if scope is allowed, WP_Error otherwise.
	 */
	public function check_scope( $required_scope, $token_scopes = array() ) {
		// Get enabled scopes from WP settings.
		$all_scopes = array(
			'posts:read',
			'posts:write',
			'posts:delete',
			'media:read',
			'media:write',
			'taxonomies:read',
			'taxonomies:write',
			'site:read',
		);
		$enabled_scopes = get_option( 'arcadia_agents_scopes', $all_scopes );

		// Check if scope is enabled in WP settings.
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

		// Check if token has the required scope.
		if ( ! empty( $token_scopes ) && ! in_array( $required_scope, $token_scopes, true ) ) {
			return new WP_Error(
				'scope_not_granted',
				sprintf(
					/* translators: %s: scope name */
					__( "The token does not have the required '%s' scope.", 'arcadia-agents' ),
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
	 * Extract bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return string|WP_Error The token or WP_Error.
	 */
	public function get_bearer_token( $request ) {
		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return $this->error_response(
				'missing_authorization',
				__( 'Authorization header is required.', 'arcadia-agents' ),
				401
			);
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return $this->error_response(
				'invalid_authorization',
				__( 'Invalid Authorization header format. Expected: Bearer <token>', 'arcadia-agents' ),
				401
			);
		}

		return $matches[1];
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

		// Check scope if required.
		if ( null !== $required_scope ) {
			$token_scopes = isset( $payload['scopes'] ) ? $payload['scopes'] : array();
			$scope_check  = $this->check_scope( $required_scope, $token_scopes );
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
	 * Base64url decode.
	 *
	 * @param string $data Base64url encoded data.
	 * @return string Decoded data.
	 */
	private function base64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) );
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
		return true;
	}
}
