<?php
/**
 * Generate a test JWT for local testing.
 *
 * Usage: php test/generate-jwt.php
 *
 * Requires: composer install in arcadia-agents/ directory
 */

// Load composer autoload.
$autoload = __DIR__ . '/../arcadia-agents/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	die( "Error: Run 'composer install' in arcadia-agents/ directory first.\n" );
}
require_once $autoload;

use Firebase\JWT\JWT;

// Load private key.
$private_key_file = __DIR__ . '/private_key.pem';
if ( ! file_exists( $private_key_file ) ) {
	die( "Error: Private key not found. Run mock-setup.php first.\n" );
}
$private_key = file_get_contents( $private_key_file );

// JWT payload.
$now = time();
$payload = array(
	'iss'    => 'arcadia-agents-test',
	'sub'    => 'test-site',
	'iat'    => $now,
	'nbf'    => $now,
	'exp'    => $now + 3600, // 1 hour
	'scopes' => array(
		'articles:read',
		'articles:write',
		'articles:delete',
		'media:read',
		'media:write',
		'taxonomies:read',
		'taxonomies:write',
		'site:read',
	),
);

// Generate JWT.
$jwt = JWT::encode( $payload, $private_key, 'RS256' );

echo "=== Generated JWT (valid for 1 hour) ===\n\n";
echo $jwt . "\n\n";

echo "=== Test Commands ===\n\n";

echo "# Health check (no auth):\n";
echo "curl -s http://localhost:8080/wp-json/arcadia/v1/health | jq .\n\n";

echo "# List articles:\n";
echo "curl -s -H 'Authorization: Bearer $jwt' http://localhost:8080/wp-json/arcadia/v1/articles | jq .\n\n";

echo "# Get site info:\n";
echo "curl -s -H 'Authorization: Bearer $jwt' http://localhost:8080/wp-json/arcadia/v1/site-info | jq .\n\n";

echo "# Create a test article:\n";
$test_post = json_encode( array(
	'title'   => 'Test Article from API',
	'content' => array(
		'meta' => array(
			'title'       => 'Test Article SEO Title',
			'slug'        => 'test-article-api',
			'description' => 'This is a test article created via the Arcadia Agents API.',
			'categories'  => array( 'Uncategorized' ),
			'tags'        => array( 'test', 'api' ),
		),
		'h1'       => 'Test Article from Arcadia Agents',
		'sections' => array(
			array(
				'content' => array(
					array(
						'type' => 'paragraph',
						'text' => 'This is the introduction paragraph with a [test link](https://example.com).',
					),
				),
			),
			array(
				'heading'     => 'First Section',
				'subsections' => array(
					array(
						'content' => array(
							array(
								'type' => 'paragraph',
								'text' => 'Content under the first H2 heading.',
							),
							array(
								'type'    => 'list',
								'ordered' => false,
								'items'   => array( 'Item one', 'Item two', 'Item three' ),
							),
						),
					),
				),
			),
		),
	),
	'status'  => 'draft',
), JSON_PRETTY_PRINT );

echo "curl -s -X POST \\\n";
echo "  -H 'Authorization: Bearer $jwt' \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '$test_post' \\\n";
echo "  http://localhost:8080/wp-json/arcadia/v1/articles | jq .\n\n";

// Save JWT to file for convenience.
$jwt_file = __DIR__ . '/test_jwt.txt';
file_put_contents( $jwt_file, $jwt );
echo "JWT saved to: $jwt_file\n";
