<?php
/**
 * Tests for Arcadia_Guard_Status — the publishing guard's single source of truth.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-guard-status.php';

/**
 * Test class for the guard status helpers (FS-4).
 */
class GuardStatusTest extends TestCase {

    protected function setUp(): void {
        global $_test_options;
        $_test_options = array();
        unset( $GLOBALS['_test_user_can'] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_test_user_can'] );
    }

    public function test_is_on_reflects_option(): void {
        global $_test_options;

        $this->assertFalse( \Arcadia_Guard_Status::is_on(), 'Missing option means guard off.' );

        $_test_options['aa_force_draft'] = true;
        $this->assertTrue( \Arcadia_Guard_Status::is_on() );

        $_test_options['aa_force_draft'] = false;
        $this->assertFalse( \Arcadia_Guard_Status::is_on() );
    }

    public function test_labels_differ_between_states(): void {
        global $_test_options;

        $_test_options['aa_force_draft'] = true;
        $label_on = \Arcadia_Guard_Status::label();
        $desc_on  = \Arcadia_Guard_Status::description();

        $_test_options['aa_force_draft'] = false;
        $label_off = \Arcadia_Guard_Status::label();
        $desc_off  = \Arcadia_Guard_Status::description();

        $this->assertNotSame( '', $label_on );
        $this->assertNotSame( '', $label_off );
        $this->assertNotSame( $label_on, $label_off );
        $this->assertNotSame( $desc_on, $desc_off );
    }

    public function test_chip_carries_state_modifier_class(): void {
        global $_test_options;

        $_test_options['aa_force_draft'] = true;
        $this->assertStringContainsString( 'aa-guard-chip--on', \Arcadia_Guard_Status::chip_html() );

        $_test_options['aa_force_draft'] = false;
        $this->assertStringContainsString( 'aa-guard-chip--off', \Arcadia_Guard_Status::chip_html() );
    }

    public function test_chip_links_to_settings_for_admins(): void {
        $html = \Arcadia_Guard_Status::chip_html( true );

        $this->assertStringContainsString( '<a ', $html );
        $this->assertStringContainsString( 'page=arcadia-agents-settings', $html );
    }

    public function test_unlinked_chip_has_no_anchor(): void {
        $html = \Arcadia_Guard_Status::chip_html( false );

        $this->assertStringNotContainsString( '<a', $html );
        $this->assertStringContainsString( '<span class="aa-guard-chip', $html );
    }

    public function test_chip_falls_back_to_span_without_manage_options(): void {
        global $_test_user_can;
        $_test_user_can = array( 'manage_options' => false );

        $html = \Arcadia_Guard_Status::chip_html( true );

        $this->assertStringNotContainsString( '<a', $html );
    }

    public function test_chip_emoji_is_decorative(): void {
        $this->assertStringContainsString( 'aria-hidden="true"', \Arcadia_Guard_Status::chip_html() );
    }
}
