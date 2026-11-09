<?php
/**
 * One test per cell of the write table (docs/contracts/write-table.md, §2).
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;
use Arcadia_Write_Table as T;

require_once dirname( __DIR__, 2 ) . '/includes/class-write-table.php';

/**
 * Class WriteTableTest
 */
class WriteTableTest extends TestCase {

    // ─── §2.1 Create ────────────────────────────────────────────────────

    public function test_create_without_force_draft_uses_requested_status_default_draft() {
        $this->assertSame( array( 'decision' => T::APPLY, 'status' => 'draft', 'capped' => false ), T::content_create( false, null ) );
        $this->assertSame( array( 'decision' => T::APPLY, 'status' => 'publish', 'capped' => false ), T::content_create( false, 'publish' ) );
    }

    public function test_create_with_force_draft_caps_to_draft_and_says_so() {
        $this->assertSame( array( 'decision' => T::APPLY, 'status' => 'draft', 'capped' => true ), T::content_create( true, 'publish' ) );
        $this->assertSame( array( 'decision' => T::APPLY, 'status' => 'draft', 'capped' => false ), T::content_create( true, null ) );
        $this->assertSame( array( 'decision' => T::APPLY, 'status' => 'draft', 'capped' => false ), T::content_create( true, 'draft' ) );
    }

    // ─── §2.1 Update ────────────────────────────────────────────────────

    /**
     * @return array<string, array{0:string,1:bool,2:?string,3:string,4:?string,5:bool,6:?string}>
     *   current, force_draft, requested → decision, status to write, capped, error.
     */
    public static function update_cells() {
        return array(
            // Force Draft off: everything applies, a same status asks for nothing.
            'off, live, no status'            => array( 'publish', false, null, T::APPLY, null, false, null ),
            'off, live, same status'          => array( 'publish', false, 'publish', T::APPLY, null, false, null ),
            'off, live, other status'         => array( 'publish', false, 'draft', T::APPLY, 'draft', false, null ),
            'off, draft, to publish'          => array( 'draft', false, 'publish', T::APPLY, 'publish', false, null ),
            'off, future, no status'          => array( 'future', false, null, T::APPLY, null, false, null ),
            // Force Draft on, live (publish or future): held as a revision.
            'on, publish, no status'          => array( 'publish', true, null, T::HOLD, null, false, null ),
            'on, publish, same status (C03)'  => array( 'publish', true, 'publish', T::HOLD, null, false, null ),
            'on, publish, other status'       => array( 'publish', true, 'draft', T::REFUSE, null, false, 'status_not_supported_for_revision' ),
            'on, future, no status (C61)'     => array( 'future', true, null, T::HOLD, null, false, null ),
            'on, future, to publish'          => array( 'future', true, 'publish', T::REFUSE, null, false, 'status_not_supported_for_revision' ),
            // Force Draft on, not live: applied, status kept (C61), never made live.
            'on, draft, no status'            => array( 'draft', true, null, T::APPLY, null, false, null ),
            'on, pending, no status (C61)'    => array( 'pending', true, null, T::APPLY, null, false, null ),
            'on, private, no status (C61)'    => array( 'private', true, null, T::APPLY, null, false, null ),
            'on, private, same status'        => array( 'private', true, 'private', T::APPLY, null, false, null ),
            'on, draft, to publish'           => array( 'draft', true, 'publish', T::APPLY, 'draft', true, null ),
            'on, pending, to publish'         => array( 'pending', true, 'publish', T::APPLY, 'draft', true, null ),
            'on, draft, to pending'           => array( 'draft', true, 'pending', T::APPLY, 'pending', false, null ),
            'on, draft, to private'           => array( 'draft', true, 'private', T::APPLY, 'private', false, null ),
            // Trash: refused either way.
            'off, trash'                      => array( 'trash', false, null, T::REFUSE, null, false, 'post_trashed' ),
            'on, trash'                       => array( 'trash', true, 'draft', T::REFUSE, null, false, 'post_trashed' ),
        );
    }

    /**
     * @dataProvider update_cells
     */
    public function test_update_cell( $current, $force_draft, $requested, $decision, $status, $capped, $error ) {
        $r = T::content_update( $current, $force_draft, $requested );
        $this->assertSame( $decision, $r['decision'] );
        $this->assertSame( $status, $r['status'] );
        $this->assertSame( $capped, $r['capped'] );
        $this->assertSame( $error, $r['error'] ?? null );
    }

    // ─── §2.1 Featured image ────────────────────────────────────────────

    public function test_featured_image_held_only_on_live_post_under_force_draft() {
        $this->assertSame( T::HOLD, T::featured_image( 'publish', true ) );
        $this->assertSame( T::HOLD, T::featured_image( 'future', true ) );
        $this->assertSame( T::APPLY, T::featured_image( 'draft', true ) );
        $this->assertSame( T::APPLY, T::featured_image( 'publish', false ) );
    }

    // ─── §2.1 Delete ────────────────────────────────────────────────────

    public static function delete_cells() {
        return array(
            'off, live, no force'          => array( 'publish', false, false, T::APPLY, 'trash' ),
            'off, live, force (still trash first)' => array( 'publish', false, true, T::APPLY, 'trash' ),
            'off, draft'                   => array( 'draft', false, false, T::APPLY, 'trash' ),
            'off, trashed, no force'       => array( 'trash', false, false, T::NOOP, null ),
            'off, trashed, force'          => array( 'trash', false, true, T::APPLY, 'delete' ),
            'on, live'                     => array( 'publish', true, false, T::HOLD, 'trash' ),
            'on, draft (every delete held)' => array( 'draft', true, false, T::HOLD, 'trash' ),
            'on, trashed, no force'        => array( 'trash', true, false, T::NOOP, null ),
            'on, trashed, force'           => array( 'trash', true, true, T::HOLD, 'delete' ),
        );
    }

    /**
     * @dataProvider delete_cells
     */
    public function test_delete_cell( $current, $force_draft, $force, $decision, $mode ) {
        $this->assertSame( array( 'decision' => $decision, 'mode' => $mode ), T::content_delete( $current, $force_draft, $force ) );
    }

    // ─── §2.2–2.4 Terms, media, redirects ───────────────────────────────

    public static function simple_cells() {
        return array(
            'term.create'     => array( 'term.create', T::APPLY ),
            'term.update'     => array( 'term.update', T::HOLD ),
            'term.delete'     => array( 'term.delete', T::HOLD ),
            'media.upload'    => array( 'media.upload', T::APPLY ),
            'media.update'    => array( 'media.update', T::HOLD ),
            'media.delete'    => array( 'media.delete', T::HOLD ),
            'redirect.create' => array( 'redirect.create', T::HOLD ),
            'redirect.delete' => array( 'redirect.delete', T::HOLD ),
        );
    }

    /**
     * @dataProvider simple_cells
     */
    public function test_simple_cell( $action, $with_force_draft ) {
        $this->assertSame( $with_force_draft, T::simple( $action, true ) );
        $this->assertSame( T::APPLY, T::simple( $action, false ) );
    }

    public function test_unknown_action_fails_loudly() {
        $this->expectException( \InvalidArgumentException::class );
        T::simple( 'content.typo', true );
    }

    // ─── §5 held_actions ────────────────────────────────────────────────

    public function test_held_actions_empty_without_force_draft() {
        $this->assertSame( array(), T::held_actions( false ) );
        $this->assertSame( T::HELD_ACTIONS, T::held_actions( true ) );
    }

    public function test_every_simple_held_action_is_listed() {
        foreach ( self::simple_cells() as $cell ) {
            if ( T::HOLD === $cell[1] ) {
                $this->assertContains( $cell[0], T::HELD_ACTIONS );
            }
        }
        // And every listed action is reachable by some cell.
        $reachable = array( 'content.update', 'content.featured_image', 'content.delete' );
        foreach ( self::simple_cells() as $cell ) {
            if ( T::HOLD === $cell[1] ) {
                $reachable[] = $cell[0];
            }
        }
        sort( $reachable );
        $listed = T::HELD_ACTIONS;
        sort( $listed );
        $this->assertSame( $listed, $reachable );
    }
}
