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
	// Only a person's "no" is red: out of date and withdrawn are nobody's
	// refusal, so they stay neutral.
	$decision_tone = array(
		'approved'  => 'approved',
		'rejected'  => 'rejected',
		'stale'     => 'neutral',
		'withdrawn' => 'neutral',
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
				<p class="aa-muted">
					<?php esc_html_e( 'Arcadia proposes these changes. Nothing changes on your site until you approve.', 'arcadia-agents' ); ?>
					<br />
					<?php esc_html_e( 'Edits to an article’s text are approved in the article editor.', 'arcadia-agents' ); ?>
				</p>

				<div id="aa-pending-empty" class="aa-muted" <?php echo empty( $pending ) ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Nothing is waiting for your approval.', 'arcadia-agents' ); ?>
				</div>

				<?php if ( ! empty( $pending ) ) : ?>
					<div class="aa-bulkbar" id="aa-bulkbar" hidden>
						<span class="aa-bulkbar__count" id="aa-bulkbar-count" aria-live="polite"></span>
						<div class="aa-bulkbar__actions" id="aa-bulkbar-actions"></div>
					</div>

					<table class="aa-table aa-table--queue aa-table--changes" id="aa-pending-table">
						<thead>
							<tr>
								<th scope="col" class="aa-cell-check">
									<input type="checkbox" id="aa-select-all" class="aa-check" aria-label="<?php esc_attr_e( 'Select all changes', 'arcadia-agents' ); ?>" />
								</th>
								<th scope="col"><?php esc_html_e( 'Action', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Item', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Approve or reject', 'arcadia-agents' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending as $row ) : ?>
								<tr id="aa-action-<?php echo (int) $row['id']; ?>" class="aa-change aa-change--<?php echo esc_attr( $row['summary']['verb'] ); ?><?php echo $focus_id === $row['id'] ? ' aa-row--focus' : ''; ?>">
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
									<?php arcadia_agents_change_cells( $row['summary'] ); ?>
									<td class="aa-muted aa-cell-date"><?php echo esc_html( wp_date( 'j M Y', strtotime( $row['date'] ) ) ); ?></td>
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
					<table class="aa-table aa-table--changes">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Action', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Item', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Decision', 'arcadia-agents' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $decided as $row ) : ?>
								<tr class="aa-change aa-change--<?php echo esc_attr( $row['summary']['verb'] ); ?>">
									<?php arcadia_agents_change_cells( $row['summary'] ); ?>
									<td>
										<span class="aa-decision aa-decision--<?php echo esc_attr( $decision_tone[ $row['status'] ] ?? 'neutral' ); ?>">
											<?php echo esc_html( $status_labels[ $row['status'] ] ?? $row['status'] ); ?>
										</span>
									</td>
									<td class="aa-muted aa-cell-date"><?php echo esc_html( wp_date( 'j M Y', strtotime( $row['date'] ) ) ); ?></td>
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
 * Icon of a verb: trash = delete, pencil = edit, plus = create. One mapping,
 * used by the rows and by the settings list, so a symbol means one thing.
 *
 * @param string $verb 'delete', 'edit', 'create', or 'direct' (applied without approval).
 * @return string Inline SVG.
 */
function arcadia_agents_verb_icon( $verb ) {
	$icons = array(
		'delete' => 'trash',
		'edit'   => 'pencil',
		'create' => 'plus',
		'direct' => 'check',
	);
	return arcadia_dashboard_icon( $icons[ $verb ] ?? 'pencil' );
}

/**
 * The two cells that say what a change does: the action (a coloured verb, so
 * a deletion never reads like an edit) and the item (its kind, its name, what
 * changes, and whether a deletion can be undone).
 *
 * Shared by the waiting list and the decisions table, so both read alike.
 * The empty .aa-row-error slot receives the reason when an approval fails.
 *
 * @param array $summary Arcadia_Pending_Actions::summary().
 */
function arcadia_agents_change_cells( array $summary ) {
	?>
	<td class="aa-cell-verb">
		<span class="aa-verb aa-verb--<?php echo esc_attr( $summary['verb'] ); ?>" title="<?php echo esc_attr( $summary['verb_label'] ); ?>">
			<?php echo arcadia_agents_verb_icon( $summary['verb'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
			<span class="screen-reader-text"><?php echo esc_html( $summary['verb_label'] ); ?></span>
		</span>
	</td>
	<td class="aa-cell-item">
		<span class="aa-item__kind"><?php echo esc_html( $summary['kind_label'] ); ?></span>
		<span class="aa-item__subject"><?php echo esc_html( $summary['subject'] ); ?></span>
		<?php if ( '' !== $summary['detail'] || '' !== $summary['effect'] ) : ?>
			<span class="aa-item__meta">
				<?php if ( '' !== $summary['effect'] ) : ?>
					<span class="aa-effect aa-effect--<?php echo esc_attr( $summary['effect'] ); ?>"><?php echo esc_html( $summary['effect_label'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $summary['detail'] ) : ?>
					<span class="aa-item__detail"><?php echo esc_html( $summary['detail'] ); ?></span>
				<?php endif; ?>
			</span>
		<?php endif; ?>
		<span class="aa-row-error" hidden></span>
	</td>
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
		// The code lets the screen tell "out of date" (the row just leaves
		// the list) from a real failure (the row turns red and says why).
		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			)
		);
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
		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			)
		);
	}

	wp_send_json_success( array( 'message' => __( 'Change rejected.', 'arcadia-agents' ) ) );
}
