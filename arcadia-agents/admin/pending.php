<?php
/**
 * Admin page — changes waiting for approval (Phase 55 / CH03).
 *
 * With Force Draft on, Arcadia's deletions, category and tag changes, media
 * edits, redirects and featured-image changes on live articles wait here
 * (docs/contracts/write-table.md). Article text edits keep their own review
 * view in the editor (preview + diff); this page lists everything else.
 *
 * Same Arcadia skin, table and behaviour as the dashboard's queue: the rows
 * are driven by admin/js/dashboard.js with this page's AJAX actions.
 *
 * @package ArcadiaAgents
 * @since   0.13.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the pending-actions page.
 */
function arcadia_agents_pending_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$queue     = Arcadia_Pending_Actions::get_instance();
	$pending   = $queue->screen_rows( 'pending', 100 );
	$decided   = array_merge(
		$queue->screen_rows( 'approved', 10 ),
		$queue->screen_rows( 'rejected', 10 ),
		$queue->screen_rows( 'stale', 10 ),
		$queue->screen_rows( 'withdrawn', 10 )
	);
	usort( $decided, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
	$decided   = array_slice( $decided, 0, 10 );
	$focus_id  = isset( $_GET['action_id'] ) ? (int) $_GET['action_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only highlight.
	$status_labels = array(
		'approved'  => __( 'Approved', 'arcadia-agents' ),
		'rejected'  => __( 'Rejected', 'arcadia-agents' ),
		'stale'     => __( 'Out of date', 'arcadia-agents' ),
		'withdrawn' => __( 'Withdrawn by Arcadia', 'arcadia-agents' ),
	);

	?>
	<div class="wrap">
		<div class="aa-page-header">
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="" class="aa-page-header__logo" />
			<h1 class="aa-page-header__title"><?php esc_html_e( 'Changes awaiting approval', 'arcadia-agents' ); ?></h1>
		</div>
		<hr class="wp-header-end" />

		<div class="arcadia-admin">

			<section class="aa-card" id="aa-pending">
				<h2 class="aa-card__title"><?php esc_html_e( 'Changes awaiting approval', 'arcadia-agents' ); ?></h2>
				<p class="aa-muted">
					<?php esc_html_e( 'The setting “The agent saves as draft only” is on: Arcadia’s deletions, category and tag changes, media changes, redirects and featured-image changes on live articles wait here. Nothing changes on your site until you approve. Edits to article text are reviewed in the article editor.', 'arcadia-agents' ); ?>
				</p>

				<div id="aa-pending-empty" class="aa-muted" <?php echo empty( $pending ) ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Nothing is waiting for your approval.', 'arcadia-agents' ); ?>
				</div>

				<?php if ( ! empty( $pending ) ) : ?>
					<div class="aa-bulkbar" id="aa-bulkbar" hidden>
						<span class="aa-bulkbar__count" id="aa-bulkbar-count" aria-live="polite"></span>
						<div class="aa-bulkbar__actions" id="aa-bulkbar-actions"></div>
					</div>

					<table class="aa-table aa-table--queue" id="aa-pending-table">
						<thead>
							<tr>
								<th scope="col" class="aa-cell-check">
									<input type="checkbox" id="aa-select-all" class="aa-check" aria-label="<?php esc_attr_e( 'Select all changes', 'arcadia-agents' ); ?>" />
								</th>
								<th scope="col"><?php esc_html_e( 'Change', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending as $row ) : ?>
								<tr id="aa-action-<?php echo (int) $row['id']; ?>" <?php echo $focus_id === $row['id'] ? 'class="aa-row--focus"' : ''; ?>>
									<td class="aa-cell-check">
										<input
											type="checkbox"
											class="aa-check aa-row-check"
											value="<?php echo (int) $row['id']; ?>"
											aria-label="<?php
												/* translators: %s: description of the change */
												echo esc_attr( sprintf( __( 'Select “%s”', 'arcadia-agents' ), $row['label'] ) );
											?>" />
									</td>
									<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
									<td class="aa-muted"><?php echo esc_html( wp_date( 'j M Y', strtotime( $row['date'] ) ) ); ?></td>
									<td>
										<div class="aa-row-actions" data-revision-id="<?php echo (int) $row['id']; ?>">
											<button
												type="button"
												class="aa-btn aa-btn--primary aa-btn--icon aa-dash-approve"
												title="<?php esc_attr_e( 'Approve', 'arcadia-agents' ); ?>"
												aria-label="<?php
													/* translators: %s: description of the change */
													echo esc_attr( sprintf( __( 'Approve “%s”', 'arcadia-agents' ), $row['label'] ) );
												?>"
											><?php echo arcadia_dashboard_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></button>
											<button
												type="button"
												class="aa-btn aa-btn--ghost aa-btn--icon aa-btn--danger-hover aa-dash-reject"
												title="<?php esc_attr_e( 'Reject', 'arcadia-agents' ); ?>"
												aria-label="<?php
													/* translators: %s: description of the change */
													echo esc_attr( sprintf( __( 'Reject “%s”', 'arcadia-agents' ), $row['label'] ) );
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

			<section class="aa-card" id="aa-decisions">
				<h2 class="aa-card__title"><?php esc_html_e( 'Recent decisions', 'arcadia-agents' ); ?></h2>
				<?php if ( empty( $decided ) ) : ?>
					<p class="aa-muted"><?php esc_html_e( 'No decisions yet.', 'arcadia-agents' ); ?></p>
				<?php else : ?>
					<table class="aa-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Change', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Decision', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $decided as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['label'] ); ?></td>
									<td>
										<span class="aa-decision <?php echo 'approved' === $row['status'] ? 'aa-decision--approved' : 'aa-decision--rejected'; ?>">
											<?php echo esc_html( $status_labels[ $row['status'] ] ?? $row['status'] ); ?>
										</span>
									</td>
									<td class="aa-muted"><?php echo esc_html( wp_date( 'j M Y', strtotime( $row['date'] ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<p class="aa-muted aa-small aa-dashboard-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents' ) ); ?>"><?php esc_html_e( 'Dashboard', 'arcadia-agents' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'arcadia-agents' ); ?></a>
			</p>
		</div>
	</div>
	<?php
}

/**
 * AJAX: approve a pending action. Same nonce as the revision queue.
 */
function arcadia_agents_ajax_approve_pending_action() {
	check_ajax_referer( 'aa_revision_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'You are not allowed to approve this change.', 'arcadia-agents' ) );
	}

	$id = (int) ( $_POST['action_id'] ?? 0 );
	if ( ! $id ) {
		wp_send_json_error( __( 'Missing change ID.', 'arcadia-agents' ) );
	}

	$result = Arcadia_Pending_Actions::get_instance()->approve( $id, wp_get_current_user()->user_login );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( array( 'message' => __( 'Change approved and applied.', 'arcadia-agents' ) ) );
}

/**
 * AJAX: reject a pending action.
 */
function arcadia_agents_ajax_reject_pending_action() {
	check_ajax_referer( 'aa_revision_action', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'You are not allowed to reject this change.', 'arcadia-agents' ) );
	}

	$id = (int) ( $_POST['action_id'] ?? 0 );
	if ( ! $id ) {
		wp_send_json_error( __( 'Missing change ID.', 'arcadia-agents' ) );
	}

	$notes  = sanitize_textarea_field( wp_unslash( $_POST['decision_notes'] ?? '' ) );
	$result = Arcadia_Pending_Actions::get_instance()->reject( $id, wp_get_current_user()->user_login, $notes );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( array( 'message' => __( 'Change rejected.', 'arcadia-agents' ) ) );
}
