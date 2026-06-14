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
		'arcadia_agents_scopes',
		'arcadia_agents_block_adapter',
		'aa_force_draft',
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

	// 4. arcadia_source taxonomy terms (the hidden source tag). The taxonomy
	//    is not registered during uninstall, so query terms directly.
	$term_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = %s",
			'arcadia_source'
		)
	);
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( (int) $term_id, 'arcadia_source' );
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
