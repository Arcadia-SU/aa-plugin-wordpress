<?php
/**
 * Admin dashboard page — Arcadia Agents control center.
 *
 * Shows connection status, pending revisions to review,
 * and recent revision decisions.
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

	$is_connected  = get_option( 'arcadia_agents_connected', false );
	$connected_at  = get_option( 'arcadia_agents_connected_at', '' );
	$last_activity = get_option( 'arcadia_agents_last_activity', '' );

	// Count articles managed by Arcadia (tagged with arcadia_source taxonomy).
	$managed_count = 0;
	$managed_query = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'arcadia_source',
					'operator' => 'EXISTS',
				),
			),
		)
	);
	$managed_count = $managed_query->post_count;

	// Revision stats.
	$pending_revisions  = arcadia_dashboard_get_revisions( 'pending', 50 );
	$pending_count      = count( $pending_revisions );
	$approved_count     = arcadia_dashboard_count_revisions( 'approved' );
	$rejected_count     = arcadia_dashboard_count_revisions( 'rejected' );
	$recent_decisions   = arcadia_dashboard_get_recent_decisions( 10 );

	?>
	<div class="wrap arcadia-dashboard">

		<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
			<img src="<?php echo esc_url( ARCADIA_AGENTS_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="Arcadia Agents" style="width: 36px; height: 36px; border-radius: 6px;" />
			<h1 style="margin: 0;"><?php esc_html_e( 'Arcadia Agents', 'arcadia-agents' ); ?></h1>
		</div>

		<!-- Top cards row -->
		<div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">

			<!-- Connection card -->
			<div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo $is_connected ? '#00a32a' : '#d63638'; ?>; padding: 16px; border-radius: 0 4px 4px 0;">
				<div style="font-size: 13px; color: #646970; margin-bottom: 4px;"><?php esc_html_e( 'Connection', 'arcadia-agents' ); ?></div>
				<?php if ( $is_connected ) : ?>
					<div style="font-size: 18px; font-weight: 600; color: #00a32a; margin-bottom: 4px;"><?php esc_html_e( 'Connected', 'arcadia-agents' ); ?></div>
					<?php if ( $connected_at ) : ?>
						<div style="font-size: 12px; color: #646970;"><?php echo esc_html( sprintf( __( 'Since %s', 'arcadia-agents' ), wp_date( 'j M Y', strtotime( $connected_at ) ) ) ); ?></div>
					<?php endif; ?>
				<?php else : ?>
					<div style="font-size: 18px; font-weight: 600; color: #d63638;"><?php esc_html_e( 'Not connected', 'arcadia-agents' ); ?></div>
					<div style="font-size: 12px; color: #646970;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ); ?>"><?php esc_html_e( 'Configure connection', 'arcadia-agents' ); ?></a>
					</div>
				<?php endif; ?>
			</div>

			<!-- Stats cards -->
			<div style="flex: 1; min-width: 140px; background: #fff; border: 1px solid #c3c4c7; padding: 16px; border-radius: 4px;">
				<div style="font-size: 13px; color: #646970; margin-bottom: 4px;"><?php esc_html_e( 'Articles managed', 'arcadia-agents' ); ?></div>
				<div style="font-size: 28px; font-weight: 600; color: #1d2327;"><?php echo (int) $managed_count; ?></div>
			</div>

			<div style="flex: 1; min-width: 140px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo $pending_count > 0 ? '#dba617' : '#c3c4c7'; ?>; padding: 16px; border-radius: 0 4px 4px 0;">
				<div style="font-size: 13px; color: #646970; margin-bottom: 4px;"><?php esc_html_e( 'Pending review', 'arcadia-agents' ); ?></div>
				<div style="font-size: 28px; font-weight: 600; color: <?php echo $pending_count > 0 ? '#9a6700' : '#1d2327'; ?>;"><?php echo (int) $pending_count; ?></div>
			</div>

			<div style="flex: 1; min-width: 140px; background: #fff; border: 1px solid #c3c4c7; padding: 16px; border-radius: 4px;">
				<div style="font-size: 13px; color: #646970; margin-bottom: 4px;"><?php esc_html_e( 'Approved / Rejected', 'arcadia-agents' ); ?></div>
				<div style="font-size: 28px; font-weight: 600; color: #1d2327;">
					<span style="color: #00a32a;"><?php echo (int) $approved_count; ?></span>
					<span style="color: #c3c4c7; font-weight: 300;">/</span>
					<span style="color: #d63638;"><?php echo (int) $rejected_count; ?></span>
				</div>
			</div>

		</div>

		<!-- Pending Revisions table -->
		<div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 24px;">
			<div style="padding: 16px 20px; border-bottom: 1px solid #c3c4c7; display: flex; align-items: center; gap: 8px;">
				<h2 style="margin: 0; font-size: 14px;">
					<?php esc_html_e( 'Pending Revisions', 'arcadia-agents' ); ?>
				</h2>
				<?php if ( $pending_count > 0 ) : ?>
					<span style="background: #dba617; color: #fff; padding: 1px 8px; border-radius: 10px; font-size: 12px; font-weight: 600;"><?php echo (int) $pending_count; ?></span>
				<?php endif; ?>
			</div>

			<?php if ( empty( $pending_revisions ) ) : ?>
				<div style="padding: 40px 20px; text-align: center; color: #646970;">
					<span class="dashicons dashicons-yes-alt" style="font-size: 36px; width: 36px; height: 36px; color: #00a32a; display: block; margin: 0 auto 8px;"></span>
					<?php esc_html_e( 'No pending revisions. All clear!', 'arcadia-agents' ); ?>
				</div>
			<?php else : ?>
				<table class="widefat striped" style="border: none; box-shadow: none;">
					<thead>
						<tr>
							<th style="padding-left: 20px;"><?php esc_html_e( 'Article', 'arcadia-agents' ); ?></th>
							<th style="width: 70px;"><?php esc_html_e( 'Version', 'arcadia-agents' ); ?></th>
							<th style="width: 200px;"><?php esc_html_e( 'Notes', 'arcadia-agents' ); ?></th>
							<th style="width: 120px;"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
							<th style="width: 280px;"><?php esc_html_e( 'Actions', 'arcadia-agents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pending_revisions as $rev ) : ?>
							<tr>
								<td style="padding-left: 20px;">
									<strong><?php echo esc_html( $rev['parent_title'] ); ?></strong>
								</td>
								<td>
									<span style="background: #fff3cd; color: #664d03; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600;">v<?php echo (int) $rev['version']; ?></span>
								</td>
								<td style="color: #646970; font-size: 13px;">
									<?php echo $rev['notes'] ? esc_html( wp_trim_words( $rev['notes'], 10 ) ) : '<em>' . esc_html__( 'No notes', 'arcadia-agents' ) . '</em>'; ?>
								</td>
								<td style="color: #646970;"><?php echo esc_html( $rev['date'] ); ?></td>
								<td>
									<div class="aa-row-actions" data-revision-id="<?php echo (int) $rev['revision_id']; ?>" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
										<a href="<?php echo esc_url( $rev['preview_url'] ); ?>" target="_blank" class="button button-small"><?php esc_html_e( 'Preview', 'arcadia-agents' ); ?></a>
										<button type="button" class="button button-small button-primary aa-dash-approve"><?php esc_html_e( 'Approve', 'arcadia-agents' ); ?></button>
										<button type="button" class="button button-small aa-dash-reject" style="color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Reject', 'arcadia-agents' ); ?></button>
										<a href="<?php echo esc_url( get_edit_post_link( $rev['parent_id'] ) ); ?>" class="button button-small" style="color: #646970;"><?php esc_html_e( 'Edit', 'arcadia-agents' ); ?></a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Recent Decisions table -->
		<div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
			<div style="padding: 16px 20px; border-bottom: 1px solid #c3c4c7;">
				<h2 style="margin: 0; font-size: 14px;"><?php esc_html_e( 'Recent Decisions', 'arcadia-agents' ); ?></h2>
			</div>

			<?php if ( empty( $recent_decisions ) ) : ?>
				<div style="padding: 40px 20px; text-align: center; color: #646970;">
					<?php esc_html_e( 'No decisions yet.', 'arcadia-agents' ); ?>
				</div>
			<?php else : ?>
				<table class="widefat striped" style="border: none; box-shadow: none;">
					<thead>
						<tr>
							<th style="padding-left: 20px;"><?php esc_html_e( 'Article', 'arcadia-agents' ); ?></th>
							<th style="width: 70px;"><?php esc_html_e( 'Version', 'arcadia-agents' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Decision', 'arcadia-agents' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'By', 'arcadia-agents' ); ?></th>
							<th style="width: 120px;"><?php esc_html_e( 'Date', 'arcadia-agents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_decisions as $dec ) : ?>
							<tr>
								<td style="padding-left: 20px;">
									<?php echo esc_html( $dec['parent_title'] ); ?>
								</td>
								<td>v<?php echo (int) $dec['version']; ?></td>
								<td>
									<?php
									$badge_style = 'approved' === $dec['status']
										? 'background: #d1e7dd; color: #0a5c36;'
										: 'background: #f8d7da; color: #842029;';
									$label = 'approved' === $dec['status']
										? __( 'Approved', 'arcadia-agents' )
										: __( 'Rejected', 'arcadia-agents' );
									?>
									<span style="<?php echo esc_attr( $badge_style ); ?> padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
										<?php echo esc_html( $label ); ?>
									</span>
								</td>
								<td style="color: #646970;"><?php echo esc_html( $dec['decided_by'] ?: '—' ); ?></td>
								<td style="color: #646970;"><?php echo esc_html( $dec['date'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<p style="margin-top: 16px; color: #646970; font-size: 12px;">
			Arcadia Agents v<?php echo esc_html( ARCADIA_AGENTS_VERSION ); ?>
			&middot;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=arcadia-agents-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'arcadia-agents' ); ?></a>
		</p>

		<?php if ( ! empty( $pending_revisions ) ) : ?>
		<script>
		(function() {
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce   = '<?php echo esc_js( wp_create_nonce( 'aa_revision_action' ) ); ?>';

			function doAction( action, revisionId, row ) {
				var buttons = row.querySelectorAll( 'button, a.button' );
				buttons.forEach( function( b ) { b.disabled = true; b.style.opacity = '0.5'; b.style.pointerEvents = 'none'; } );

				var statusEl = document.createElement( 'span' );
				statusEl.style.fontSize = '12px';
				statusEl.style.marginLeft = '4px';
				statusEl.textContent = '<?php echo esc_js( __( 'Processing...', 'arcadia-agents' ) ); ?>';
				statusEl.style.color = '#664d03';
				row.appendChild( statusEl );

				var data = new FormData();
				data.append( 'action', action );
				data.append( 'revision_id', revisionId );
				data.append( 'nonce', nonce );

				fetch( ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( resp ) {
						if ( resp.success ) {
							var tr = row.closest( 'tr' );
							tr.style.transition = 'opacity 0.3s';
							tr.style.opacity = '0.3';
							statusEl.textContent = action === 'aa_approve_revision'
								? '<?php echo esc_js( __( 'Approved!', 'arcadia-agents' ) ); ?>'
								: '<?php echo esc_js( __( 'Rejected!', 'arcadia-agents' ) ); ?>';
							statusEl.style.color = '#0a5c36';
							setTimeout( function() { location.reload(); }, 800 );
						} else {
							statusEl.textContent = resp.data || 'Error';
							statusEl.style.color = '#b32d2e';
							buttons.forEach( function( b ) { b.disabled = false; b.style.opacity = '1'; b.style.pointerEvents = ''; } );
						}
					} )
					.catch( function() {
						statusEl.textContent = '<?php echo esc_js( __( 'Network error', 'arcadia-agents' ) ); ?>';
						statusEl.style.color = '#b32d2e';
						buttons.forEach( function( b ) { b.disabled = false; b.style.opacity = '1'; b.style.pointerEvents = ''; } );
					} );
			}

			function bindRow( row ) {
				var revisionId = row.dataset.revisionId;
				var original   = row.innerHTML;

				row.querySelector( '.aa-dash-approve' ).addEventListener( 'click', function() {
					row.innerHTML =
						'<span style="color: #0a5c36; font-size: 13px;"><?php echo esc_js( __( 'Apply to live?', 'arcadia-agents' ) ); ?></span> ' +
						'<button type="button" class="button button-small button-primary aa-confirm-yes"><?php echo esc_js( __( 'Confirm', 'arcadia-agents' ) ); ?></button> ' +
						'<button type="button" class="button button-small aa-confirm-no"><?php echo esc_js( __( 'Cancel', 'arcadia-agents' ) ); ?></button>';
					row.querySelector( '.aa-confirm-yes' ).addEventListener( 'click', function() {
						doAction( 'aa_approve_revision', revisionId, row );
					} );
					row.querySelector( '.aa-confirm-no' ).addEventListener( 'click', function() {
						row.innerHTML = original;
						bindRow( row );
					} );
				} );

				row.querySelector( '.aa-dash-reject' ).addEventListener( 'click', function() {
					row.innerHTML =
						'<div style="display: flex; flex-direction: column; gap: 6px;">' +
						'<textarea class="aa-reject-notes" rows="2" placeholder="<?php echo esc_js( __( 'Rejection notes (optional)', 'arcadia-agents' ) ); ?>" style="width: 100%; font-size: 12px;"></textarea>' +
						'<div style="display: flex; gap: 6px;">' +
						'<button type="button" class="button button-small aa-confirm-reject" style="color: #b32d2e; border-color: #b32d2e;"><?php echo esc_js( __( 'Confirm Rejection', 'arcadia-agents' ) ); ?></button>' +
						'<button type="button" class="button button-small aa-confirm-no"><?php echo esc_js( __( 'Cancel', 'arcadia-agents' ) ); ?></button>' +
						'</div></div>';
					row.querySelector( '.aa-confirm-reject' ).addEventListener( 'click', function() {
						var notes = row.querySelector( '.aa-reject-notes' ).value;
						var fd = new FormData();
						fd.append( 'action', 'aa_reject_revision' );
						fd.append( 'revision_id', revisionId );
						fd.append( 'decision_notes', notes );
						fd.append( 'nonce', nonce );

						var buttons = row.querySelectorAll( 'button' );
						buttons.forEach( function( b ) { b.disabled = true; } );

						fetch( ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
							.then( function( r ) { return r.json(); } )
							.then( function( resp ) {
								if ( resp.success ) {
									var tr = row.closest( 'tr' );
									tr.style.transition = 'opacity 0.3s';
									tr.style.opacity = '0.3';
									setTimeout( function() { location.reload(); }, 800 );
								} else {
									row.innerHTML = original;
									bindRow( row );
								}
							} )
							.catch( function() { row.innerHTML = original; bindRow( row ); } );
					} );
					row.querySelector( '.aa-confirm-no' ).addEventListener( 'click', function() {
						row.innerHTML = original;
						bindRow( row );
					} );
				} );
			}

			document.querySelectorAll( '.aa-row-actions' ).forEach( bindRow );
		})();
		</script>
		<?php endif; ?>

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
		$preview      = Arcadia_Preview::get_instance();
		$token        = $preview->get_or_create_token( $rev->ID );
		$preview_url  = add_query_arg(
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
