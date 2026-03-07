<?php
/**
 * Tests for Arcadia_SEO_Meta class.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for SEO meta multi-plugin detection.
 */
class SeoMetaTest extends TestCase {

    /**
     * Define WPSEO_VERSION once before any test in this class.
     *
     * Must be in setUpBeforeClass() — not in a test method — because
     * phpunit.xml uses executionOrder="depends,defects" which can reorder
     * tests within a class. If the define lived in a specific test, defect
     * ordering could run other tests first (before the constant exists).
     */
    public static function setUpBeforeClass(): void {
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            define( 'WPSEO_VERSION', '22.0' );
        }
    }

    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        global $_test_post_meta, $_test_posts;
        $_test_post_meta = array();
        $_test_posts     = array();
    }

    /**
     * Test that Yoast meta is read when WPSEO_VERSION is defined.
     */
    public function test_yoast_detection_reads_yoast_meta(): void {
        global $_test_post_meta;
        $_test_post_meta[1] = array(
            '_yoast_wpseo_title'                       => 'Yoast Title',
            '_yoast_wpseo_metadesc'                    => 'Yoast Description',
            '_yoast_wpseo_canonical'                   => 'https://example.com/canonical',
            '_yoast_wpseo_opengraph-title'             => 'OG Title',
            '_yoast_wpseo_opengraph-description'       => 'OG Description',
            '_yoast_wpseo_meta-robots-noindex'         => '',
            '_yoast_wpseo_meta-robots-nofollow'        => '',
        );

        $seo = \Arcadia_SEO_Meta::get_seo_meta( 1 );

        $this->assertEquals( 'yoast', $seo['plugin'] );
        $this->assertEquals( 'Yoast Title', $seo['meta_title'] );
        $this->assertEquals( 'Yoast Description', $seo['meta_description'] );
        $this->assertEquals( 'https://example.com/canonical', $seo['canonical_url'] );
        $this->assertEquals( 'OG Title', $seo['og_title'] );
        $this->assertEquals( 'OG Description', $seo['og_description'] );
        $this->assertEquals( 'index,follow', $seo['robots'] );
    }

    /**
     * Test Yoast noindex/nofollow robots meta.
     */
    public function test_yoast_noindex_nofollow(): void {
        global $_test_post_meta;
        $_test_post_meta[2] = array(
            '_yoast_wpseo_title'                => '',
            '_yoast_wpseo_metadesc'             => '',
            '_yoast_wpseo_canonical'            => '',
            '_yoast_wpseo_opengraph-title'      => '',
            '_yoast_wpseo_opengraph-description' => '',
            '_yoast_wpseo_meta-robots-noindex'  => '1',
            '_yoast_wpseo_meta-robots-nofollow' => '1',
        );

        $seo = \Arcadia_SEO_Meta::get_seo_meta( 2 );

        $this->assertEquals( 'noindex,nofollow', $seo['robots'] );
    }

    /**
     * Test active plugin detection returns 'yoast' when defined.
     */
    public function test_get_active_plugin_yoast(): void {
        $this->assertEquals( 'yoast', \Arcadia_SEO_Meta::get_active_plugin() );
    }

    /**
     * Test is_yoast_active returns true when WPSEO_VERSION is defined.
     */
    public function test_is_yoast_active(): void {
        $this->assertTrue( \Arcadia_SEO_Meta::is_yoast_active() );
    }

    /**
     * Test SEO meta response structure has all required fields.
     */
    public function test_seo_meta_structure(): void {
        global $_test_post_meta;
        $_test_post_meta[3] = array();

        $seo = \Arcadia_SEO_Meta::get_seo_meta( 3 );

        $this->assertArrayHasKey( 'plugin', $seo );
        $this->assertArrayHasKey( 'meta_title', $seo );
        $this->assertArrayHasKey( 'meta_description', $seo );
        $this->assertArrayHasKey( 'canonical_url', $seo );
        $this->assertArrayHasKey( 'og_title', $seo );
        $this->assertArrayHasKey( 'og_description', $seo );
        $this->assertArrayHasKey( 'robots', $seo );
        $this->assertCount( 7, $seo );
    }

    /**
     * Test that all values are strings (no null, no arrays).
     */
    public function test_seo_meta_values_are_strings(): void {
        global $_test_post_meta;
        $_test_post_meta[4] = array();

        $seo = \Arcadia_SEO_Meta::get_seo_meta( 4 );

        foreach ( $seo as $key => $value ) {
            $this->assertIsString( $value, "SEO field '$key' should be a string" );
        }
    }

    /**
     * Test native fallback returns post title and excerpt when no SEO plugin.
     */
    public function test_native_fallback_uses_post_data(): void {
        // Since WPSEO_VERSION is already defined, we can't test this in the same
        // process. Instead, we test the static method directly.
        global $_test_posts;
        $_test_posts[10] = (object) array(
            'ID'           => 10,
            'post_title'   => 'Test Post Title',
            'post_excerpt' => 'Test excerpt text',
        );

        // Call native method directly via reflection.
        $reflection = new \ReflectionClass( \Arcadia_SEO_Meta::class );
        $method     = $reflection->getMethod( 'get_native_meta' );
        $method->setAccessible( true );

        $seo = $method->invoke( null, 10 );

        $this->assertEquals( 'none', $seo['plugin'] );
        $this->assertEquals( 'Test Post Title', $seo['meta_title'] );
        $this->assertEquals( 'Test excerpt text', $seo['meta_description'] );
        $this->assertEquals( 'index,follow', $seo['robots'] );
    }

    /**
     * Test RankMath meta reading via reflection.
     */
    public function test_rankmath_meta_reading(): void {
        global $_test_post_meta;
        $_test_post_meta[20] = array(
            'rank_math_title'                => 'RM Title',
            'rank_math_description'          => 'RM Description',
            'rank_math_canonical_url'        => 'https://example.com/rm',
            'rank_math_facebook_title'       => 'RM OG Title',
            'rank_math_facebook_description' => 'RM OG Desc',
            'rank_math_robots'               => array( 'index', 'follow' ),
        );

        $reflection = new \ReflectionClass( \Arcadia_SEO_Meta::class );
        $method     = $reflection->getMethod( 'get_rankmath_meta' );
        $method->setAccessible( true );

        $seo = $method->invoke( null, 20 );

        $this->assertEquals( 'rankmath', $seo['plugin'] );
        $this->assertEquals( 'RM Title', $seo['meta_title'] );
        $this->assertEquals( 'RM Description', $seo['meta_description'] );
        $this->assertEquals( 'index,follow', $seo['robots'] );
    }

    /**
     * Test AIOSEO meta reading via reflection.
     */
    public function test_aioseo_meta_reading(): void {
        global $_test_post_meta;
        $_test_post_meta[30] = array(
            '_aioseo_title'         => 'AIO Title',
            '_aioseo_description'   => 'AIO Description',
            '_aioseo_canonical_url' => 'https://example.com/aio',
            '_aioseo_og_title'      => 'AIO OG Title',
            '_aioseo_og_description' => 'AIO OG Desc',
            '_aioseo_noindex'       => '1',
            '_aioseo_nofollow'      => '',
        );

        $reflection = new \ReflectionClass( \Arcadia_SEO_Meta::class );
        $method     = $reflection->getMethod( 'get_aioseo_meta' );
        $method->setAccessible( true );

        $seo = $method->invoke( null, 30 );

        $this->assertEquals( 'aioseo', $seo['plugin'] );
        $this->assertEquals( 'AIO Title', $seo['meta_title'] );
        $this->assertEquals( 'noindex,follow', $seo['robots'] );
    }
}
