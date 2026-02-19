<?php
/**
 * Mock setup script for local testing.
 *
 * Usage: docker exec arcadia-wp php /var/www/html/wp-content/plugins/arcadia-agents/../../test/mock-setup.php
 * Or via WP-CLI: wp eval-file test/mock-setup.php
 *
 * This script:
 * 1. Generates RSA key pair
 * 2. Stores public key in WP options (mocking handshake)
 * 3. Outputs private key for JWT generation
 */

// Load WordPress if not already loaded.
if ( ! defined( 'ABSPATH' ) ) {
	// Find wp-load.php
	$wp_load_paths = array(
		'/var/www/html/wp-load.php',                    // Docker
		dirname( __DIR__ ) . '/../../../wp-load.php',  // Standard plugin location
	);

	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	die( "Error: Could not load WordPress.\n" );
}

echo "=== Arcadia Agents - Mock Setup ===\n\n";

// Generate RSA key pair.
$config = array(
	'private_key_bits' => 2048,
	'private_key_type' => OPENSSL_KEYTYPE_RSA,
);

$key = openssl_pkey_new( $config );
if ( ! $key ) {
	die( "Error: Could not generate RSA key pair.\n" );
}

// Extract private key.
openssl_pkey_export( $key, $private_key );

// Extract public key.
$key_details = openssl_pkey_get_details( $key );
$public_key  = $key_details['key'];

// Store public key in WP options (mock handshake).
update_option( 'arcadia_agents_public_key', $public_key );
update_option( 'arcadia_agents_connected', true );
update_option( 'arcadia_agents_connected_at', current_time( 'mysql' ) );
update_option( 'arcadia_agents_connection_key', 'aa_mock_test_key_' . wp_generate_password( 16, false ) );

// Enable all scopes.
$all_scopes = array(
	'articles:read',
	'articles:write',
	'articles:delete',
	'media:read',
	'media:write',
	'taxonomies:read',
	'taxonomies:write',
	'site:read',
);
update_option( 'arcadia_agents_scopes', $all_scopes );

echo "✓ RSA key pair generated\n";
echo "✓ Public key stored in WP options\n";
echo "✓ Connection marked as active\n";
echo "✓ All scopes enabled\n\n";

// Save private key to file for JWT generation.
$private_key_file = __DIR__ . '/private_key.pem';
file_put_contents( $private_key_file, $private_key );
chmod( $private_key_file, 0600 );

echo "Private key saved to: $private_key_file\n\n";

// Output public key for reference.
echo "=== Public Key (stored in WP) ===\n";
echo $public_key . "\n";

echo "=== Next Steps ===\n";
echo "1. Generate a JWT using the private key:\n";
echo "   php test/generate-jwt.php\n\n";
echo "2. Test the API:\n";
echo "   curl -H 'Authorization: Bearer <JWT>' http://localhost:8080/wp-json/arcadia/v1/articles\n\n";
