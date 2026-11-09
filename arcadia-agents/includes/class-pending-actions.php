<?php
/**
 * Pending actions: writes the site holds for a human's approval.
 *
 * Contract: docs/contracts/write-table.md §3–§4 (Phase 55 / CH03). Every held
 * write other than an article edit lands here: deletions, term renames and
 * deletions, media edits and deletions, redirects, the featured image of a
 * live article. Article edits keep their own store (aa_revision, with preview
 * and diff), but answer the same contract: same `held` fields, same statuses,
 * same staleness rule, and they are listed by GET /pending-actions too.
 *
 * Every held write carries the version of the object it was computed from
 * (`base_version`). Approval compares it with the object as it is now and
 * refuses when they differ (C04): a proposal made on an older version is
 * never applied over changes made since.
 *
 * Approval applies the action through the SAME code as a direct write (the
 * executor, Arcadia_API::apply_held_action()): there is one implementation of
 * "delete this article" or "rename this category", not two.
 *
 * @package ArcadiaAgents
 * @since   0.13.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-write-table.php';

/**
 * Class Arcadia_Pending_Actions
 */
final class Arcadia_Pending_Actions {

	const POST_TYPE = 'aa_pending_action';

	/**
	 * Every status a pending action (or a revision) can be in — §4.
	 */
	const STATUSES = array( 'pending', 'approved', 'rejected', 'stale', 'superseded', 'withdrawn' );

	/**
	 * Object kinds, with the scope that reads them and the one that writes them.
	 */
	const KIND_SCOPES = array(
		'content'  => array( 'articles:read', 'articles:write' ),
		'term'     => array( 'taxonomies:read', 'taxonomies:write' ),
		'media'    => array( 'media:read', 'media:write' ),
		'redirect' => array( 'redirects:read', 'redirects:write' ),
	);

	/**
	 * Single instance.
	 *
	 * @var Arcadia_Pending_Actions|null
	 */
	private static $instance = null;

	/**
	 * Applies an approved action. Defaults to Arcadia_API::apply_held_action().
	 *
	 * @var callable|null
	 */
	private $executor = null;

	/**
	 * The action being applied right now by approve(), or 0.
	 *
	 * mark_content_stale() runs inside the executor of an approved deletion;
	 * without this it would mark that very deletion stale.
	 *
	 * @var int
	 */
	private $applying = 0;

	/**
	 * Get single instance.
	 *
	 * @return Arcadia_Pending_Actions
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Replace the executor (tests).
	 *
	 * @param callable|null $executor fn( string $action, array $object, array $payload ): WP_REST_Response|WP_Error.
	 */
	public function set_executor( $executor ) {
		$this->executor = $executor;
	}

	/**
	 * Register the statuses a proposal moves through.
	 *
	 * WP_Query only filters on REGISTERED statuses: asking for an unregistered
	 * one ('approved') drops the status filter entirely and returns every post
	 * of the type, pending ones included. The revision queries relied on these
	 * names since 0.2.0 without registering them. Internal: never listed in
	 * the admin, never public.
	 */
	public static function register_post_statuses() {
		foreach ( self::STATUSES as $status ) {
			if ( 'pending' === $status ) {
				continue; // Core status.
			}
			register_post_status(
				$status,
				array(
					'internal'                  => true,
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				)
			);
		}
	}

	/**
	 * Register the hidden CPT.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array( 'name' => 'AA Pending actions' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'rewrite'      => false,
			)
		);
	}

	// =========================================================================
	// Versions
	// =========================================================================

	/**
	 * The version of an object as it is now, or null when it does not exist.
	 *
	 * Content: its post_modified_gmt — WordPress bumps it on every save.
	 * Media, terms, redirects: a hash of the fields a visitor sees, because
	 * their edits do not all bump a date (an alt text is post meta).
	 *
	 * @param array $object {kind, type, id}.
	 * @return string|null
	 */
	public static function current_version( array $object ) {
		$id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		if ( $id <= 0 ) {
			return null;
		}

		switch ( $object['kind'] ) {
			case 'content':
				$post = get_post( $id );
				if ( ! $post ) {
					return null;
				}
				// The status is part of the version: trashing or unpublishing an
				// article changes what an approval would land on.
				return (string) self::field( $post, 'post_modified_gmt', '' ) . '|' . $post->post_status;

			case 'media':
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					return null;
				}
				return md5(
					wp_json_encode(
						array(
							$post->post_title,
							$post->post_excerpt,
							get_post_meta( $id, '_wp_attachment_image_alt', true ),
							$post->post_status,
						)
					)
				);

			case 'term':
				$term = get_term( $id, (string) $object['type'] );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				return md5(
					wp_json_encode(
						array(
							$term->name,
							$term->slug,
							(string) self::field( $term, 'description', '' ),
							(int) self::field( $term, 'parent', 0 ),
						)
					)
				);

			case 'redirect':
				$post = get_post( $id );
				if ( ! $post || 'arcadia_redirect' !== $post->post_type ) {
					return null;
				}
				return md5(
					wp_json_encode(
						array(
							$post->post_title,
							get_post_meta( $id, '_redirect_target', true ),
							(int) get_post_meta( $id, '_redirect_type', true ),
						)
					)
				);
		}

		return null;
	}

	/**
	 * A property of a WordPress object, or a default when it is absent.
	 *
	 * Real WP_Post/WP_Term objects always carry these fields; the unit-test
	 * stand-ins do not always, and reading a missing property is a warning.
	 *
	 * @param object $object  WP_Post, WP_Term or stand-in.
	 * @param string $name    Property.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function field( $object, $name, $default ) {
		$vars = get_object_vars( $object );
		return array_key_exists( $name, $vars ) && null !== $vars[ $name ] ? $vars[ $name ] : $default;
	}

	// =========================================================================
	// Hold
	// =========================================================================

	/**
	 * Store a held write and answer the 202 of §3.
	 *
	 * A pending proposal of the same action on the same object is superseded.
	 *
	 * @param string $action  One of Arcadia_Write_Table::HELD_ACTIONS (not content.update).
	 * @param array  $object  {kind, type, id}; id null for redirect.create.
	 * @param array  $payload What the executor needs to apply it later.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hold( $action, array $object, array $payload ) {
		$object = $this->normalize_object( $object );
		$base   = self::current_version( $object );

		$previous = $this->find_pending( $action, $object );

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'pending',
					// The screen describes the action in the reader's language
					// (describe()); the title is only a label for debugging.
					'post_title'  => $action . ' ' . $object['kind'] . ' ' . (string) $object['id'],
					'post_parent' => 'content' === $object['kind'] ? (int) $object['id'] : 0,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_aa_pa_action', $action );
		update_post_meta( $id, '_aa_pa_object', wp_slash( wp_json_encode( $object ) ) );
		update_post_meta( $id, '_aa_pa_base_version', null === $base ? '' : $base );
		// wp_slash(): update_post_meta() unslashes, and the payload is JSON.
		update_post_meta( $id, '_aa_pa_payload', wp_slash( wp_json_encode( $payload ) ) );

		if ( $previous ) {
			$this->set_status( $previous->ID, 'superseded', sprintf( 'Superseded by %d', $id ) );
		}

		do_action( 'aa_pending_action_changed', $id, 'pending' );

		return new WP_REST_Response( $this->held_fields( get_post( $id ) ), 202 );
	}

	/**
	 * The common fields of a held write (§3), for one stored action or revision.
	 *
	 * @param WP_Post $post An aa_pending_action or an aa_revision.
	 * @return array
	 */
	public function held_fields( $post ) {
		if ( 'aa_revision' === $post->post_type ) {
			$parent = get_post( $post->post_parent );
			$base   = get_post_meta( $post->ID, '_aa_revision_base_version', true );
			return array(
				'held'              => true,
				'pending_action_id' => (int) $post->ID,
				'action'            => 'content.update',
				'object'            => array(
					'kind' => 'content',
					'type' => $parent ? $parent->post_type : null,
					'id'   => (int) $post->post_parent,
				),
				'base_version'      => '' === $base ? null : $base,
				'review_url'        => admin_url( 'post.php?post=' . (int) $post->post_parent . '&action=edit' ),
				'message'           => 'Held for approval in WordPress. Nothing changed on the site.',
			);
		}

		$base = get_post_meta( $post->ID, '_aa_pa_base_version', true );
		return array(
			'held'              => true,
			'pending_action_id' => (int) $post->ID,
			'action'            => (string) get_post_meta( $post->ID, '_aa_pa_action', true ),
			'object'            => $this->stored_object( $post->ID ),
			'base_version'      => '' === $base ? null : $base,
			'review_url'        => $this->review_url( $post->ID ),
			'message'           => 'Held for approval in WordPress. Nothing changed on the site.',
		);
	}

	/**
	 * Admin URL of the approval screen for one action.
	 *
	 * @param int $id Pending action ID.
	 * @return string
	 */
	public function review_url( $id ) {
		return admin_url( 'admin.php?page=arcadia-pending&action_id=' . (int) $id );
	}

	/**
	 * The pending action of this kind on this object, if any.
	 *
	 * @param string $action Action.
	 * @param array  $object {kind, type, id}.
	 * @return WP_Post|null
	 */
	public function find_pending( $action, array $object ) {
		foreach ( $this->query( array( 'status' => 'pending' ) ) as $post ) {
			if ( get_post_meta( $post->ID, '_aa_pa_action', true ) !== $action ) {
				continue;
			}
			$stored = $this->stored_object( $post->ID );
			if ( $stored['kind'] === $object['kind'] && $stored['type'] === $object['type'] && $stored['id'] === $object['id'] ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * ID of the pending deletion of an article, or 0.
	 *
	 * @param int $post_id Article ID.
	 * @return int
	 */
	public function pending_delete_id( $post_id ) {
		foreach ( $this->query( array( 'status' => 'pending' ) ) as $post ) {
			if ( 'content.delete' !== get_post_meta( $post->ID, '_aa_pa_action', true ) ) {
				continue;
			}
			$stored = $this->stored_object( $post->ID );
			if ( 'content' === $stored['kind'] && (int) $post_id === (int) $stored['id'] ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	/**
	 * The 409 for a write on an article whose deletion waits, or null.
	 *
	 * @param int $post_id Article ID.
	 * @return WP_Error|null
	 */
	public function pending_delete_refusal( $post_id ) {
		$pending = $this->pending_delete_id( $post_id );
		if ( ! $pending ) {
			return null;
		}
		return Arcadia_Write_Table::refusal(
			'pending_action_exists',
			array(
				'pending_action_id' => $pending,
				'review_url'        => $this->review_url( $pending ),
			)
		);
	}

	// =========================================================================
	// Decisions
	// =========================================================================

	/**
	 * Approve: check the version, then apply through the executor.
	 *
	 * @param int    $id         Pending action ID.
	 * @param string $user_login Approver.
	 * @return array{approved:bool,response:mixed}|WP_Error
	 */
	public function approve( $id, $user_login ) {
		$post = $this->load( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'pending' !== $post->post_status ) {
			return Arcadia_Write_Table::refusal( 'action_not_pending' );
		}

		$object = $this->stored_object( $post->ID );
		$base   = (string) get_post_meta( $post->ID, '_aa_pa_base_version', true );
		$now    = self::current_version( $object );
		if ( '' !== $base && (string) $now !== $base ) {
			$this->set_status( $post->ID, 'stale', 'The item changed on the site after the proposal.', $user_login );
			return Arcadia_Write_Table::refusal( 'pending_action_stale' );
		}

		$action  = (string) get_post_meta( $post->ID, '_aa_pa_action', true );
		$payload = json_decode( (string) get_post_meta( $post->ID, '_aa_pa_payload', true ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'pending_action_corrupt', 'Pending action payload is corrupted.', array( 'status' => 500 ) );
		}

		$executor       = $this->executor ?? array( Arcadia_API::get_instance(), 'apply_held_action' );
		$this->applying = (int) $post->ID;
		try {
			$result = call_user_func( $executor, $action, $object, $payload );
		} finally {
			$this->applying = 0;
		}
		if ( is_wp_error( $result ) ) {
			// Left pending: the approver sees why and can retry or reject.
			return $result;
		}

		$this->set_status( $post->ID, 'approved', '', $user_login );

		return array(
			'approved' => true,
			'response' => $result instanceof WP_REST_Response ? $result->get_data() : $result,
		);
	}

	/**
	 * Reject.
	 *
	 * @param int    $id         Pending action ID.
	 * @param string $user_login Decider.
	 * @param string $notes      Optional reason.
	 * @return true|WP_Error
	 */
	public function reject( $id, $user_login, $notes = '' ) {
		$post = $this->load( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'pending' !== $post->post_status ) {
			return Arcadia_Write_Table::refusal( 'action_not_pending' );
		}
		$this->set_status( $post->ID, 'rejected', $notes, $user_login );
		return true;
	}

	/**
	 * Withdraw (Arcadia's own proposal) — pending actions AND revisions.
	 *
	 * @param int $id Pending action or revision ID.
	 * @return true|WP_Error
	 */
	public function withdraw( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, array( self::POST_TYPE, 'aa_revision' ), true ) ) {
			return Arcadia_Write_Table::refusal( 'pending_action_not_found' );
		}
		if ( 'pending' !== $post->post_status ) {
			return Arcadia_Write_Table::refusal( 'action_not_pending' );
		}
		$this->set_status( $post->ID, 'withdrawn', 'Withdrawn by Arcadia.', 'arcadia_agent' );
		return true;
	}

	/**
	 * Mark every pending proposal on an article stale (it was deleted).
	 *
	 * Revisions included: approving one would write into a trashed post.
	 *
	 * @param int $post_id Article ID.
	 */
	public function mark_content_stale( $post_id ) {
		foreach ( $this->query( array( 'status' => 'pending' ) ) as $post ) {
			if ( (int) $post->ID === $this->applying ) {
				continue;
			}
			$stored = $this->stored_object( $post->ID );
			if ( 'content' === $stored['kind'] && (int) $post_id === (int) $stored['id'] ) {
				$this->set_status( $post->ID, 'stale', 'The article was deleted.' );
			}
		}

		$revisions = get_posts(
			array(
				'post_type'        => 'aa_revision',
				'post_parent'      => (int) $post_id,
				'post_status'      => 'pending',
				'numberposts'      => 50,
				'suppress_filters' => true,
			)
		);
		foreach ( $revisions as $revision ) {
			$this->set_status( $revision->ID, 'stale', 'The article was deleted.' );
		}
	}

	/**
	 * Move one stored action or revision to a status, with its decision data.
	 *
	 * @param int    $id      Post ID (aa_pending_action or aa_revision).
	 * @param string $status  One of self::STATUSES.
	 * @param string $notes   Decision notes.
	 * @param string $by      Who decided (WP login, or arcadia_agent).
	 */
	public function set_status( $id, $status, $notes = '', $by = '' ) {
		$post   = get_post( $id );
		$prefix = ( $post && 'aa_revision' === $post->post_type ) ? '_aa_revision_' : '_aa_pa_';

		// edit_date: keep the creation date of the proposal (Phase 49.1).
		// arcadia:slash-safe — only post ID + status, no slashable content.
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => $status,
				'edit_date'   => true,
			)
		);
		if ( '' !== $by ) {
			update_post_meta( $id, $prefix . 'decided_by', sanitize_text_field( $by ) );
		}
		update_post_meta( $id, $prefix . 'decided_at', gmdate( 'c' ) );
		if ( '' !== $notes ) {
			update_post_meta( $id, $prefix . 'decision_notes', sanitize_textarea_field( $notes ) );
		}

		if ( $post && 'aa_revision' === $post->post_type ) {
			do_action( 'aa_revision_decided', (int) $id, $status );
		} else {
			do_action( 'aa_pending_action_changed', (int) $id, $status );
		}
	}

	// =========================================================================
	// Listing
	// =========================================================================

	/**
	 * List pending actions and revisions, newest first (§4).
	 *
	 * @param array $filters status, object_kind, object_id (all optional), kinds (allowed kinds).
	 * @return array<int, array>
	 */
	public function list_entries( array $filters ) {
		$status = isset( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ? $filters['status'] : null;
		$kinds  = $filters['kinds'] ?? array_keys( self::KIND_SCOPES );

		$posts = $this->query( array( 'status' => $status ) );
		if ( in_array( 'content', $kinds, true ) ) {
			$revisions = get_posts(
				array(
					'post_type'        => 'aa_revision',
					'post_status'      => null === $status ? self::STATUSES : $status,
					'numberposts'      => 200,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => true,
				)
			);
			$posts     = array_merge( $posts, $revisions );
		}

		$entries = array();
		foreach ( $posts as $post ) {
			$entry = $this->format_entry( $post );
			if ( ! in_array( $entry['object']['kind'], $kinds, true ) ) {
				continue;
			}
			if ( ! empty( $filters['object_kind'] ) && $entry['object']['kind'] !== $filters['object_kind'] ) {
				continue;
			}
			if ( ! empty( $filters['object_id'] ) && (int) $entry['object']['id'] !== (int) $filters['object_id'] ) {
				continue;
			}
			$entries[] = $entry;
		}

		usort(
			$entries,
			fn( $a, $b ) => strcmp( (string) $b['created_gmt'], (string) $a['created_gmt'] ) ?: ( $b['pending_action_id'] <=> $a['pending_action_id'] )
		);

		return $entries;
	}

	/**
	 * One listing entry: the held fields + status and decision data.
	 *
	 * @param WP_Post $post aa_pending_action or aa_revision.
	 * @return array
	 */
	public function format_entry( $post ) {
		$prefix = 'aa_revision' === $post->post_type ? '_aa_revision_' : '_aa_pa_';
		$fields = $this->held_fields( $post );
		unset( $fields['held'], $fields['message'] );

		return array_merge(
			$fields,
			array(
				'status'         => $post->post_status,
				'created_gmt'    => $this->created_gmt( $post ),
				'decided_gmt'    => get_post_meta( $post->ID, $prefix . 'decided_at', true ) ?: null,
				'decided_by'     => get_post_meta( $post->ID, $prefix . 'decided_by', true ) ?: null,
				'decision_notes' => get_post_meta( $post->ID, $prefix . 'decision_notes', true ) ?: null,
			)
		);
	}

	/**
	 * Pending actions for the approval screen, with what to show.
	 *
	 * @param string $status Status.
	 * @param int    $limit  Max rows.
	 * @return array<int, array{id:int, label:string, action:string, object:array, date:string, status:string, notes:string}>
	 */
	public function screen_rows( $status, $limit ) {
		$rows = array();
		foreach ( array_slice( $this->query( array( 'status' => $status ) ), 0, $limit ) as $post ) {
			$action  = (string) get_post_meta( $post->ID, '_aa_pa_action', true );
			$object  = $this->stored_object( $post->ID );
			$payload = json_decode( (string) get_post_meta( $post->ID, '_aa_pa_payload', true ), true );
			$rows[]  = array(
				'id'     => (int) $post->ID,
				'label'  => self::describe( $action, $object, is_array( $payload ) ? $payload : array() ),
				'action' => $action,
				'object' => $object,
				'date'   => (string) $post->post_date,
				'status' => $post->post_status,
				'notes'  => (string) get_post_meta( $post->ID, '_aa_pa_decision_notes', true ),
			);
		}
		return $rows;
	}

	/**
	 * What an action will do, in one sentence for the approver.
	 *
	 * Built at display time (not stored) so it follows the admin's language
	 * and the object's current name.
	 *
	 * @param string $action  Action.
	 * @param array  $object  {kind, type, id}.
	 * @param array  $payload Stored payload.
	 * @return string
	 */
	public static function describe( $action, array $object, array $payload ) {
		$id = (int) ( $object['id'] ?? 0 );

		switch ( $action ) {
			case 'content.delete':
				$post  = get_post( $id );
				$title = $post ? $post->post_title : '#' . $id;
				return 'delete' === ( $payload['mode'] ?? 'trash' )
					/* translators: %s: article title */
					? sprintf( __( 'Permanently delete “%s” (already in the trash)', 'arcadia-agents' ), $title )
					/* translators: %s: article title */
					: sprintf( __( 'Move “%s” to the trash', 'arcadia-agents' ), $title );

			case 'content.featured_image':
				$post = get_post( $id );
				/* translators: %s: article title */
				return sprintf( __( 'Change the featured image of “%s”', 'arcadia-agents' ), $post ? $post->post_title : '#' . $id );

			case 'term.update':
			case 'term.delete':
				$term = get_term( $id, (string) $object['type'] );
				$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $id;
				$kind = 'post_tag' === $object['type'] ? __( 'tag', 'arcadia-agents' ) : __( 'category', 'arcadia-agents' );
				if ( 'term.delete' === $action ) {
					/* translators: 1: "category" or "tag", 2: its name */
					return sprintf( __( 'Delete the %1$s “%2$s”', 'arcadia-agents' ), $kind, $name );
				}
				$new = $payload['args']['name'] ?? null;
				return $new
					/* translators: 1: "category" or "tag", 2: current name, 3: new name */
					? sprintf( __( 'Rename the %1$s “%2$s” to “%3$s”', 'arcadia-agents' ), $kind, $name, $new )
					/* translators: 1: "category" or "tag", 2: its name */
					: sprintf( __( 'Edit the %1$s “%2$s”', 'arcadia-agents' ), $kind, $name );

			case 'media.update':
			case 'media.delete':
				$post  = get_post( $id );
				$title = $post ? $post->post_title : '#' . $id;
				return 'media.delete' === $action
					/* translators: %s: media title */
					? sprintf( __( 'Delete the media “%s” — it disappears from every page that shows it', 'arcadia-agents' ), $title )
					/* translators: %s: media title */
					: sprintf( __( 'Edit the title, caption or alternative text of the media “%s”', 'arcadia-agents' ), $title );

			case 'redirect.create':
				/* translators: 1: source path, 2: target URL */
				return sprintf( __( 'Redirect visitors from %1$s to %2$s', 'arcadia-agents' ), $payload['source'] ?? '?', $payload['target'] ?? '?' );

			case 'redirect.delete':
				$post = get_post( $id );
				/* translators: %s: source path */
				return sprintf( __( 'Remove the redirect from %s', 'arcadia-agents' ), $post ? $post->post_title : '#' . $id );
		}

		return $action;
	}

	// =========================================================================
	// Internals
	// =========================================================================

	/**
	 * Stored actions, newest first.
	 *
	 * @param array $args status (null = all).
	 * @return WP_Post[]
	 */
	private function query( array $args ) {
		return get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => empty( $args['status'] ) ? self::STATUSES : $args['status'],
				'numberposts'      => 200,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Load one stored action.
	 *
	 * @param int $id ID.
	 * @return WP_Post|WP_Error
	 */
	private function load( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return Arcadia_Write_Table::refusal( 'pending_action_not_found' );
		}
		return $post;
	}

	/**
	 * The object an action targets, as stored.
	 *
	 * @param int $id Pending action ID.
	 * @return array{kind:string, type:string|null, id:int|null}
	 */
	private function stored_object( $id ) {
		$object = json_decode( (string) get_post_meta( $id, '_aa_pa_object', true ), true );
		return $this->normalize_object( is_array( $object ) ? $object : array() );
	}

	/**
	 * Canonical object shape.
	 *
	 * @param array $object Raw.
	 * @return array{kind:string, type:string|null, id:int|null}
	 */
	private function normalize_object( array $object ) {
		return array(
			'kind' => (string) ( $object['kind'] ?? '' ),
			'type' => isset( $object['type'] ) ? (string) $object['type'] : null,
			'id'   => isset( $object['id'] ) ? (int) $object['id'] : null,
		);
	}

	/**
	 * Creation instant as ISO 8601 UTC.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	private function created_gmt( $post ) {
		if ( empty( $post->post_date ) ) {
			return null;
		}
		$datetime = get_post_datetime( $post, 'date', 'gmt' );
		if ( $datetime instanceof DateTimeInterface ) {
			return $datetime->format( 'c' );
		}
		return gmdate( 'c', strtotime( get_gmt_from_date( $post->post_date ) ) );
	}
}
