<?php
/**
 * Admin dashboard page — Arcadia Agents control center.
 *
 * Arcadia design system (skin scoped .arcadia-admin). Shows connection
 * status + publishing guard, agent-created content counts (with direct
 * links to the filtered lists), the review queue and recent decisions.
 * Approve/reject AJAX is handled by admin/js/dashboard.js — same endpoints
 * and nonce as before, but without location.reload().
 *
 * @package ArcadiaAgents
 * @since   0.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the dashboard page.
 */
function arcadia_agents_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_connected = get_option( 'arcadia_agents_connected', false );
	$connected_at = get_option( 'arcadia_agents_connected_at', '' );

	// Agent-created content (arcadia_source term), live count — the same
	// counter that feeds the "Arcadia (n)" list view. Enumerated over every
	// supported post type, never a hardcoded post/page pair: on CPT-built
	// sites (iSelection) the agent's content lives in custom types.
	$managed_counts = array();
	$managed_total  = 0;
	foreach ( Arcadia_Admin_List_UI::supported_post_types() as $managed_type ) {
		$type_count     = Arcadia_Content_Counter::count( $managed_type );
		$managed_total += $type_count;
		if ( $type_count > 0 ) {
			$managed_counts[ $managed_type ] = $type_count;
		}
	}

	// Revision stats.
	$pending_revisions = arcadia_dashboard_get_revisions( 'pending', 50 );
	$pending_count     = count( $pending_revisions );
	$approved_count    = arcadia_dashboard_count_revisions( 'approved' );
	$rejected_count    = arcadia_dashboard_count_revisions( 'rejected' );
	$recent_decisions  = arcadia_dashboard_get_recent_decisions( 10 );

	$review_queue_url = admin_url( 'admin.php?page=arcadia-agents' ) . '#aa-pending';

	?>
	<div class="wrap">
		<div class="aa-page-header">
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="" class="aa-page-header__logo" />
			<h1 class="aa-page-header__title"><?php esc_html_e( 'Arcadia Agents', 'arcadia-agents' ); ?></h1>
		</div>
		<hr class="wp-header-end" />

		<div class="arcadia-admin">

			<!-- Top cards row -->
			<div class="aa-cards-row">

				<!-- Connection + guard -->
				<div class="aa-card aa-stat">
					<p class="aa-status <?php echo $is_connected ? 'aa-status--on' : 'aa-status--off'; ?>">
						<span class="aa-status__dot" aria-hidden="true"></span>
						<?php $is_connected ? esc_html_e( 'Connected', 'arcadia-agents' ) : esc_html_e( 'Not connected', 'arcadia-agents' ); ?>
					</p>
					<?php if ( $is_connected && $connected_at ) : ?>
						<div class="aa-stat__label">
							<?php
							/* translators: %s: connection date */
							echo esc_html( sprintf( __( 'Since %s', 'arcadia-agents' ), wp_date( 'j M Y', strtotime( $connected_at ) ) ) );
							?>
						</div>
					<?php elseif ( ! $is_connected ) : ?>
						<div class="aa-stat__label">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ); ?>"><?php esc_html_e( 'Configure connection', 'arcadia-agents' ); ?></a>
						</div>
					<?php endif; ?>
					<div class="aa-stat__links">
						<?php echo Arcadia_Guard_Status::chip_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
					</div>
				</div>

				<!-- Arcadia contents, linked to the filtered lists (FS-2).
				     One link per post type actually holding agent content;
				     labels come from the post type objects (already localized). -->
				<div class="aa-card aa-stat">
					<div class="aa-stat__value"><?php echo (int) $managed_total; ?></div>
					<div class="aa-stat__label"><?php esc_html_e( 'Arcadia contents', 'arcadia-agents' ); ?></div>
					<div class="aa-stat__links">
						<?php if ( empty( $managed_counts ) ) : ?>
							<span class="aa-muted"><?php esc_html_e( 'No agent-created content yet.', 'arcadia-agents' ); ?></span>
						<?php else : ?>
							<?php foreach ( $managed_counts as $managed_type => $type_count ) : ?>
								<?php
								$type_object = get_post_type_object( $managed_type );
								$type_label  = $type_object && isset( $type_object->labels->name ) ? $type_object->labels->name : $managed_type;
								$type_url    = add_query_arg(
									array(
										'post_type' => $managed_type,
										'aa_source' => 'arcadia',
									),
									admin_url( 'edit.php' )
								);
								?>
								<a href="<?php echo esc_url( $type_url ); ?>">
									<?php
									/* translators: 1: post type name (e.g. Posts), 2: number of contents */
									echo esc_html( sprintf( __( '%1$s (%2$s)', 'arcadia-agents' ), $type_label, number_format_i18n( $type_count ) ) );
									?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- Review queue, one click away. -->
				<a class="aa-card aa-stat" href="<?php echo esc_url( $review_queue_url ); ?>">
					<div class="aa-stat__value" id="aa-pending-value"><?php echo (int) $pending_count; ?></div>
					<div class="aa-stat__label"><?php esc_html_e( 'Agent proposals awaiting review', 'arcadia-agents' ); ?></div>
				</a>

			</div>

			<!-- Pending proposals table -->
			<section class="aa-card" id="aa-pending">
				<h2 class="aa-card__title">
					<?php esc_html_e( 'Agent proposals awaiting review', 'arcadia-agents' ); ?>
				</h2>

				<div id="aa-pending-empty" class="aa-muted" <?php echo empty( $pending_revisions ) ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'No pending proposals. All clear!', 'arcadia-agents' ); ?>
				</div>

				<?php if ( ! empty( $pending_revisions ) ) : ?>
					<!-- Bulk action bar: only exists once something is selected
					     (progressive disclosure). Rendered empty, filled by JS. -->
					<div class="aa-bulkbar" id="aa-bulkbar" hidden>
						<span class="aa-bulkbar__count" id="aa-bulkbar-count" aria-live="polite"></span>
						<div class="aa-bulkbar__actions" id="aa-bulkbar-actions"></div>
					</div>

					<table class="aa-table aa-table--queue" id="aa-pending-table">
						<thead>
							<tr>
								<th scope="col" class="aa-cell-check">
									<input type="checkbox" id="aa-select-all" class="aa-check" aria-label="<?php esc_attr_e( 'Select all proposals', 'arcadia-agents' ); ?>" />
								</th>
								<th scope="col"><?php esc_html_e( 'Article', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Notes', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_revisions as $rev ) : ?>
								<?php
								$rev_title = $rev['parent_title'];
								// The review view (diff + approve/reject) is the parent
								// post editor, where the plugin injects its metabox /
								// sidebar. It is the row's natural destination, so the
								// title carries it instead of a fourth button.
								$review_url = get_edit_post_link( $rev['parent_id'] );
								?>
								<tr>
									<td class="aa-cell-check">
										<input
											type="checkbox"
											class="aa-check aa-row-check"
											value="<?php echo (int) $rev['revision_id']; ?>"
											aria-label="<?php
												/* translators: %s: article title */
												echo esc_attr( sprintf( __( 'Select “%s”', 'arcadia-agents' ), $rev_title ) );
											?>" />
									</td>
									<td>
										<?php if ( $review_url ) : ?>
											<a class="aa-row-title" href="<?php echo esc_url( $review_url ); ?>"><?php echo esc_html( $rev_title ); ?></a>
										<?php else : ?>
											<strong><?php echo esc_html( $rev_title ); ?></strong>
										<?php endif; ?>
									</td>
									<td>v<?php echo (int) $rev['version']; ?></td>
									<td class="aa-muted">
										<?php echo $rev['notes'] ? esc_html( wp_trim_words( $rev['notes'], 10 ) ) : '<em>' . esc_html__( 'No notes', 'arcadia-agents' ) . '</em>'; ?>
									</td>
									<td class="aa-muted"><?php echo esc_html( $rev['date'] ); ?></td>
									<td>
										<div class="aa-row-actions" data-revision-id="<?php echo (int) $rev['revision_id']; ?>">
											<a
												href="<?php echo esc_url( $rev['preview_url'] ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												class="aa-btn aa-btn--quiet aa-btn--small aa-row-actions__quiet"
											><?php echo arcadia_dashboard_icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?><span><?php esc_html_e( 'Preview', 'arcadia-agents' ); ?></span></a>
											<button
												type="button"
												class="aa-btn aa-btn--primary aa-btn--icon aa-dash-approve"
												title="<?php esc_attr_e( 'Approve', 'arcadia-agents' ); ?>"
												aria-label="<?php
													/* translators: %s: article title */
													echo esc_attr( sprintf( __( 'Approve “%s”', 'arcadia-agents' ), $rev_title ) );
												?>"
											><?php echo arcadia_dashboard_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></button>
											<button
												type="button"
												class="aa-btn aa-btn--ghost aa-btn--icon aa-btn--danger-hover aa-dash-reject"
												title="<?php esc_attr_e( 'Reject', 'arcadia-agents' ); ?>"
												aria-label="<?php
													/* translators: %s: article title */
													echo esc_attr( sprintf( __( 'Reject “%s”', 'arcadia-agents' ), $rev_title ) );
												?>"
											><?php echo arcadia_dashboard_icon( 'cross' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<!-- Recent decisions -->
			<section class="aa-card" id="aa-decisions">
				<h2 class="aa-card__title"><?php esc_html_e( 'Recent decisions', 'arcadia-agents' ); ?></h2>

				<?php if ( empty( $recent_decisions ) ) : ?>
					<p class="aa-muted"><?php esc_html_e( 'No decisions yet.', 'arcadia-agents' ); ?></p>
				<?php else : ?>
					<table class="aa-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Article', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Decision', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_decisions as $dec ) : ?>
								<tr>
									<td><?php echo esc_html( $dec['parent_title'] ); ?></td>
									<td>v<?php echo (int) $dec['version']; ?></td>
									<td>
										<?php if ( 'approved' === $dec['status'] ) : ?>
											<span class="aa-decision aa-decision--approved"><?php esc_html_e( 'Approved', 'arcadia-agents' ); ?></span>
										<?php else : ?>
											<span class="aa-decision aa-decision--rejected"><?php esc_html_e( 'Rejected', 'arcadia-agents' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="aa-muted"><?php echo esc_html( $dec['decided_by'] ? $dec['decided_by'] : '—' ); ?></td>
									<td class="aa-muted"><?php echo esc_html( $dec['date'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="aa-table-footer aa-muted">
					<?php
					/* translators: 1: approved count, 2: rejected count */
					echo esc_html( sprintf( __( 'Approved: %1$s · Rejected: %2$s', 'arcadia-agents' ), number_format_i18n( $approved_count ), number_format_i18n( $rejected_count ) ) );
					?>
				</p>
			</section>

			<p class="aa-muted aa-small aa-dashboard-footer">
				<?php
				/* translators: %s: plugin version */
				echo esc_html( sprintf( __( 'Arcadia Agents v%s', 'arcadia-agents' ), ARCADIA_AGENTS_VERSION ) );
				?>
				&middot;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'arcadia-agents' ); ?></a>
			</p>

		</div>
	</div>
	<?php
}

/**
 * Inline SVG icon for the queue actions.
 *
 * Icons are shipped inline (never a font, never a remote file) so that a row
 * action renders identically whatever the admin theme does with dashicons, and
 * inherits its colour from the button through currentColor. The markup is
 * static and self-contained: callers echo it without escaping.
 *
 * @param string $name Icon key: eye, check, cross.
 * @return string SVG markup, or an empty string for an unknown key.
 */
function arcadia_dashboard_icon( $name ) {
	$open  = '<svg class="aa-icon" viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
	$paths = array(
		'eye'   => '<path d="M1.7 10S4.9 4.6 10 4.6 18.3 10 18.3 10 15.1 15.4 10 15.4 1.7 10 1.7 10Z"/><circle cx="10" cy="10" r="2.4"/>',
		'check' => '<path d="m4.6 10.4 3.5 3.5 7.3-7.8"/>',
		'cross' => '<path d="m5.5 5.5 9 9m0-9-9 9"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return $open . $paths[ $name ] . '</svg>';
}

/**
 * Get pending revisions with parent post info.
 *
 * @param string $status   Post status to query.
 * @param int    $limit    Max results.
 * @return array Array of revision data.
 */
function arcadia_dashboard_get_revisions( $status, $limit ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'aa_revision',
			'post_status'    => $status,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	$results = array();
	foreach ( $query->posts as $rev ) {
		$parent       = get_post( $rev->post_parent );
		$parent_title = $parent ? $parent->post_title : __( '(deleted)', 'arcadia-agents' );

		// Build preview URL.
		$preview     = Arcadia_Preview::get_instance();
		$token       = $preview->get_or_create_token( $rev->ID );
		$preview_url = add_query_arg(
			array(
				'p'          => $rev->ID,
				'aa_preview' => $token,
			),
			home_url( '/' )
		);

		$results[] = array(
			'revision_id'  => $rev->ID,
			'parent_id'    => $rev->post_parent,
			'parent_title' => $parent_title,
			'version'      => (int) get_post_meta( $rev->ID, '_aa_revision_version', true ),
			'notes'        => get_post_meta( $rev->ID, '_aa_revision_notes', true ),
			'date'         => wp_date( 'j M Y', strtotime( $rev->post_date ) ),
			'status'       => $rev->post_status,
			'preview_url'  => $preview_url,
		);
	}

	return $results;
}

/**
 * Count revisions by status.
 *
 * @param string $status Post status.
 * @return int Count.
 */
function arcadia_dashboard_count_revisions( $status ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'aa_revision',
			'post_status'    => $status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	return $query->post_count;
}

/**
 * Get recent approved/rejected decisions.
 *
 * @param int $limit Max results.
 * @return array Array of decision data.
 */
function arcadia_dashboard_get_recent_decisions( $limit ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'aa_revision',
			'post_status'    => array( 'approved', 'rejected' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	);

	$results = array();
	foreach ( $query->posts as $rev ) {
		$parent       = get_post( $rev->post_parent );
		$parent_title = $parent ? $parent->post_title : __( '(deleted)', 'arcadia-agents' );
		$decided_at   = get_post_meta( $rev->ID, '_aa_revision_decided_at', true );

		$results[] = array(
			'revision_id'  => $rev->ID,
			'parent_id'    => $rev->post_parent,
			'parent_title' => $parent_title,
			'version'      => (int) get_post_meta( $rev->ID, '_aa_revision_version', true ),
			'status'       => $rev->post_status,
			'decided_by'   => get_post_meta( $rev->ID, '_aa_revision_decided_by', true ),
			'date'         => $decided_at ? wp_date( 'j M Y', strtotime( $decided_at ) ) : wp_date( 'j M Y', strtotime( $rev->post_modified ) ),
		);
	}

	return $results;
}
