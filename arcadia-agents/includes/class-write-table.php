<?php
/**
 * Write table: apply, hold or refuse — the one place that decides.
 *
 * Contract: docs/contracts/write-table.md (Phase 55 / CH03). Every write path
 * asks this class what to do and does exactly that; none decides on its own.
 * Before it, Force Draft was read in three places that disagreed: the update
 * path forced 'draft' on every non-published post (a scheduled post lost its
 * date, C61), the revision path refused a status equal to the live one (every
 * "Update" on iSelection ended in a 422, C03), and delete, taxonomy, media and
 * redirect writes never read it at all (C62).
 *
 * Pure logic: no WordPress call, so each cell of the table has its own unit
 * test (WriteTableTest). Changing a cell means changing the contract file in
 * the same commit. TODO(55.7): a build.sh check that HELD_ACTIONS and the
 * contract's `held_actions` list are the same (docs/ is not mounted in the
 * test container, so it cannot be a PHPUnit test).
 *
 * @package ArcadiaAgents
 * @since   0.13.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Write_Table
 */
final class Arcadia_Write_Table {

	const APPLY  = 'apply';
	const HOLD   = 'hold';
	const REFUSE = 'refuse';
	const NOOP   = 'noop';

	/**
	 * Actions held for approval while Force Draft is on, in contract order.
	 *
	 * Published as-is by /site-info (`held_actions`), so the server reads the
	 * table instead of guessing it from `force_draft`.
	 */
	const HELD_ACTIONS = array(
		'content.update',
		'content.featured_image',
		'content.delete',
		'term.update',
		'term.delete',
		'media.update',
		'media.delete',
		'redirect.create',
		'redirect.delete',
	);

	/**
	 * Writes that change nothing a visitor sees, so they always apply.
	 */
	const ALWAYS_APPLIED = array(
		'term.create',
		'media.upload',
	);

	/**
	 * Statuses a visitor sees, now or later: a scheduled post goes live alone.
	 */
	const LIVE_STATUSES = array( 'publish', 'future' );

	/**
	 * Whether Force Draft is on — the one switch that makes the table hold.
	 *
	 * Read here rather than through Arcadia_Guard_Status: that class is only
	 * loaded in wp-admin, and the write paths run in REST requests.
	 *
	 * @return bool
	 */
	public static function force_draft_on() {
		return (bool) get_option( 'aa_force_draft', false );
	}

	/**
	 * The WP_Error for a refusal the table decided.
	 *
	 * One place for the codes, statuses and messages of §6 of the contract, so
	 * every write path refuses with the same words.
	 *
	 * @param string $code  Refusal code.
	 * @param array  $extra Extra error data (merged into the error's data).
	 * @return WP_Error
	 */
	public static function refusal( $code, array $extra = array() ) {
		$messages = array(
			'status_not_supported_for_revision' => array(
				422,
				__( "This article is live and the site holds edits for approval: an approval cannot change the article's status. Send the edit without 'status', or with the article's current status.", 'arcadia-agents' ),
			),
			'post_trashed'                      => array(
				409,
				__( 'This article is in the trash. Restore it in WordPress before editing it.', 'arcadia-agents' ),
			),
			'pending_action_exists'             => array(
				409,
				__( 'A deletion of this article is waiting for approval in WordPress. Nothing was changed. Withdraw the deletion, or wait for the decision.', 'arcadia-agents' ),
			),
			'action_not_pending'                => array(
				409,
				__( 'This proposal is no longer waiting for approval.', 'arcadia-agents' ),
			),
			'pending_action_not_found'          => array(
				404,
				__( 'No proposal with this ID.', 'arcadia-agents' ),
			),
			'pending_action_stale'              => array(
				409,
				__( 'This proposal is out of date: the item changed on the site after Arcadia made it. Nothing was applied. Arcadia will propose again from the current version.', 'arcadia-agents' ),
			),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			throw new InvalidArgumentException( 'Unknown refusal code: ' . $code );
		}

		list( $status, $message ) = $messages[ $code ];
		$data                     = array_merge( array( 'status' => $status ), $extra );
		if ( 'status_not_supported_for_revision' === $code && ! isset( $data['field'] ) ) {
			$data['field'] = 'status';
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * The actions this site holds right now.
	 *
	 * @param bool $force_draft Whether Force Draft is on.
	 * @return string[]
	 */
	public static function held_actions( $force_draft ) {
		return $force_draft ? self::HELD_ACTIONS : array();
	}

	/**
	 * Whether a post in this status is seen by visitors (now or once scheduled).
	 *
	 * @param string $status post_status.
	 * @return bool
	 */
	public static function is_live( $status ) {
		return in_array( $status, self::LIVE_STATUSES, true );
	}

	/**
	 * Decision for a write that has no status dimension (terms, media, redirects,
	 * featured image of a post that is not live is handled by featured_image()).
	 *
	 * @param string $action      One of HELD_ACTIONS or ALWAYS_APPLIED.
	 * @param bool   $force_draft Whether Force Draft is on.
	 * @return string self::APPLY or self::HOLD.
	 */
	public static function simple( $action, $force_draft ) {
		if ( ! in_array( $action, self::HELD_ACTIONS, true ) && ! in_array( $action, self::ALWAYS_APPLIED, true ) ) {
			// A write path asking about an action the table does not know is a
			// programming error: fail loudly rather than apply by default.
			throw new InvalidArgumentException( 'Unknown write action: ' . $action );
		}
		return ( $force_draft && in_array( $action, self::HELD_ACTIONS, true ) ) ? self::HOLD : self::APPLY;
	}

	/**
	 * Creating a post.
	 *
	 * @param bool        $force_draft Whether Force Draft is on.
	 * @param string|null $requested   body.status, null when absent.
	 * @return array{decision:string, status:string, capped:bool}
	 */
	public static function content_create( $force_draft, $requested ) {
		$status = null === $requested ? 'draft' : $requested;
		if ( $force_draft && 'draft' !== $status ) {
			return array(
				'decision' => self::APPLY,
				'status'   => 'draft',
				'capped'   => true,
			);
		}
		return array(
			'decision' => self::APPLY,
			'status'   => $status,
			'capped'   => false,
		);
	}

	/**
	 * Updating an existing post.
	 *
	 * `status` in the result is the status to write, or null to leave the post's
	 * status untouched — never a value derived from Force Draft alone (C61).
	 *
	 * @param string      $current     The post's live post_status.
	 * @param bool        $force_draft Whether Force Draft is on.
	 * @param string|null $requested   body.status, null when absent.
	 * @return array{decision:string, status:string|null, capped:bool, error?:string}
	 */
	public static function content_update( $current, $force_draft, $requested ) {
		if ( 'trash' === $current ) {
			return array(
				'decision' => self::REFUSE,
				'status'   => null,
				'capped'   => false,
				'error'    => 'post_trashed',
			);
		}

		// A status equal to the current one asks for nothing (C03).
		if ( $requested === $current ) {
			$requested = null;
		}

		if ( ! $force_draft ) {
			return array(
				'decision' => self::APPLY,
				'status'   => $requested,
				'capped'   => false,
			);
		}

		if ( self::is_live( $current ) ) {
			if ( null !== $requested ) {
				// An approval cannot change a status (see update_post()).
				return array(
					'decision' => self::REFUSE,
					'status'   => null,
					'capped'   => false,
					'error'    => 'status_not_supported_for_revision',
				);
			}
			return array(
				'decision' => self::HOLD,
				'status'   => null,
				'capped'   => false,
			);
		}

		// Not live: apply, but never let the write make it live.
		if ( 'publish' === $requested || 'future' === $requested ) {
			return array(
				'decision' => self::APPLY,
				'status'   => 'draft',
				'capped'   => true,
			);
		}
		return array(
			'decision' => self::APPLY,
			'status'   => $requested,
			'capped'   => false,
		);
	}

	/**
	 * Setting the featured image of a post.
	 *
	 * @param string $current     The post's post_status.
	 * @param bool   $force_draft Whether Force Draft is on.
	 * @return string self::APPLY or self::HOLD.
	 */
	public static function featured_image( $current, $force_draft ) {
		return ( $force_draft && self::is_live( $current ) ) ? self::HOLD : self::APPLY;
	}

	/**
	 * Deleting a post.
	 *
	 * Without Force Draft a deletion always goes to the trash, whatever the post
	 * type (C63: wp_delete_post() deletes a custom type for good). A second
	 * deletion of a trashed post deletes it only when `force` is sent.
	 *
	 * @param string $current     The post's post_status.
	 * @param bool   $force_draft Whether Force Draft is on.
	 * @param bool   $force       body/query `force`.
	 * @return array{decision:string, mode:string|null} mode: 'trash' | 'delete' | null.
	 */
	public static function content_delete( $current, $force_draft, $force ) {
		$in_trash = ( 'trash' === $current );

		if ( $in_trash && ! $force ) {
			return array(
				'decision' => self::NOOP,
				'mode'     => null,
			);
		}
		$mode = $in_trash ? 'delete' : 'trash';
		if ( $force_draft ) {
			return array(
				'decision' => self::HOLD,
				'mode'     => $mode,
			);
		}
		return array(
			'decision' => self::APPLY,
			'mode'     => $mode,
		);
	}
}
