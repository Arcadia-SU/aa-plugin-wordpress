<?php
/**
 * Tests for Arcadia_Admin_List_UI — badge (FS-1) and views filter (FS-2).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-guard-status.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-content-counter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-admin-list-ui.php';

/**
 * Test class for the admin list-screen UI.
 */
class AdminListUiTest extends TestCase {

    protected function setUp(): void {
        global $_test_options, $_test_object_terms;
        $_test_options      = array();
        $_test_object_terms = array();
        $_GET               = array();
        \WP_Query::reset();
    }

    protected function tearDown(): void {
        $_GET = array();
        unset( $GLOBALS['_test_is_admin'], $GLOBALS['pagenow'], $GLOBALS['_test_user_can'] );
        \WP_Query::reset();
    }

    // -------------------------------------------------------
    // FS-1 — filter_post_states()
    // -------------------------------------------------------

    public function test_badge_added_for_agent_created_post(): void {
        global $_test_object_terms;
        $_test_object_terms[42]['arcadia_source'] = array( 'arcadia' );

        $post   = (object) array( 'ID' => 42 );
        $states = \Arcadia_Admin_List_UI::filter_post_states( array(), $post );

        $this->assertArrayHasKey( 'arcadia', $states );
        $this->assertStringContainsString( 'aa-badge', $states['arcadia'] );
    }

    public function test_badge_added_for_agent_created_page(): void {
        global $_test_object_terms;
        $_test_object_terms[7]['arcadia_source'] = array( 'arcadia' );

        $page   = (object) array( 'ID' => 7 );
        $states = \Arcadia_Admin_List_UI::filter_post_states( array(), $page );

        $this->assertArrayHasKey( 'arcadia', $states );
    }

    public function test_no_badge_without_term(): void {
        $post   = (object) array( 'ID' => 42 );
        $states = \Arcadia_Admin_List_UI::filter_post_states( array( 'draft' => 'Draft' ), $post );

        $this->assertArrayNotHasKey( 'arcadia', $states );
        $this->assertSame( array( 'draft' => 'Draft' ), $states );
    }

    public function test_no_badge_for_null_post(): void {
        $states = \Arcadia_Admin_List_UI::filter_post_states( array(), null );

        $this->assertArrayNotHasKey( 'arcadia', $states );
    }

    /**
     * post_states values are echoed as HTML by core: ours must contain only
     * our fixed markup, zero dynamic data.
     */
    public function test_badge_html_is_fixed_markup_only(): void {
        global $_test_object_terms;
        $_test_object_terms[42]['arcadia_source'] = array( 'arcadia' );

        $post   = (object) array( 'ID' => 42 );
        $states = \Arcadia_Admin_List_UI::filter_post_states( array(), $post );

        $this->assertSame( '<span class="aa-badge">Arcadia</span>', $states['arcadia'] );
    }

    // -------------------------------------------------------
    // FS-2 — build_view_link()
    // -------------------------------------------------------

    public function test_view_link_shape(): void {
        $link = \Arcadia_Admin_List_UI::build_view_link( 'post', 12, false );

        $this->assertStringContainsString( 'post_type=post', $link );
        $this->assertStringContainsString( 'aa_source=arcadia', $link );
        $this->assertStringContainsString( '<span class="count">(12)</span>', $link );
        $this->assertStringNotContainsString( 'current', $link );
    }

    public function test_view_link_current_state(): void {
        $link = \Arcadia_Admin_List_UI::build_view_link( 'page', 3, true );

        $this->assertStringContainsString( 'class="current"', $link );
        $this->assertStringContainsString( 'aria-current="page"', $link );
        $this->assertStringContainsString( 'post_type=page', $link );
    }

    // -------------------------------------------------------
    // FS-2 — filter_views()
    // -------------------------------------------------------

    public function test_view_hidden_when_count_is_zero(): void {
        \WP_Query::set_next_result( array(), 0 );

        $views = \Arcadia_Admin_List_UI::filter_views( array( 'all' => '<a href="edit.php?post_type=post">All</a>' ), 'post' );

        $this->assertArrayNotHasKey( 'arcadia', $views );
    }

    public function test_view_inserted_after_all(): void {
        \WP_Query::set_next_result( array(), 5 );

        $views = \Arcadia_Admin_List_UI::filter_views(
            array(
                'all'   => '<a href="edit.php?post_type=post">All</a>',
                'draft' => '<a href="edit.php?post_status=draft&#038;post_type=post">Drafts</a>',
            ),
            'post'
        );

        $this->assertSame( array( 'all', 'arcadia', 'draft' ), array_keys( $views ) );
        $this->assertStringContainsString( '(5)', $views['arcadia'] );
    }

    public function test_active_filter_rewrites_status_links_and_marks_current(): void {
        $_GET['aa_source'] = 'arcadia';
        \WP_Query::set_next_result( array(), 5 );

        $views = \Arcadia_Admin_List_UI::filter_views(
            array(
                'all'   => '<a href="edit.php?post_type=post">All</a>',
                'draft' => '<a href="edit.php?post_status=draft&#038;post_type=post">Drafts</a>',
            ),
            'post'
        );

        // Status links keep the filter…
        $this->assertStringContainsString( 'aa_source=arcadia', $views['draft'] );
        // …"All" never does: it is the exit door.
        $this->assertStringNotContainsString( 'aa_source', $views['all'] );
        // The Arcadia view is marked current.
        $this->assertStringContainsString( 'class="current"', $views['arcadia'] );
    }

    // -------------------------------------------------------
    // FS-2 — add_query_arg_to_views(): security contract
    // -------------------------------------------------------

    public function test_rewrite_leaves_href_less_views_intact(): void {
        $views = array(
            'all'   => '<a href="edit.php?post_type=post">All</a>',
            'weird' => '<span>No link here</span>',
        );

        $result = \Arcadia_Admin_List_UI::add_query_arg_to_views( $views );

        $this->assertSame( $views['weird'], $result['weird'] );
        $this->assertSame( $views['all'], $result['all'] );
    }

    /**
     * Hostile fixture (blocking review finding): an href smuggling an
     * entity-encoded attribute breakout must come out neutralized — the
     * rewritten value passes through esc_url(), which strips " < >.
     */
    public function test_rewrite_neutralizes_hostile_href(): void {
        $views = array(
            'draft' => '<a href="edit.php?post_status=draft&amp;x=&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">Drafts</a>',
        );

        $result = \Arcadia_Admin_List_UI::add_query_arg_to_views( $views );

        $this->assertStringNotContainsString( '<script', $result['draft'] );
        $this->assertStringNotContainsString( '"><', $result['draft'] );
        $this->assertStringContainsString( 'aa_source=arcadia', $result['draft'] );
    }

    // -------------------------------------------------------
    // should_filter() — decision matrix
    // -------------------------------------------------------

    /**
     * @dataProvider provide_should_filter_matrix
     */
    public function test_should_filter_matrix( bool $expected, bool $is_admin, string $pagenow, bool $is_main, string $post_type, array $get ): void {
        $this->assertSame(
            $expected,
            \Arcadia_Admin_List_UI::should_filter( $is_admin, $pagenow, $is_main, $post_type, $get )
        );
    }

    public static function provide_should_filter_matrix(): array {
        $on = array( 'aa_source' => 'arcadia' );

        return array(
            'all conditions met (post)'  => array( true, true, 'edit.php', true, 'post', $on ),
            'all conditions met (page)'  => array( true, true, 'edit.php', true, 'page', $on ),
            'not admin'                  => array( false, false, 'edit.php', true, 'post', $on ),
            'wrong page'                 => array( false, true, 'upload.php', true, 'post', $on ),
            'not main query'             => array( false, true, 'edit.php', false, 'post', $on ),
            'unsupported post type'      => array( false, true, 'edit.php', true, 'attachment', $on ),
            'param absent'               => array( false, true, 'edit.php', true, 'post', array() ),
            'wrong value'                => array( false, true, 'edit.php', true, 'post', array( 'aa_source' => 'evil' ) ),
            'array value'                => array( false, true, 'edit.php', true, 'post', array( 'aa_source' => array( 'arcadia' ) ) ),
        );
    }

    // -------------------------------------------------------
    // filter_admin_query()
    // -------------------------------------------------------

    public function test_tax_query_added_on_the_gated_path(): void {
        global $_test_is_admin, $pagenow;
        $_test_is_admin    = true;
        $pagenow           = 'edit.php';
        $_GET['aa_source'] = 'arcadia';

        $query            = new \WP_Query();
        $query->is_main   = true;
        $query->query_vars['post_type'] = 'post';

        \Arcadia_Admin_List_UI::filter_admin_query( $query );

        $tax_query = $query->get( 'tax_query' );
        $this->assertIsArray( $tax_query );
        // Hardcoded term — the $_GET value only decides WHETHER to filter.
        $this->assertSame( 'arcadia_source', $tax_query[0]['taxonomy'] );
        $this->assertSame( 'slug', $tax_query[0]['field'] );
        $this->assertSame( 'arcadia', $tax_query[0]['terms'] );
    }

    public function test_no_tax_query_for_secondary_queries(): void {
        global $_test_is_admin, $pagenow;
        $_test_is_admin    = true;
        $pagenow           = 'edit.php';
        $_GET['aa_source'] = 'arcadia';

        $query          = new \WP_Query();
        $query->is_main = false; // e.g. a widget query on the same request.
        $query->query_vars['post_type'] = 'post';

        \Arcadia_Admin_List_UI::filter_admin_query( $query );

        $this->assertSame( '', $query->get( 'tax_query' ) );
    }

    public function test_existing_tax_query_is_preserved(): void {
        global $_test_is_admin, $pagenow;
        $_test_is_admin    = true;
        $pagenow           = 'edit.php';
        $_GET['aa_source'] = 'arcadia';

        $query          = new \WP_Query();
        $query->is_main = true;
        $query->query_vars['post_type'] = 'post';
        $query->set( 'tax_query', array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => 'news' ) ) );

        \Arcadia_Admin_List_UI::filter_admin_query( $query );

        $tax_query = $query->get( 'tax_query' );
        $this->assertCount( 2, $tax_query );
        $this->assertSame( 'category', $tax_query[0]['taxonomy'] );
        $this->assertSame( 'arcadia_source', $tax_query[1]['taxonomy'] );
    }

    // -------------------------------------------------------
    // render_filter_row()
    // -------------------------------------------------------

    public function test_filter_row_renders_guard_chip(): void {
        ob_start();
        \Arcadia_Admin_List_UI::render_filter_row( 'post', 'top' );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'aa-guard-chip', $html );
    }

    public function test_filter_row_hidden_input_when_filter_active(): void {
        $_GET['aa_source'] = 'arcadia';

        ob_start();
        \Arcadia_Admin_List_UI::render_filter_row( 'post', 'top' );
        $html = ob_get_clean();

        $this->assertStringContainsString( '<input type="hidden" name="aa_source" value="arcadia" />', $html );
    }

    public function test_filter_row_no_hidden_input_when_inactive(): void {
        ob_start();
        \Arcadia_Admin_List_UI::render_filter_row( 'post', 'top' );
        $html = ob_get_clean();

        $this->assertStringNotContainsString( '<input', $html );
    }

    public function test_filter_row_skips_unsupported_post_types(): void {
        ob_start();
        \Arcadia_Admin_List_UI::render_filter_row( 'attachment', 'top' );
        $html = ob_get_clean();

        $this->assertSame( '', $html );
    }

    public function test_filter_row_renders_once_top_only(): void {
        ob_start();
        \Arcadia_Admin_List_UI::render_filter_row( 'post', 'bottom' );
        $html = ob_get_clean();

        $this->assertSame( '', $html );
    }
}
