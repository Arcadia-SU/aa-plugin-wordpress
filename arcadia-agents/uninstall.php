<?php
/**
 * Uninstall routine for Arcadia Agents.
 *
 * Runs ONLY when the plugin is deleted (not on deactivation). Removes every
 * artifact the plugin persists so deletion leaves no orphaned data:
 *   - plugin options
 *   - its custom post types (with their postmeta)
 *   - preview meta left on regular posts
 *   - the arcadia_source taxonomy terms
 *   - cached transients (fixed names + the arcadia_blocks_usage_* family)
 *   - the daily preview-cleanup cron event
 *
 * Contract: deactivation keeps data (register_deactivation_hook only unschedules
 * the cron); deletion wipes it. Multisite-aware: cleans every site in a network.
 *
 * Note: third-party SEO meta the plugin writes onto posts (Yoast/RankMath/AIOSEO)
 * is intentionally NOT removed — it belongs to those plugins and the post, not us.
 *
 * @package ArcadiaAgents
 */

// Bail unless this is a genuine WordPress uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all Arcadia Agents data for the current site.
 *
 * @return void
 */
function arcadia_agents_uninstall_site() {
	global $wpdb;

	// 1. Plugin options (every arcadia_*/aa_* option the plugin writes).
	$options = array(
		'arcadia_agents_public_key',
		'arcadia_agents_connected',
		'arcadia_agents_connected_at',
		'arcadia_agents_last_activity',
		'arcadia_agents_connection_key',
		'arcadia_agents_site_id',
		'arcadia_agents_issuer',
		'arcadia_agents_scopes',
		'arcadia_agents_block_adapter',
		'aa_force_draft',
		// Legacy: the standalone Pending Revisions toggle (commit 72c8a47 folded
		// it into Force Draft and removed the reader/writer). Installs upgraded
		// from before that change still carry the row, so clean it up here.
		'aa_pending_revisions',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 2. Custom post types — force-delete each post, which also clears its
	//    postmeta (_aa_revision_*, _redirect_*, and any _aa_preview_* on them).
	$cpt_posts = get_posts(
		array(
			'post_type'        => array( 'aa_revision', 'arcadia_redirect' ),
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	foreach ( $cpt_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	// 3. Preview meta also lands on regular posts/pages (anything previewed),
	//    so remove those two keys everywhere, not just on our CPTs.
	delete_post_meta_by_key( '_aa_preview_token' );
	delete_post_meta_by_key( '_aa_preview_expires' );

	// 4. arcadia_source taxonomy terms (the hidden source tag). The taxonomy is
	//    NOT registered during uninstall — only this file loads, the init hook
	//    that registers it never fires. That makes wp_delete_term() useless here:
	//    it routes through term_exists() -> get_terms(), which bails with
	//    WP_Error('invalid_taxonomy') for an unregistered taxonomy, so every call
	//    is a silent no-op and the rows survive. Delete them directly instead,
	//    mirroring the raw-SQL transient cleanup in step 5.
	$tt_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			'arcadia_source'
		)
	);
	if ( ! empty( $tt_rows ) ) {
		$term_ids = array_map( static fn( $row ) => (int) $row->term_id, $tt_rows );
		$tt_ids   = array_map( static fn( $row ) => (int) $row->term_taxonomy_id, $tt_rows );

		// Object relationships first (one row per tagged post), then the taxonomy
		// rows, then the terms and any term meta.
		$tt_placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($tt_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$tt_ids
			)
		);
		$wpdb->delete( $wpdb->term_taxonomy, array( 'taxonomy' => 'arcadia_source' ), array( '%s' ) );

		$term_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->terms} WHERE term_id IN ($term_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$term_ids
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($term_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$term_ids
			)
		);
	}

	// 5. Transients: fixed names + the dynamic blocks-usage cache family.
	delete_transient( 'aa_pending_revision_count' );
	delete_transient( 'arcadia_redirects_map' );
	$like_value   = $wpdb->esc_like( '_transient_arcadia_blocks_usage_' ) . '%';
	$like_timeout = $wpdb->esc_like( '_transient_timeout_arcadia_blocks_usage_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like_value,
			$like_timeout
		)
	);

	// 6. Cron: the daily preview-cleanup event.
	wp_clear_scheduled_hook( 'arcadia_preview_cleanup' );
}

// Run per-site so a network install leaves nothing behind.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		arcadia_agents_uninstall_site();
		restore_current_blog();
	}
} else {
	arcadia_agents_uninstall_site();
}
