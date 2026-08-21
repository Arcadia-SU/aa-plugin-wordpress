<?php
/**
 * Test: a revision preview renders in its parent's clothes (Phase 41.2).
 *
 * The template hierarchy used to be derived from the previewed post itself.
 * For an `aa_revision` that post *is* the revision, so the candidates were
 * `single-aa_revision*.php`, all missing, and the render fell through to the
 * generic template. Observed on iSelection preprod: body class
 * `single-aa_revision postid-88553` where the live page renders
 * `single-page-investir page-investir-template-default`.
 *
 * The consequence is not cosmetic — the client approves a revision in a layout
 * that is not the page's. HITL rendered blind.
 *
 * Phase 41.2 fixed that by hand: a copied hierarchy, fed to locate_template().
 * The copy is gone — resolution now goes through core's own getters, so these
 * tests assert the two things that are still ours to get right: the queried
 * object handed to core is the parent, and whatever the theme's
 * `template_include` returns is what gets included.
 *
 * The third gap took longer to find. The queried object was the parent, but the
 * post *in the loop* stayed an `aa_revision`, and a theme that branches on the
 * loop post rendered nothing at all. See the post-type section below.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-preview.php';

class PreviewRenderContextTest extends TestCase {

	/** @var \Arcadia_Preview */
	private $preview;

	protected function setUp(): void {
		global $_test_posts, $_test_post_meta, $_test_page_template_slugs;
		global $_test_filters, $_test_template_getters, $_test_index_template;

		$_test_posts               = array();
		$_test_post_meta           = array();
		$_test_page_template_slugs = array();
		$_test_filters             = array();
		$_test_index_template      = '/tmp/index.php';
		$_test_template_getters    = array(
			'single'   => '/tmp/single.php',
			'page'     => '/tmp/page.php',
			'singular' => '',
		);

		$reflection = new \ReflectionClass( \Arcadia_Preview::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->preview = \Arcadia_Preview::get_instance();
	}

	protected function tearDown(): void {
		global $_test_page_template_slugs, $_test_filters;
		$_test_page_template_slugs = array();
		$_test_filters             = array();
		unset( $GLOBALS['wp_query'] );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function seed( int $id, string $post_type, string $post_name, int $parent = 0 ): object {
		global $_test_posts;
		$_test_posts[ $id ] = (object) array(
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_name'    => $post_name,
			'post_parent'  => $parent,
			'post_title'   => 'T' . $id,
			'post_status'  => 'publish',
			'post_content' => '',
			'post_excerpt' => '',
		);
		return $_test_posts[ $id ];
	}

	/**
	 * Put the preview in rendering state, then ask it for its template — the
	 * order handle_preview() uses, and the one core's getters require.
	 */
	private function resolve( object $post, ?object $context = null ): string {
		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $post, $context );

		$method = ( new \ReflectionClass( \Arcadia_Preview::class ) )->getMethod( 'resolve_template' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->preview );
	}

	private function candidates(): array {
		$prop = ( new \ReflectionClass( \Arcadia_Preview::class ) )->getProperty( 'template_candidates' );
		$prop->setAccessible( true );
		return $prop->getValue( $this->preview );
	}

	private function context( object $post ): object {
		$method = ( new \ReflectionClass( \Arcadia_Preview::class ) )->getMethod( 'resolve_render_context' );
		$method->setAccessible( true );
		return $method->invoke( $this->preview, $post );
	}

	// ---------------------------------------------------------------------
	// resolve_render_context
	// ---------------------------------------------------------------------

	public function test_revision_resolves_to_its_parent(): void {
		$parent   = $this->seed( 100, 'page', 'investir' );
		$revision = $this->seed( 101, 'aa_revision', 'rev-101', 100 );

		$this->assertSame( $parent, $this->context( $revision ) );
	}

	public function test_ordinary_post_is_its_own_context(): void {
		$post = $this->seed( 102, 'post', 'hello-world' );

		$this->assertSame( $post, $this->context( $post ) );
	}

	/**
	 * Nothing cascades revision deletion when the parent goes, so an orphan
	 * must degrade to its own context rather than fatal on a null parent.
	 */
	public function test_orphan_revision_falls_back_to_itself(): void {
		$revision = $this->seed( 103, 'aa_revision', 'rev-103', 9999 );

		$this->assertSame( $revision, $this->context( $revision ) );
	}

	public function test_parentless_revision_falls_back_to_itself(): void {
		$revision = $this->seed( 104, 'aa_revision', 'rev-104', 0 );

		$this->assertSame( $revision, $this->context( $revision ) );
	}

	// ---------------------------------------------------------------------
	// Template resolution — delegated to WordPress
	// ---------------------------------------------------------------------

	public function test_page_context_resolves_through_get_page_template(): void {
		$page = $this->seed( 110, 'page', 'investir' );

		$this->assertSame( '/tmp/page.php', $this->resolve( $page ) );
	}

	public function test_cpt_context_resolves_through_get_single_template(): void {
		$post = $this->seed( 112, 'article', 'mon-article' );

		$this->assertSame( '/tmp/single.php', $this->resolve( $post ) );
	}

	/**
	 * The Phase 41.2 guarantee, restated against core: a revision resolves in
	 * its parent's branch, never in `single-aa_revision*.php`.
	 */
	public function test_revision_resolves_in_its_parents_branch(): void {
		$parent   = $this->seed( 120, 'page', 'investir' );
		$revision = $this->seed( 121, 'aa_revision', 'rev-121', 120 );

		$this->assertSame( '/tmp/page.php', $this->resolve( $revision, $parent ) );
		$this->assertSame( $parent, $GLOBALS['wp_query']->queried_object );
	}

	public function test_falls_through_to_singular_when_the_branch_misses(): void {
		global $_test_template_getters;
		$_test_template_getters['single']   = '';
		$_test_template_getters['singular'] = '/tmp/singular.php';

		$post = $this->seed( 122, 'article', 'mon-article' );

		$this->assertSame( '/tmp/singular.php', $this->resolve( $post ) );
	}

	public function test_falls_through_to_index_when_nothing_else_matches(): void {
		global $_test_template_getters;
		$_test_template_getters['single']   = '';
		$_test_template_getters['singular'] = '';

		$post = $this->seed( 123, 'article', 'mon-article' );

		$this->assertSame( '/tmp/index.php', $this->resolve( $post ) );
	}

	/**
	 * The second way a preview came back bare: a theme that routes its
	 * templates by filter. Every request outside the preview goes through
	 * `template_include`; the preview used to skip it and include the file it
	 * had picked itself, which for such a theme is a stub that prints nothing.
	 */
	public function test_template_include_is_applied(): void {
		add_filter(
			'template_include',
			static function ( $template ) {
				return '/tmp/routed-by-the-theme.php';
			}
		);

		$post = $this->seed( 124, 'article', 'mon-article' );

		$this->assertSame( '/tmp/routed-by-the-theme.php', $this->resolve( $post ) );
	}

	public function test_candidates_are_captured_for_the_debug_report(): void {
		$post = $this->seed( 125, 'article', 'mon-article' );
		$this->resolve( $post );

		$this->assertSame( array( 'single.php' ), $this->candidates() );
	}

	/**
	 * The capture is a closure on four core hooks. Leaving it hooked would make
	 * it fire again for anything the theme renders after us.
	 */
	public function test_the_capture_is_unhooked_after_resolution(): void {
		global $_test_filters;

		$post = $this->seed( 126, 'article', 'mon-article' );
		$this->resolve( $post );

		foreach ( array( 'single_template_hierarchy', 'page_template_hierarchy', 'singular_template_hierarchy', 'index_template_hierarchy' ) as $hook ) {
			$this->assertEmpty( $_test_filters[ $hook ] ?? array(), $hook . ' still holds the capture.' );
		}
	}

	// ---------------------------------------------------------------------
	// The post in the loop — the Technologia defect
	// ---------------------------------------------------------------------

	/**
	 * A theme is entitled to branch on the loop post. Several do it as the very
	 * first statement of the template:
	 *
	 *     if ( 'expertise_sante' !== get_post_type() ) { return; }
	 *
	 * With an `aa_revision` in the loop that guard returns before printing a
	 * byte, and the preview falls through to render_fallback(). Measured on the
	 * Technologia preprod: two of ten pending revisions came back bare, and
	 * they were exactly the two whose post type has such a template.
	 */
	public function test_revision_wears_the_parents_post_type_in_the_loop(): void {
		$parent   = $this->seed( 200, 'expertise_sante', 'risque-grave' );
		$revision = $this->seed( 201, 'aa_revision', 'rev-201', 200 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $parent );

		$this->assertSame( 'expertise_sante', $revision->post_type );
		$this->assertSame( 'expertise_sante', $GLOBALS['wp_query']->posts[0]->post_type );
		$this->assertSame( 'expertise_sante', $GLOBALS['post']->post_type );
	}

	/**
	 * Only the type is borrowed. The ID stays the revision's, or every content
	 * and field read would land on the live post and the preview would show the
	 * page as it already is.
	 */
	public function test_borrowing_the_type_does_not_borrow_the_identity(): void {
		$parent   = $this->seed( 202, 'expertise_sante', 'risque-grave' );
		$revision = $this->seed( 203, 'aa_revision', 'rev-203', 202 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $parent );

		$this->assertSame( 203, $revision->ID );
		$this->assertSame( array( $revision ), $GLOBALS['wp_query']->posts );
		$this->assertSame( 202, $GLOBALS['wp_query']->queried_object_id );
	}

	public function test_an_ordinary_preview_keeps_its_own_type(): void {
		$post = $this->seed( 204, 'article', 'mon-article' );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $post );

		$this->assertSame( 'article', $post->post_type );
	}

	/**
	 * An orphan revision is its own context, so there is no type to borrow —
	 * and nothing must rewrite it to something the site does not have.
	 */
	public function test_orphan_revision_keeps_its_own_type(): void {
		$revision = $this->seed( 205, 'aa_revision', 'rev-205', 9999 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $this->context( $revision ) );

		$this->assertSame( 'aa_revision', $revision->post_type );
	}

	// ---------------------------------------------------------------------
	// Query state — where body_class() reads from
	// ---------------------------------------------------------------------

	public function test_queried_object_is_the_parent_but_the_loop_yields_the_revision(): void {
		$parent   = $this->seed( 140, 'page', 'investir' );
		$revision = $this->seed( 141, 'aa_revision', 'rev-141', 140 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $parent );

		$q = $GLOBALS['wp_query'];

		$this->assertSame( $parent, $q->queried_object, 'body_class() reads the queried object.' );
		$this->assertSame( 140, $q->queried_object_id );
		$this->assertSame( array( $revision ), $q->posts, 'The loop must still render the revision content.' );
		$this->assertSame( $revision, $GLOBALS['post'] );
	}

	public function test_page_context_sets_is_page_not_is_single(): void {
		$parent   = $this->seed( 150, 'page', 'investir' );
		$revision = $this->seed( 151, 'aa_revision', 'rev-151', 150 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $parent );

		$this->assertTrue( $GLOBALS['wp_query']->is_page );
		$this->assertFalse( $GLOBALS['wp_query']->is_single );
		$this->assertTrue( $GLOBALS['wp_query']->is_singular );
	}

	public function test_non_page_context_keeps_is_single(): void {
		$parent   = $this->seed( 160, 'article', 'mon-article' );
		$revision = $this->seed( 161, 'aa_revision', 'rev-161', 160 );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $revision, $parent );

		$this->assertTrue( $GLOBALS['wp_query']->is_single );
		$this->assertFalse( $GLOBALS['wp_query']->is_page );
	}

	/**
	 * The context argument is optional so the Phase 19 call shape keeps working.
	 */
	public function test_context_defaults_to_the_post_itself(): void {
		$post = $this->seed( 170, 'post', 'hello-world' );

		$GLOBALS['wp_query'] = new \WP_Query();
		$this->preview->setup_preview_state( $post );

		$this->assertSame( $post, $GLOBALS['wp_query']->queried_object );
		$this->assertSame( 170, $GLOBALS['wp_query']->queried_object_id );
	}

	public function test_preview_state_still_forces_publish_and_clears_404(): void {
		$parent           = $this->seed( 180, 'page', 'investir' );
		$revision         = $this->seed( 181, 'aa_revision', 'rev-181', 180 );
		$revision->post_status = 'draft';

		$GLOBALS['wp_query']         = new \WP_Query();
		$GLOBALS['wp_query']->is_404 = true;

		$this->preview->setup_preview_state( $revision, $parent );

		$this->assertSame( 'publish', $revision->post_status );
		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 1, $GLOBALS['wp_query']->post_count );
		$this->assertSame( 1, $GLOBALS['wp_query']->found_posts );
	}
}
