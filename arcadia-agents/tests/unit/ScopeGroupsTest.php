<?php
/**
 * Tests for Arcadia_Auth::scope_groups() — the thematic permission groups.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-auth.php';

/**
 * The groups drive the settings-page render: a scope missing from every group
 * would never get a checkbox, a scope in two groups would get two. Both are
 * silent in the UI — so they must be loud here.
 */
class ScopeGroupsTest extends TestCase {

    /**
     * Union of all group scopes === all_scopes(): no missing, no duplicate.
     */
    public function test_groups_cover_all_scopes_exactly_once(): void {
        $grouped = array();
        foreach ( \Arcadia_Auth::scope_groups() as $key => $group ) {
            foreach ( $group['scopes'] as $scope ) {
                $grouped[] = $scope;
            }
        }

        // No duplicates across (or within) groups.
        $this->assertSame(
            array_values( array_unique( $grouped ) ),
            $grouped,
            'A scope appears in more than one group.'
        );

        // Exactly the supported scopes — order-insensitive (groups reorder for display).
        $all = \Arcadia_Auth::all_scopes();
        sort( $grouped );
        sort( $all );
        $this->assertSame( $all, $grouped );
    }

    /**
     * Every group has a non-empty label and at least one scope.
     */
    public function test_groups_have_labels_and_scopes(): void {
        $groups = \Arcadia_Auth::scope_groups();

        $this->assertNotEmpty( $groups );

        foreach ( $groups as $key => $group ) {
            $this->assertIsString( $key );
            $this->assertNotSame( '', $key );
            $this->assertArrayHasKey( 'label', $group );
            $this->assertNotSame( '', trim( $group['label'] ), "Group '$key' has an empty label." );
            $this->assertArrayHasKey( 'scopes', $group );
            $this->assertNotEmpty( $group['scopes'], "Group '$key' has no scopes." );
        }
    }

    /**
     * Every scope inside a group is a scope the plugin actually supports.
     */
    public function test_groups_reference_only_known_scopes(): void {
        $all = \Arcadia_Auth::all_scopes();

        foreach ( \Arcadia_Auth::scope_groups() as $key => $group ) {
            foreach ( $group['scopes'] as $scope ) {
                $this->assertContains( $scope, $all, "Group '$key' references unknown scope '$scope'." );
            }
        }
    }
}
