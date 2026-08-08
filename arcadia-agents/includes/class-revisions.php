<?php
/**
 * Pending Revisions management via hidden Custom Post Type.
 *
 * Registers the aa_revision CPT and provides CRUD methods
 * for creating, approving, rejecting, and listing revisions.
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arcadia_Revisions
 *
 * Manages pending revisions stored as a hidden Custom Post Type.
 * When the agent sends `pending_revision: true` on a PUT /articles/{id},
 * the update is stored as an aa_revision instead of modifying the live post.
 */
class Arcadia_Revisions {

	/**
	 * Maximum number of revisions kept per post.
	 *
	 * Every edit creates an aa_revision; without a cap the CPT grows unbounded
	 * on busy posts. After inserting a new revision we prune the oldest decided
	 * ones beyond this many, never touching the live pending revision.
	 *
	 * @var int
	 */
	const RETENTION_PER_POST = 30;

	/**
	 * Single instance.
	 *
	 * @var Arcadia_Revisions|null
	 */
	private static $instance = null;

	/**
	 * Get single instance.
	 *
	 * @return Arcadia_Revisions
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
	 * Register the aa_revision CPT.
	 *
	 * Hidden from admin UI and frontend. Content is the rendered
	 * post_content of the proposed revision.
	 */
	public function register_post_type() {
		register_post_type(
			'aa_revision',
			array(
				'labels'       => array( 'name' => 'AA Revisions' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Create a pending revision for a published post.
	 *
	 * Auto-supersedes any existing pending revision for the same post.
	 * Generates a preview token for the revision.
	 *
	 * @param int         $post_id          The original post ID.
	 * @param array       $body             The full request body (title, excerpt, content, acf_fields, etc.).
	 * @param array       $meta             The meta array from the request body.
	 * @param string|null $rendered_content  The rendered post_content (blocks HTML) or null.
	 * @return array|WP_Error Array with revision_id, revision_version, preview_url on success.
	 */
	public function create_revision( $post_id, $body, $meta, $rendered_content = null, $skip_markdown = false ) {
		// Existing pending revision is superseded after the new one is inserted,
		// so its decision note can reference the replacing revision ID.
		$existing = $this->get_pending_revision( $post_id );

		// Compute next version number.
		$version = $this->get_next_version( $post_id );

		// Build the title for the revision CPT — a display label in wp-admin, not
		// a value that reaches the live post. meta.title is deliberately NOT a
		// fallback here: it is the SEO meta-title, so using it would label the
		// pending revision with a string that is not the page's H1 (Phase 42.3).
		if ( ! empty( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
		} else {
			$original = get_post( $post_id );
			$title    = $original ? $original->post_title : '';
		}

		// Insert the revision CPT.
		//
		// wp_slash() is mandatory: wp_insert_post() runs wp_unslash() internally,
		// and $rendered_content is block markup whose attributes are JSON built with
		// wp_json_encode() — full of backslash escapes (\r, \n, \"). Without slashing
		// first, wp_unslash() strips those backslashes, turning newlines into literal
		// "rn" and breaking the block-comment JSON so parse_blocks() leaks raw markup.
		// Every other write path slashes (see class-post-builder write_post(),
		// trait-api-posts update path, and the approve path below).
		$revision_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'aa_revision',
					'post_parent'  => $post_id,
					'post_status'  => 'pending',
					'post_title'   => $title,
					'post_content' => $rendered_content ?? '',
				)
			),
			true
		);

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		// Auto-supersede the previous pending revision, referencing its replacement.
		if ( $existing ) {
			// arcadia:slash-safe — only post ID + status, no slashable content.
			wp_update_post(
				array(
					'ID'          => $existing->ID,
					'post_status' => 'superseded',
				)
			);
			update_post_meta(
				$existing->ID,
				'_aa_revision_decision_notes',
				sprintf( 'Superseded by revision %d', $revision_id )
			);
		}

		// Store the complete payload as JSON for replay on approve.
		// skip_markdown rides along: it is a property of the originating request,
		// and approve_revision() has no request to read it from.
		$revision_meta = array(
			'body'          => $body,
			'meta'          => $meta,
			'skip_markdown' => (bool) $skip_markdown,
		);
		update_post_meta( $revision_id, '_aa_revision_version', $version );
		// wp_slash() is mandatory for the same reason as post_content above:
		// update_post_meta() runs wp_unslash() internally, and this is a JSON
		// blob from wp_json_encode() (backslash escapes \" \n). Without slashing,
		// the escapes are stripped, the JSON breaks, and approve_revision() fails
		// at json_decode() with "Revision metadata is corrupted" — making EVERY
		// revision un-approvable even when its rendered content is fine.
		update_post_meta( $revision_id, '_aa_revision_meta', wp_slash( wp_json_encode( $revision_meta ) ) );
		update_post_meta( $revision_id, '_aa_revision_created_by', 'arcadia_agent' );

		if ( ! empty( $body['revision_notes'] ) ) {
			update_post_meta(
				$revision_id,
				'_aa_revision_notes',
				sanitize_textarea_field( $body['revision_notes'] )
			);
		}

		// Generate preview token (reuses existing preview system).
		$preview  = Arcadia_Preview::get_instance();
		$token    = $preview->generate_token( $revision_id );
		$preview_url = add_query_arg(
			array(
				'p'          => $revision_id,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		// Keep the revision history bounded for this post.
		$this->prune_old_revisions( $post_id );

		return array(
			'revision_id'      => $revision_id,
			'revision_version' => $version,
			'preview_url'      => $preview_url,
		);
	}

	/**
	 * Delete the oldest revisions of a post beyond the retention cap.
	 *
	 * Only decided revisions (approved/rejected/superseded) are eligible — a
	 * pending revision is the live proposal and is always kept. We keep the
	 * newest RETENTION_PER_POST decided revisions and force-delete the rest.
	 *
	 * @param int $post_id The parent post ID.
	 * @return void
	 */
	private function prune_old_revisions( $post_id ) {
		$old = get_posts(
			array(
				'post_type'        => 'aa_revision',
				'post_parent'      => $post_id,
				'post_status'      => array( 'approved', 'rejected', 'superseded' ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'offset'           => self::RETENTION_PER_POST,
				'numberposts'      => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $old as $rev_id ) {
			wp_delete_post( $rev_id, true );
		}
	}

	/**
	 * Approve a pending revision: apply changes to the live post.
	 *
	 * Uses wp_update_post() which creates a native WP revision for rollback.
	 * Replays metadata (SEO, ACF fields, featured image, taxonomies).
	 *
	 * @param int    $revision_id  The aa_revision post ID.
	 * @param string $user_login   The WP user login who approved.
	 * @return array{approved:bool,warnings:array<int,string>}|WP_Error Result with any
	 *               non-fatal warnings on success, WP_Error if a critical step failed.
	 */
	public function approve_revision( $revision_id, $user_login ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $revision->post_status ) {
			return new WP_Error( 'revision_not_pending', 'Revision is not pending.', array( 'status' => 400 ) );
		}

		$post_id = $revision->post_parent;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'original_post_not_found', 'Original post not found.', array( 'status' => 404 ) );
		}

		// Load the stored payload.
		$revision_meta_json = get_post_meta( $revision_id, '_aa_revision_meta', true );
		$revision_meta      = json_decode( $revision_meta_json, true );
		if ( ! is_array( $revision_meta ) ) {
			return new WP_Error( 'revision_meta_corrupt', 'Revision metadata is corrupted.', array( 'status' => 500 ) );
		}

		$body = $revision_meta['body'] ?? array();
		$meta = $revision_meta['meta'] ?? array();

		// Build post_data for wp_update_post.
		//
		// Only the four fields the revision CPT actually carries are set here.
		// Everything downstream of the write — SEO meta, taxonomies, featured
		// image, ACF fields, field-schema mappings, render test — is delegated
		// to Arcadia_Post_Builder::finalize_post(), the SAME call the direct PUT
		// path makes (trait-api-posts.php). See approve_revision()'s docblock for
		// why hand-rolling that replay here was a data-loss bug (Phase 42.1).
		$post_data = array( 'ID' => $post_id );

		// Title — body.title (H1) only. meta.title is the SEO meta-title and
		// lands in _yoast_wpseo_title via finalize_post(), never in post_title
		// (Phase 42.3: one incoming field writes exactly one destination).
		if ( ! empty( $body['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $body['title'] );
		}

		// Content (stored in the revision CPT's post_content).
		if ( ! empty( $revision->post_content ) ) {
			$post_data['post_content'] = $revision->post_content;
		}

		// Excerpt — body.excerpt only, same rule as the title.
		if ( isset( $body['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $body['excerpt'] );
		}

		// Slug.
		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		// Apply update to live post (creates native WP revision for rollback).
		$post_data = wp_slash( $post_data );
		$result    = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Replay every post-write side effect through the shared pipeline.
		//
		// $rendered_content is the revision's own post_content: it is what a
		// `wysiwyg: null` field copies (process_acf_fields), and on this path the
		// blocks were already rendered at create_revision() time.
		//
		// skip_markdown is read back from the stored payload — the flag belonged
		// to the original request, and there is no request here. Without it, a
		// round-trip PUT (already-HTML content) would be markdown-parsed a second
		// time at approval, which is exactly the asymmetry this phase closes.
		$builder  = new Arcadia_Post_Builder( Arcadia_Blocks::get_instance() );
		$finalize = $builder->finalize_post(
			(int) $post_id,
			$body,
			$meta,
			$post->post_type,
			$revision->post_content,
			Arcadia_API::get_instance(),
			array(
				'is_create'     => false,
				'skip_markdown' => ! empty( $revision_meta['skip_markdown'] ),
			)
		);
		if ( is_wp_error( $finalize ) ) {
			return $finalize;
		}

		// Non-fatal replay failures (featured image, term creation) are surfaced
		// to the approver instead of being silently swallowed.
		$warnings = $finalize['warnings'];

		// Mark revision as approved.
		// arcadia:slash-safe — only post ID + status, no slashable content.
		wp_update_post(
			array(
				'ID'          => $revision_id,
				'post_status' => 'approved',
			)
		);
		update_post_meta( $revision_id, '_aa_revision_decided_by', sanitize_text_field( $user_login ) );
		update_post_meta( $revision_id, '_aa_revision_decided_at', gmdate( 'c' ) );

		/**
		 * Fires when a revision decision is made.
		 *
		 * @param int    $revision_id The revision ID.
		 * @param string $decision    The decision: 'approved' or 'rejected'.
		 */
		do_action( 'aa_revision_decided', $revision_id, 'approved' );

		return array(
			'approved' => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Reject a pending revision.
	 *
	 * @param int    $revision_id    The aa_revision post ID.
	 * @param string $user_login     The WP user login who rejected.
	 * @param string $decision_notes Optional notes explaining the rejection.
	 * @return true|WP_Error True on success.
	 */
	public function reject_revision( $revision_id, $user_login, $decision_notes = '' ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $revision->post_status ) {
			return new WP_Error( 'revision_not_pending', 'Revision is not pending.', array( 'status' => 400 ) );
		}

		// arcadia:slash-safe — only post ID + status, no slashable content.
		wp_update_post(
			array(
				'ID'          => $revision_id,
				'post_status' => 'rejected',
			)
		);
		update_post_meta( $revision_id, '_aa_revision_decided_by', sanitize_text_field( $user_login ) );
		update_post_meta( $revision_id, '_aa_revision_decided_at', gmdate( 'c' ) );

		if ( ! empty( $decision_notes ) ) {
			update_post_meta(
				$revision_id,
				'_aa_revision_decision_notes',
				sanitize_textarea_field( $decision_notes )
			);
		}

		do_action( 'aa_revision_decided', $revision_id, 'rejected' );

		return true;
	}

	/**
	 * Get the pending revision for a post (at most one).
	 *
	 * @param int $post_id The original post ID.
	 * @return WP_Post|null The pending revision or null.
	 */
	public function get_pending_revision( $post_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'aa_revision',
				'post_parent'    => $post_id,
				'post_status'    => 'pending',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Get revisions for a post with pagination and optional status filter.
	 *
	 * @param int   $post_id The original post ID.
	 * @param array $args    Optional. Query args: status, page, per_page.
	 * @return array Array with 'revisions', 'total', 'page', 'per_page'.
	 */
	public function get_revisions( $post_id, $args = array() ) {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );

		$query_args = array(
			'post_type'      => 'aa_revision',
			'post_parent'    => $post_id,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Filter by status if provided.
		$allowed_statuses = array( 'pending', 'approved', 'rejected', 'superseded' );
		if ( ! empty( $args['status'] ) && in_array( $args['status'], $allowed_statuses, true ) ) {
			$query_args['post_status'] = $args['status'];
		} else {
			$query_args['post_status'] = $allowed_statuses;
		}

		$query = new WP_Query( $query_args );

		$revisions = array();
		foreach ( $query->posts as $rev ) {
			$revisions[] = $this->format_revision( $rev );
		}

		return array(
			'revisions' => $revisions,
			'total'     => (int) $query->found_posts,
			'page'      => $page,
			'per_page'  => $per_page,
		);
	}

	/**
	 * Get a single revision by ID.
	 *
	 * @param int $revision_id The aa_revision post ID.
	 * @return array|WP_Error Formatted revision or error.
	 */
	public function get_revision( $revision_id ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'aa_revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}

		// Detail view: carry the proposal, same as the REST detail handler.
		return $this->format_revision( $revision, true );
	}

	/**
	 * Format a revision post for API response.
	 *
	 * @param WP_Post $revision        The revision post object.
	 * @param bool    $include_changes When true, attach the before/after projection of
	 *                                 what this revision proposes (Phase 43.1).
	 *                                 Defaults to FALSE on purpose: this formatter also
	 *                                 feeds the listing (get_revisions()) and the admin
	 *                                 history box, and listing 20 revisions must not mean
	 *                                 building and shipping 20 full diffs. The detail
	 *                                 endpoint is the one caller that opts in.
	 * @return array Formatted revision data.
	 */
	public function format_revision( $revision, $include_changes = false ) {
		$version = (int) get_post_meta( $revision->ID, '_aa_revision_version', true );

		// Get or create preview URL.
		$preview      = Arcadia_Preview::get_instance();
		$token        = $preview->get_or_create_token( $revision->ID );
		$preview_url  = add_query_arg(
			array(
				'p'          => $revision->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		$data = array(
			'revision_id'      => $revision->ID,
			'revision_version' => $version,
			'status'           => $revision->post_status,
			'created_at'       => ! empty( $revision->post_date ) ? gmdate( 'c', strtotime( $revision->post_date ) ) : null,
			'created_by'       => get_post_meta( $revision->ID, '_aa_revision_created_by', true ) ?: null,
			'decided_at'       => get_post_meta( $revision->ID, '_aa_revision_decided_at', true ) ?: null,
			'decided_by'       => get_post_meta( $revision->ID, '_aa_revision_decided_by', true ) ?: null,
			'decision_notes'   => get_post_meta( $revision->ID, '_aa_revision_decision_notes', true ) ?: null,
			'revision_notes'   => get_post_meta( $revision->ID, '_aa_revision_notes', true ) ?: null,
			'preview_url'      => $preview_url,
		);

		if ( $include_changes ) {
			$diff                    = new Arcadia_Revision_Diff();
			$projection              = $diff->build( $revision );
			// to_api_changes(), not the raw list: it bounds value size and refuses
			// to serialize WP objects an ACF relationship field may hand back.
			$data['changes']         = $diff->to_api_changes( $projection['changes'] );
			$data['content_changed'] = $projection['content_changed'];
			$data['skip_markdown']   = $projection['skip_markdown'];
		}

		return $data;
	}

	/**
	 * Get the next version number for revisions of a post.
	 *
	 * @param int $post_id The original post ID.
	 * @return int The next version number.
	 */
	private function get_next_version( $post_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'aa_revision',
				'post_parent'    => $post_id,
				'post_status'    => array( 'pending', 'approved', 'rejected', 'superseded' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_aa_revision_version',
			)
		);

		if ( ! empty( $query->posts ) ) {
			$last_version = (int) get_post_meta( $query->posts[0]->ID, '_aa_revision_version', true );
			return $last_version + 1;
		}

		return 1;
	}
}
