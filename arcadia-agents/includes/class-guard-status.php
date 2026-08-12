<?php
/**
 * Publishing guard (aa_force_draft) status — single source of truth.
 *
 * One place for the guard's state, labels and chip markup, consumed by the
 * settings page, the dashboard and the admin list screens (FS-4): the same
 * wording everywhere, or the guard stops being legible.
 *
 * @package ArcadiaAgents
 * @since   0.6.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Guard_Status
 *
 * Static helpers around the aa_force_draft option.
 */
class Arcadia_Guard_Status {

	/**
	 * Whether the guard is on (agent writes are forced to draft).
	 *
	 * @return bool
	 */
	public static function is_on() {
		return (bool) get_option( 'aa_force_draft', false );
	}

	/**
	 * Short label for the current state.
	 *
	 * @return string
	 */
	public static function label() {
		return self::is_on()
			? __( 'Draft only', 'arcadia-agents' )
			: __( 'Direct publishing', 'arcadia-agents' );
	}

	/**
	 * One-sentence description of what the current state means.
	 *
	 * @return string
	 */
	public static function description() {
		return self::is_on()
			? __( 'The agent saves as draft only.', 'arcadia-agents' )
			: __( 'The agent can publish live.', 'arcadia-agents' );
	}

	/**
	 * Factual status chip, shared by the list screens and the dashboard.
	 *
	 * The emoji is decorative (aria-hidden) and lives outside the translatable
	 * strings. Linked to the settings page only for users who can change the
	 * option; everyone else gets a plain span — the state itself is not a
	 * secret, it is already observable from the agent's behaviour.
	 *
	 * @param bool $linked Whether to link the chip to the settings page.
	 * @return string HTML.
	 */
	public static function chip_html( $linked = true ) {
		$on    = self::is_on();
		$state = $on ? 'on' : 'off';
		$icon  = $on ? '🛡' : '⚡';

		$text = sprintf(
			/* translators: %s: guard state label ("Draft only" / "Direct publishing") */
			__( 'Agent: %s', 'arcadia-agents' ),
			self::label()
		);

		$inner = sprintf(
			'<span class="aa-guard-chip__icon" aria-hidden="true">%s</span> %s',
			$icon,
			esc_html( $text )
		);

		if ( $linked && current_user_can( 'manage_options' ) ) {
			return sprintf(
				'<a class="aa-guard-chip aa-guard-chip--%1$s" href="%2$s" title="%3$s">%4$s</a>',
				esc_attr( $state ),
				esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ),
				esc_attr( self::description() ),
				$inner
			);
		}

		return sprintf(
			'<span class="aa-guard-chip aa-guard-chip--%1$s" title="%2$s">%3$s</span>',
			esc_attr( $state ),
			esc_attr( self::description() ),
			$inner
		);
	}
}
