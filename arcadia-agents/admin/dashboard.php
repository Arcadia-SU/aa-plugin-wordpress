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
	// counter that feeds the "Arcadia (n)" list view.
	$managed_posts = Arcadia_Content_Counter::count( 'post' );
	$managed_pages = Arcadia_Content_Counter::count( 'page' );

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

				<!-- Arcadia contents, linked to the filtered lists (FS-2). -->
				<div class="aa-card aa-stat">
					<div class="aa-stat__value"><?php echo (int) ( $managed_posts + $managed_pages ); ?></div>
					<div class="aa-stat__label"><?php esc_html_e( 'Arcadia contents', 'arcadia-agents' ); ?></div>
					<div class="aa-stat__links">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=post&aa_source=arcadia' ) ); ?>">
							<?php
							/* translators: %s: number of posts */
							echo esc_html( sprintf( __( 'Articles (%s)', 'arcadia-agents' ), number_format_i18n( $managed_posts ) ) );
							?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page&aa_source=arcadia' ) ); ?>">
							<?php
							/* translators: %s: number of pages */
							echo esc_html( sprintf( __( 'Pages (%s)', 'arcadia-agents' ), number_format_i18n( $managed_pages ) ) );
							?>
						</a>
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
					<table class="aa-table" id="aa-pending-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Article', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Notes', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_revisions as $rev ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $rev['parent_title'] ); ?></strong></td>
									<td>v<?php echo (int) $rev['version']; ?></td>
									<td class="aa-muted">
										<?php echo $rev['notes'] ? esc_html( wp_trim_words( $rev['notes'], 10 ) ) : '<em>' . esc_html__( 'No notes', 'arcadia-agents' ) . '</em>'; ?>
									</td>
									<td class="aa-muted"><?php echo esc_html( $rev['date'] ); ?></td>
									<td>
										<div class="aa-row-actions" data-revision-id="<?php echo (int) $rev['revision_id']; ?>">
											<a href="<?php echo esc_url( $rev['preview_url'] ); ?>" target="_blank" class="aa-btn aa-btn--ghost aa-btn--small"><?php esc_html_e( 'Preview', 'arcadia-agents' ); ?></a>
											<button type="button" class="aa-btn aa-btn--primary aa-btn--small aa-dash-approve"><?php esc_html_e( 'Approve', 'arcadia-agents' ); ?></button>
											<button type="button" class="aa-btn aa-btn--ghost aa-btn--small aa-dash-reject"><?php esc_html_e( 'Reject', 'arcadia-agents' ); ?></button>
											<a href="<?php echo esc_url( get_edit_post_link( $rev['parent_id'] ) ); ?>" class="aa-btn aa-btn--ghost aa-btn--small"><?php esc_html_e( 'Edit', 'arcadia-agents' ); ?></a>
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
