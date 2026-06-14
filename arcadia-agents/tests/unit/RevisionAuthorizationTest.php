<?php
/**
 * Tests for per-post capability enforcement on revision approve/reject (A6).
 *
 * A global `edit_posts` check let a contributor approve a revision targeting a
 * post they cannot edit. authorize_revision_action() must resolve the revision's
 * parent post and require `edit_post` on that specific post.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-revision-metabox.php';

/**
 * Test class for revision action authorization.
 */
class RevisionAuthorizationTest extends TestCase {

	/**
	 * Reset posts and capability stub before each test.
	 */
	protected function setUp(): void {
		global $_test_posts, $_test_user_can;
		$_test_posts    = array();
		$_test_user_can = null; // Default-allow unless a test overrides.
	}

	/**
	 * Register a revision (aa_revision) pointing at a parent post.
	 */
	private function make_revision( int $rev_id, int $parent_id ): void {
		global $_test_posts;
		$_test_posts[ $rev_id ] = (object) array(
			'ID'          => $rev_id,
			'post_type'   => 'aa_revision',
			'post_parent' => $parent_id,
		);
	}

	/**
	 * A user who can edit the parent post is authorized; returns the parent ID.
	 */
	public function test_authorizes_when_user_can_edit_parent(): void {
		global $_test_user_can;
		$this->make_revision( 100, 42 );
		// Can edit only post 42.
		$_test_user_can = fn( $cap, $post_id = 0 ) => ( 'edit_post' === $cap && 42 === $post_id );

		$metabox = new \Arcadia_Revision_Metabox();
		$result  = $metabox->authorize_revision_action( 100 );

		$this->assertSame( 42, $result );
	}

	/**
	 * A user who cannot edit the parent post is denied (the core A6 fix).
	 */
	public function test_denies_when_user_cannot_edit_parent(): void {
		global $_test_user_can;
		$this->make_revision( 100, 42 );
		// Can edit post 7 but NOT post 42 (the revision's target).
		$_test_user_can = fn( $cap, $post_id = 0 ) => ( 'edit_post' === $cap && 7 === $post_id );

		$metabox = new \Arcadia_Revision_Metabox();
		$result  = $metabox->authorize_revision_action( 100 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'permission_denied', $result->get_error_code() );
	}

	/**
	 * The capability is checked against the parent post id, not a global cap.
	 * Proven by granting edit_post globally-true but asserting the *argument*.
	 */
	public function test_checks_capability_against_parent_post_id(): void {
		global $_test_user_can;
		$this->make_revision( 100, 42 );
		$seen_post_id = null;
		$_test_user_can = function ( $cap, $post_id = 0 ) use ( &$seen_post_id ) {
			$seen_post_id = $post_id;
			return true;
		};

		$metabox = new \Arcadia_Revision_Metabox();
		$metabox->authorize_revision_action( 100 );

		$this->assertSame( 42, $seen_post_id );
	}

	/**
	 * A non-existent revision id is rejected.
	 */
	public function test_denies_unknown_revision(): void {
		$metabox = new \Arcadia_Revision_Metabox();
		$result  = $metabox->authorize_revision_action( 9999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'revision_not_found', $result->get_error_code() );
	}

	/**
	 * A post that is not an aa_revision is rejected (can't be used as a lever).
	 */
	public function test_denies_non_revision_post(): void {
		global $_test_posts;
		$_test_posts[ 55 ] = (object) array(
			'ID'          => 55,
			'post_type'   => 'post',
			'post_parent' => 0,
		);

		$metabox = new \Arcadia_Revision_Metabox();
		$result  = $metabox->authorize_revision_action( 55 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'revision_not_found', $result->get_error_code() );
	}
}
