<?php
/**
 * Tests for Arcadia_Gutenberg_Adapter native core-block renderers.
 *
 * Phase 34: quote(), separator() and table() produce native WordPress block
 * grammar for core/quote, core/separator and core/table.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load dependencies.
require_once dirname( __DIR__, 2 ) . '/includes/class-markdown-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';

/**
 * Test class for the Gutenberg adapter's core-block renderers.
 */
class GutenbergAdapterTest extends TestCase {

	/**
	 * Adapter instance.
	 *
	 * @var \Arcadia_Gutenberg_Adapter
	 */
	private $adapter;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		$this->adapter = new \Arcadia_Gutenberg_Adapter();
	}

	/**
	 * separator() emits a native wp:separator block.
	 */
	public function test_separator_renders_native_block(): void {
		$out = $this->adapter->separator();

		$this->assertStringContainsString( '<!-- wp:separator -->', $out );
		$this->assertStringContainsString( '<hr class="wp-block-separator', $out );
		$this->assertStringContainsString( '<!-- /wp:separator -->', $out );
	}

	/**
	 * quote() wraps inline-formatted content in a native wp:quote blockquote.
	 */
	public function test_quote_wraps_content_in_blockquote(): void {
		$out = $this->adapter->quote( 'A **bold** quote' );

		$this->assertStringContainsString( '<!-- wp:quote -->', $out );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote">', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( '<!-- /wp:quote -->', $out );
	}

	/**
	 * table() with headers emits thead + tbody.
	 */
	public function test_table_with_headers(): void {
		$out = $this->adapter->table(
			array( 'A', 'B' ),
			array(
				array( '1', '2' ),
				array( '3', '4' ),
			)
		);

		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table>', $out );
		$this->assertStringContainsString( '<thead><tr><th>A</th><th>B</th></tr></thead>', $out );
		$this->assertStringContainsString( '<tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></tbody>', $out );
	}

	/**
	 * table() without headers omits the thead.
	 */
	public function test_table_without_headers(): void {
		$out = $this->adapter->table( null, array( array( 'x', 'y' ) ) );

		$this->assertStringNotContainsString( '<thead>', $out );
		$this->assertStringContainsString( '<td>x</td>', $out );
		$this->assertStringContainsString( '<td>y</td>', $out );
	}

	/**
	 * table() converts inline markdown inside cells (no double-escape).
	 */
	public function test_table_cell_inline_markdown(): void {
		$out = $this->adapter->table(
			null,
			array( array( 'see [link](https://ext.example.com)' ) )
		);

		$this->assertStringContainsString( '<a href="https://ext.example.com"', $out );
	}
}
