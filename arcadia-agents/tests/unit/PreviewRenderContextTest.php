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
 * Two more gaps were in the same function: it never read the editor-assigned
 * page template, and it had no `page-*.php` branch at all, so even a plain
 * page preview landed on `single.php` — a template WordPress would never pick
 * for it.
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

		$_test_posts               = array();
		$_test_post_meta           = array();
		$_test_page_template_slugs = array();

		$reflection = new \ReflectionClass( \Arcadia_Preview::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->preview = \Arcadia_Preview::get_instance();
	}

	protected function tearDown(): void {
		global $_test_page_template_slugs;
		$_test_page_template_slugs = array();
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

	private function hierarchy( object $post ): array {
		$method = ( new \ReflectionClass( \Arcadia_Preview::class ) )->getMethod( 'get_preview_template_hierarchy' );
		$method->setAccessible( true );
		return $method->invoke( $this->preview, $post );
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
		$this->assertIsArray( $this->hierarchy( $this->context( $revision ) ) );
	}

	public function test_parentless_revision_falls_back_to_itself(): void {
		$revision = $this->seed( 104, 'aa_revision', 'rev-104', 0 );

		$this->assertSame( $revision, $this->context( $revision ) );
	}

	// ---------------------------------------------------------------------
	// Template hierarchy — the page branch that did not exist
	// ---------------------------------------------------------------------

	public function test_page_uses_the_page_branch(): void {
		$page = $this->seed( 110, 'page', 'investir' );

		$this->assertSame(
			array(
				'page-investir.php',
				'page-110.php',
				'page.php',
				'singular.php',
			),
			$this->hierarchy( $page )
		);
	}

	public function test_custom_page_template_wins(): void {
		global $_test_page_template_slugs;
		$page                             = $this->seed( 111, 'page', 'investir' );
		$_test_page_template_slugs[ 111 ] = 'templates/page-investir-rich.php';

		$this->assertSame(
			array(
				'templates/page-investir-rich.php',
				'page-investir.php',
				'page-111.php',
				'page.php',
				'singular.php',
			),
			$this->hierarchy( $page )
		);
	}

	/**
	 * WP ≥ 4.7 allows a page template on any post type, so the branch must not
	 * be limited to `page`.
	 */
	public function test_custom_template_applies_to_non_page_types(): void {
		global $_test_page_template_slugs;
		$post                             = $this->seed( 112, 'article', 'mon-article' );
		$_test_page_template_slugs[ 112 ] = 'templates/longform.php';

		$this->assertSame(
			array(
				'templates/longform.php',
				'single-article-mon-article.php',
				'single-article.php',
				'single.php',
				'singular.php',
			),
			$this->hierarchy( $post )
		);
	}

	public function test_page_branch_excludes_single_php(): void {
		$page = $this->seed( 113, 'page', 'investir' );

		$this->assertNotContains(
			'single.php',
			$this->hierarchy( $page ),
			'WordPress never falls back to single.php for a page.'
		);
	}

	// ---------------------------------------------------------------------
	// The two combined — the actual iSelection defect
	// ---------------------------------------------------------------------

	public function test_revision_of_a_templated_page_resolves_the_parent_template(): void {
		global $_test_page_template_slugs;
		$this->seed( 120, 'page', 'investir' );
		$_test_page_template_slugs[ 120 ] = 'page-investir-template.php';
		$revision                         = $this->seed( 121, 'aa_revision', 'rev-121', 120 );

		$templates = $this->hierarchy( $this->context( $revision ) );

		$this->assertSame(
			array(
				'page-investir-template.php',
				'page-investir.php',
				'page-120.php',
				'page.php',
				'singular.php',
			),
			$templates
		);

		foreach ( $templates as $candidate ) {
			$this->assertStringNotContainsString(
				'aa_revision',
				$candidate,
				'No candidate may still be derived from the revision itself.'
			);
		}
	}

	public function test_revision_of_an_article_resolves_the_article_hierarchy(): void {
		$this->seed( 130, 'article', 'mon-article' );
		$revision = $this->seed( 131, 'aa_revision', 'rev-131', 130 );

		$this->assertSame(
			array(
				'single-article-mon-article.php',
				'single-article.php',
				'single.php',
				'singular.php',
			),
			$this->hierarchy( $this->context( $revision ) )
		);
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
