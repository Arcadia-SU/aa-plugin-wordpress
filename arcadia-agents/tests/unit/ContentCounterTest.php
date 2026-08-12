<?php
/**
 * Tests for Arcadia_Content_Counter — live count of agent-created content.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-content-counter.php';

/**
 * Test class for the content counter (FS-2 view count, dashboard cards).
 */
class ContentCounterTest extends TestCase {

    protected function setUp(): void {
        \WP_Query::reset();
    }

    protected function tearDown(): void {
        \WP_Query::reset();
    }

    /**
     * The count is found_posts, not the (paginated) result size.
     */
    public function test_count_returns_found_posts(): void {
        \WP_Query::set_next_result( array( (object) array( 'ID' => 1 ) ), 42 );

        $this->assertSame( 42, \Arcadia_Content_Counter::count( 'post' ) );
    }

    public function test_count_is_zero_when_nothing_found(): void {
        \WP_Query::set_next_result( array(), 0 );

        $this->assertSame( 0, \Arcadia_Content_Counter::count( 'page' ) );
    }

    /**
     * The query is cheap and hardcoded: one row a page, arcadia_source term
     * fixed to 'arcadia' (never user input), caches skipped.
     */
    public function test_query_args_shape(): void {
        \WP_Query::set_next_result( array(), 7 );
        \Arcadia_Content_Counter::count( 'page', 'draft' );

        $args = \WP_Query::$last_args;

        $this->assertSame( 'page', $args['post_type'] );
        $this->assertSame( 'draft', $args['post_status'] );
        $this->assertSame( 1, $args['posts_per_page'] );
        $this->assertSame( 'ids', $args['fields'] );
        $this->assertFalse( $args['no_found_rows'] );
        $this->assertSame( 'arcadia_source', $args['tax_query'][0]['taxonomy'] );
        $this->assertSame( 'slug', $args['tax_query'][0]['field'] );
        $this->assertSame( 'arcadia', $args['tax_query'][0]['terms'] );
    }

    /**
     * Unknown statuses fall back to 'any' instead of leaking into the query.
     */
    public function test_unknown_status_falls_back_to_any(): void {
        \WP_Query::set_next_result( array(), 3 );
        \Arcadia_Content_Counter::count( 'post', 'totally-bogus' );

        $this->assertSame( 'any', \WP_Query::$last_args['post_status'] );
    }

    /**
     * Whitelisted statuses pass through untouched.
     */
    public function test_known_status_is_kept(): void {
        \WP_Query::set_next_result( array(), 3 );
        \Arcadia_Content_Counter::count( 'post', 'publish' );

        $this->assertSame( 'publish', \WP_Query::$last_args['post_status'] );
    }

    /**
     * 'any' itself is accepted as-is (it is not a registered status).
     */
    public function test_any_status_is_accepted(): void {
        \WP_Query::set_next_result( array(), 3 );
        \Arcadia_Content_Counter::count( 'post', 'any' );

        $this->assertSame( 'any', \WP_Query::$last_args['post_status'] );
    }
}
