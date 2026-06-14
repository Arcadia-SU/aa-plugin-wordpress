<?php
/**
 * Tests for Arcadia_Block_Serializer (comment-safe block attribute encoding).
 *
 * Regression guard for block-comment injection: agent content containing
 * `-->` or `<!--` must not break out of the `<!-- wp:... -->` comment, while
 * every other value (URLs, accents, quotes) must round-trip unchanged.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-block-serializer.php';

/**
 * Test class for the block attribute serializer.
 */
class BlockSerializerTest extends TestCase {

	/**
	 * The closing comment sequence `-->` must never appear literally in output.
	 */
	public function test_escapes_comment_close_sequence(): void {
		$value = 'before --> after';
		$json  = \Arcadia_Block_Serializer::encode_attributes(
			array( 'data' => array( 'text' => $value ) )
		);

		// The comment-terminating sequence must be gone from the wire form...
		$this->assertStringNotContainsString( '-->', $json );
		// ...but the value is recovered losslessly when the block is parsed.
		$decoded = json_decode( $json, true );
		$this->assertSame( $value, $decoded['data']['text'] );
	}

	/**
	 * The opening comment sequence `<!--` must never appear literally in output.
	 */
	public function test_escapes_comment_open_sequence(): void {
		$value = 'x <!-- wp:core/html forged';
		$json  = \Arcadia_Block_Serializer::encode_attributes(
			array( 'data' => array( 'text' => $value ) )
		);

		// A forged opening delimiter must not survive as a literal `<!--`...
		$this->assertStringNotContainsString( '<!--', $json );
		// ...and the value is recovered losslessly when the block is parsed.
		$decoded = json_decode( $json, true );
		$this->assertSame( $value, $decoded['data']['text'] );
	}

	/**
	 * Escaping must be lossless: the original value is recovered by json_decode.
	 * The escaping changes the wire representation, not the data.
	 */
	public function test_value_survives_json_decode(): void {
		$attrs = array(
			'name' => 'acf/test',
			'data' => array(
				'body' => 'Breakout --> and <!-- open and a "quote"',
			),
			'mode' => 'preview',
		);

		$json    = \Arcadia_Block_Serializer::encode_attributes( $attrs );
		$decoded = json_decode( $json, true );

		$this->assertSame( $attrs, $decoded );
	}

	/**
	 * URLs keep literal forward slashes (JSON_UNESCAPED_SLASHES) — no `\/`.
	 * This is the behaviour relied on elsewhere; the fix must not change it.
	 */
	public function test_preserves_url_slashes(): void {
		$json = \Arcadia_Block_Serializer::encode_attributes(
			array( 'data' => array( 'url' => 'https://example.com/path' ) )
		);

		$this->assertStringContainsString( 'https://example.com/path', $json );
	}

	/**
	 * Accented characters stay raw (JSON_UNESCAPED_UNICODE).
	 */
	public function test_preserves_unicode(): void {
		$json = \Arcadia_Block_Serializer::encode_attributes(
			array( 'data' => array( 'title' => 'Café à la française' ) )
		);

		$this->assertStringContainsString( 'Café à la française', $json );
	}
}
