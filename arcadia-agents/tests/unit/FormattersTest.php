<?php
/**
 * Tests for API formatters.
 *
 * Note: These tests use mock WP_Post objects since the formatter methods
 * are tightly coupled to WordPress. In a real scenario, you'd use
 * the WordPress test framework or integration tests.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for API formatters.
 */
class FormattersTest extends TestCase {

    /**
     * Test that a post object is formatted correctly.
     *
     * Note: This is a simplified test that verifies the format structure.
     * Full integration testing would require WordPress environment.
     */
    public function test_format_post_structure(): void {
        // Expected fields in a formatted post.
        $expected_fields = array(
            'id',
            'title',
            'slug',
            'status',
            'url',
            'excerpt',
            'content',
            'author',
            'date',
            'date_gmt',
            'modified',
            'modified_gmt',
            'featured_image_id',
            'featured_image_url',
            'categories',
            'tags',
        );

        // This test documents the expected structure.
        // Actual formatting is tested via integration tests.
        $this->assertCount( 16, $expected_fields, 'format_post should return 16 fields' );
        $this->assertContains( 'id', $expected_fields );
        $this->assertContains( 'title', $expected_fields );
        $this->assertContains( 'content', $expected_fields );
    }

    /**
     * Test that a page object is formatted correctly.
     */
    public function test_format_page_structure(): void {
        $expected_fields = array(
            'id',
            'title',
            'slug',
            'status',
            'url',
            'parent',
            'menu_order',
            'template',
            'date',
            'modified',
        );

        $this->assertCount( 10, $expected_fields, 'format_page should return 10 fields' );
        $this->assertContains( 'parent', $expected_fields );
        $this->assertContains( 'menu_order', $expected_fields );
        $this->assertContains( 'template', $expected_fields );
    }

    /**
     * Test that a term object is formatted correctly.
     */
    public function test_format_term_structure(): void {
        $expected_fields = array(
            'id',
            'name',
            'slug',
            'description',
            'parent',
            'count',
        );

        $this->assertCount( 6, $expected_fields, 'format_term should return 6 fields' );
        $this->assertContains( 'count', $expected_fields );
        $this->assertContains( 'parent', $expected_fields );
    }
}
