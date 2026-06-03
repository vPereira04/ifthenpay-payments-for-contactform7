<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Repository\EntryRepository;
use Ifthenpay\CF7\Repository\DTO\EntryDto;

final class EntriesPage {

	private const PER_PAGE   = 20;
	private const MAX_DELETE = 1000;

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = new EntryRepository();


		$iftp_action = isset( $_GET['iftp_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['iftp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $iftp_action, [ 'delete', 'mark_read', 'mark_unread' ], true ) && isset( $_GET['entry_id'], $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$act_id    = absint( $_GET['entry_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$act_nonce = sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( wp_verify_nonce( $act_nonce, 'iftp_cf7_entry_action_' . $act_id ) ) {
				if ( $iftp_action === 'delete' ) {
					$repo->delete( $act_id );
				} elseif ( $iftp_action === 'mark_read' ) {
					$repo->mark_as_read( $act_id );
				} elseif ( $iftp_action === 'mark_unread' ) {
					$repo->mark_as_unread( $act_id );
				}
				wp_safe_redirect( admin_url( 'admin.php?page=ifthenpay-cf7-entries' ) );
				exit;
			}
		}


		if ( isset( $_POST['iftp_range_action'] ) && $_POST['iftp_range_action'] === 'delete_range' ) {
			$range_nonce = isset( $_POST['_wpnonce_range'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce_range'] ) )
				: '';
			if ( wp_verify_nonce( $range_nonce, 'iftp_cf7_delete_range' ) && current_user_can( 'manage_options' ) ) {
				$from_id = absint( $_POST['id_from'] ?? 0 );
				$to_id   = absint( $_POST['id_to']   ?? 0 );

				if ( $from_id > 0 && $to_id >= $from_id ) {
					$deleted = $repo->delete_range( $from_id, $to_id, self::MAX_DELETE );
					wp_safe_redirect( add_query_arg(
						[ 'page' => 'ifthenpay-cf7-entries', 'range_deleted' => $deleted ],
						admin_url( 'admin.php' )
					) );
					exit;
				}
			}
		}


		$this->handle_bulk_actions( $repo );


		$view_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['entry_id'] ) && wp_verify_nonce( $view_nonce, 'iftp_cf7_view_entry' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$entry_id = absint( $_GET['entry_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$entry    = $repo->get_by_id( $entry_id );
			if ( $entry !== null ) {
				if ( ! $entry->is_read ) {
					$repo->mark_as_read( $entry_id );
				}
				$this->render_single_entry( $entry );
				return;
			}
		}


		$current_page  = isset( $_GET['paged'] )        ? max( 1, absint( $_GET['paged'] ) )                                       : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status        = isset( $_GET['status'] )       ? sanitize_key( wp_unslash( (string) $_GET['status'] ) )                   : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_field  = isset( $_GET['search_field'] ) ? sanitize_key( wp_unslash( (string) $_GET['search_field'] ) )             : 'customer_name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_op     = isset( $_GET['search_op'] )    ? sanitize_key( wp_unslash( (string) $_GET['search_op'] ) )                : 'contains'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_query  = isset( $_GET['search_query'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search_query'] ) )      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_op     = in_array( $search_op, [ 'contains', 'is' ], true ) ? $search_op : 'contains';

		$read_filter   = $status === 'unread' ? 'unread' : '';
		$db_status     = in_array( $status, [ 'pending', 'completed', 'failed', 'cancelled' ], true ) ? $status : '';

		$range_deleted = isset( $_GET['range_deleted'] ) ? absint( $_GET['range_deleted'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$total         = $repo->count_all( $db_status, $read_filter, $search_field, $search_op, $search_query );
		$total_amount  = $repo->sum_amount( $db_status, $read_filter, $search_field, $search_op, $search_query );
		$entries       = $repo->get_all( $current_page, self::PER_PAGE, $db_status, $read_filter, $search_field, $search_op, $search_query );
		$total_pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		$this->render_list( $repo, $entries, $current_page, $total_pages, $total, $total_amount, $status, $range_deleted, $search_field, $search_op, $search_query );
	}



	private function handle_bulk_actions( EntryRepository $repo ): void {
		$action = '';
		if ( isset( $_POST['action'] ) && sanitize_key( wp_unslash( (string) $_POST['action'] ) ) !== '-1' ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['action'] ) );
		} elseif ( isset( $_POST['action2'] ) && sanitize_key( wp_unslash( (string) $_POST['action2'] ) ) !== '-1' ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['action2'] ) );
		}

		if ( $action === '' ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce_bulk'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce_bulk'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'iftp_cf7_bulk_entries' ) ) {
			return;
		}

		$ids = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] )
			? array_filter( array_map( 'absint', (array) $_POST['entry_ids'] ) )
			: [];

		if ( empty( $ids ) ) {
			return;
		}

		match ( $action ) {
			'mark_read'   => $repo->bulk_mark_read( $ids ),
			'mark_unread' => $repo->bulk_mark_unread( $ids ),
			'delete'      => $repo->bulk_delete( $ids ),
			default       => null,
		};
	}



	/**
	 * @param EntryDto[] $entries
	 */
	private function render_list(
		EntryRepository $repo,
		array $entries,
		int $current_page,
		int $total_pages,
		int $total,
		float $total_amount,
		string $current_tab,
		?int $range_deleted,
		string $search_field = 'customer_name',
		string $search_op = 'contains',
		string $search_query = ''
	): void {
		$unread_count = $repo->count_unread();
		$counts       = [
			''          => $repo->count_all(),
			'unread'    => $unread_count,
			'pending'   => $repo->count_all( 'pending' ),
			'completed' => $repo->count_all( 'completed' ),
			'failed'    => $repo->count_all( 'failed' ),
			'cancelled' => $repo->count_all( 'cancelled' ),
		];
		?>
		<div class="wrap iftp-cf7-entries-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'ifthenpay Entries', 'ifthenpay-payments-for-contactform7' ); ?>
				<?php if ( $unread_count > 0 ) : ?>
				<span class="iftp-cf7-unread-count"><?php echo esc_html( (string) $unread_count ); ?></span>
				<?php endif; ?>
			</h1>
			<hr class="wp-header-end" />

			<?php if ( $range_deleted !== null ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf(
					/* translators: %d: number of entries deleted */
					esc_html( _n( '%d entry deleted.', '%d entries deleted.', $range_deleted, 'ifthenpay-payments-for-contactform7' ) ),
					(int) $range_deleted
				); ?></p>
			</div>
			<?php endif; ?>

			<?php /* Filter tabs */ ?>
			<ul class="subsubsub">
				<?php
				$tabs       = [
					''          => __( 'All', 'ifthenpay-payments-for-contactform7' ),
					'unread'    => __( 'Unread', 'ifthenpay-payments-for-contactform7' ),
					'pending'   => __( 'Pending', 'ifthenpay-payments-for-contactform7' ),
					'completed' => __( 'Paid', 'ifthenpay-payments-for-contactform7' ),
					'failed'    => __( 'Failed', 'ifthenpay-payments-for-contactform7' ),
					'cancelled' => __( 'Cancelled', 'ifthenpay-payments-for-contactform7' ),
				];
				$tab_links  = [];
				foreach ( $tabs as $key => $label ) {
					$url       = add_query_arg( [ 'page' => 'ifthenpay-cf7-entries', 'status' => $key ], admin_url( 'admin.php' ) );
					$cls       = $current_tab === $key ? 'current' : '';
					$cnt       = (int) ( $counts[$key] ?? 0 );
					$tab_links[] = sprintf(
						'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
						esc_url( $url ), esc_attr( $cls ), esc_html( $label ), $cnt
					);
				}
				echo wp_kses_post( implode( ' | ', $tab_links ) );
				?>
			</ul>

			<?php /* Two-column layout: main table + sidebar */ ?>
			<div class="iftp-cf7-entries-layout">

				<?php /* ── Main table ── */ ?>
				<div class="iftp-cf7-entries-main">

				<?php $this->render_search_bar( $current_tab, $search_field, $search_op, $search_query ); ?>

				<?php if ( empty( $entries ) ) : ?>
				<div class="iftp-cf7-empty-state">
					<p><?php esc_html_e( 'No entries found.', 'ifthenpay-payments-for-contactform7' ); ?></p>
				</div>
				<?php else : ?>

				<form method="post" id="iftp-bulk-form">
					<?php wp_nonce_field( 'iftp_cf7_bulk_entries', '_wpnonce_bulk' ); ?>
					<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
					<?php if ( $current_tab !== '' ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $current_tab ); ?>" />
					<?php endif; ?>

					<?php $this->render_tablenav_top( $current_page, $total_pages, $total, $total_amount ); ?>

					<table class="wp-list-table widefat fixed striped iftp-cf7-entries-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input id="cb-select-all" type="checkbox" />
								</td>
								<th class="column-id"><?php esc_html_e( 'ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-customer"><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-date"><?php esc_html_e( 'Date', 'ifthenpay-payments-for-contactform7' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $entries as $entry ) :
							$action_nonce    = wp_create_nonce( 'iftp_cf7_entry_action_' . $entry->id );
							$view_url        = add_query_arg( [
								'page'     => 'ifthenpay-cf7-entries',
								'entry_id' => $entry->id,
								'_wpnonce' => wp_create_nonce( 'iftp_cf7_view_entry' ),
							], admin_url( 'admin.php' ) );
							$delete_url      = add_query_arg( [
								'page'        => 'ifthenpay-cf7-entries',
								'iftp_action' => 'delete',
								'entry_id'    => $entry->id,
								'_wpnonce'    => $action_nonce,
							], admin_url( 'admin.php' ) );
							$toggle_read_url = add_query_arg( [
								'page'        => 'ifthenpay-cf7-entries',
								'iftp_action' => $entry->is_read ? 'mark_unread' : 'mark_read',
								'entry_id'    => $entry->id,
								'_wpnonce'    => $action_nonce,
							], admin_url( 'admin.php' ) );
							$row_class       = ! $entry->is_read ? 'iftp-cf7-unread' : '';
						?>
							<tr class="<?php echo esc_attr( $row_class ); ?>">
								<th class="check-column">
									<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
								</th>
								<td class="column-id">
									<?php if ( ! $entry->is_read ) : ?>
									<span class="iftp-cf7-unread-dot" title="<?php esc_attr_e( 'Unread', 'ifthenpay-payments-for-contactform7' ); ?>"></span>
									<?php endif; ?>
									<a href="<?php echo esc_url( $view_url ); ?>">#<?php echo esc_html( (string) $entry->id ); ?></a>
									<div class="row-actions">
										<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'ifthenpay-payments-for-contactform7' ); ?></a></span>
										| <span class="edit"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Edit', 'ifthenpay-payments-for-contactform7' ); ?></a></span>
										| <span class="mark-read"><a href="<?php echo esc_url( $toggle_read_url ); ?>">
											<?php echo $entry->is_read
												? esc_html__( 'Mark as Unread', 'ifthenpay-payments-for-contactform7' )
												: esc_html__( 'Mark as Read', 'ifthenpay-payments-for-contactform7' ); ?>
										</a></span>
										| <span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete"
											onclick="return confirm('<?php esc_attr_e( 'Move this entry to trash?', 'ifthenpay-payments-for-contactform7' ); ?>');"><?php esc_html_e( 'Trash', 'ifthenpay-payments-for-contactform7' ); ?></a></span>
									</div>
								</td>
								<td class="column-form"><?php echo esc_html( $entry->form_title ?: 'Form #' . $entry->form_id ); ?></td>
								<td class="column-customer">
									<?php if ( ! $entry->is_read ) : ?><strong><?php endif; ?>
									<?php echo esc_html( $entry->customer_name ?: '—' ); ?>
									<?php if ( ! $entry->is_read ) : ?></strong><?php endif; ?>
									<?php if ( $entry->customer_email !== '' ) : ?>
									<br /><a href="mailto:<?php echo esc_attr( $entry->customer_email ); ?>" style="font-size:12px;"><?php echo esc_html( $entry->customer_email ); ?></a>
									<?php endif; ?>
								</td>
								<td class="column-amount"><?php echo esc_html( $entry->amount_formatted() ); ?></td>
								<td class="column-method"><?php echo esc_html( $entry->payment_method ?: '—' ); ?></td>
								<td class="column-status">
									<span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr( $entry->payment_status ); ?>">
										<?php echo esc_html( $entry->status_label() ); ?>
									</span>
									<?php if ( ! $entry->is_paid() && $entry->payment_url !== '' ) : ?>
									<br /><a href="<?php echo esc_url( $entry->payment_url ); ?>" target="_blank" rel="noopener noreferrer"
										class="iftp-cf7-pbl-link" title="<?php esc_attr_e( 'Open payment link', 'ifthenpay-payments-for-contactform7' ); ?>">&#x1F517;</a>
									<?php endif; ?>
								</td>
								<td class="column-date" style="font-size:12px;"><?php echo esc_html( $entry->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<td class="manage-column column-cb check-column"><input type="checkbox" /></td>
								<th class="column-id"><?php esc_html_e( 'ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-customer"><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-date"><?php esc_html_e( 'Date', 'ifthenpay-payments-for-contactform7' ); ?></th>
							</tr>
						</tfoot>
					</table>

					<?php $this->render_tablenav_bottom( $current_page, $total_pages ); ?>
				</form>

				<?php endif; /* empty / not empty */ ?>
				</div><!-- .iftp-cf7-entries-main -->

				<?php /* ── Sidebar ── */ ?>
				<div class="iftp-cf7-entries-sidebar">

					<?php /* Delete by ID range */ ?>
					<div class="iftp-sidebar-card">
						<h3><?php esc_html_e( 'Delete by ID Range', 'ifthenpay-payments-for-contactform7' ); ?></h3>
						<form method="post" id="iftp-range-form">
							<?php wp_nonce_field( 'iftp_cf7_delete_range', '_wpnonce_range' ); ?>
							<input type="hidden" name="iftp_range_action" value="delete_range" />
							<p>
								<label for="iftp-id-from"><?php esc_html_e( 'From ID', 'ifthenpay-payments-for-contactform7' ); ?></label>
								<input type="number" id="iftp-id-from" name="id_from"
									class="small-text" min="1" placeholder="1" required />
							</p>
							<p>
								<label for="iftp-id-to"><?php esc_html_e( 'To ID', 'ifthenpay-payments-for-contactform7' ); ?></label>
								<input type="number" id="iftp-id-to" name="id_to"
									class="small-text" min="1" placeholder="100" required />
							</p>
							<p id="iftp-range-warning" class="iftp-range-warning" style="display:none">
								<?php
								printf(
									/* translators: %d: max entries per operation */
									esc_html__( 'Only the first %d entries will be deleted (maximum per operation).', 'ifthenpay-payments-for-contactform7' ),
									self::MAX_DELETE
								);
								?>
							</p>
							<p>
								<input type="submit"
									class="button iftp-delete-range-btn"
									value="<?php esc_attr_e( 'Delete Entries', 'ifthenpay-payments-for-contactform7' ); ?>"
									onclick="return confirm('<?php esc_attr_e( 'Permanently delete entries in this ID range?', 'ifthenpay-payments-for-contactform7' ); ?>');" />
							</p>
						</form>
					</div><!-- .iftp-sidebar-card -->

					<?php /* Collapsible help text */ ?>
					<div class="iftp-sidebar-card iftp-help-card">
						<button type="button" class="iftp-help-toggle" aria-expanded="false">
							<span class="iftp-help-arrow">&#9650;</span>
							<?php esc_html_e( 'How entries work', 'ifthenpay-payments-for-contactform7' ); ?>
						</button>
						<div class="iftp-help-body" style="display:none">
							<p><?php esc_html_e( 'An entry is created every time a visitor clicks the Pay button on one of your Contact Form 7 forms. Entries start with status Pending until the payment is confirmed via callback.', 'ifthenpay-payments-for-contactform7' ); ?></p>
							<p><?php esc_html_e( 'IDs are never reused — deleting entries does not reset the counter.', 'ifthenpay-payments-for-contactform7' ); ?></p>
							<p><?php
							printf(
								/* translators: %d: max per range delete */
								esc_html__( 'The range-delete tool removes up to %d entries at a time to avoid timeouts. Run it again for larger ranges.', 'ifthenpay-payments-for-contactform7' ),
								self::MAX_DELETE
							);
							?></p>
						</div>
					</div><!-- .iftp-sidebar-card.iftp-help-card -->

				</div><!-- .iftp-cf7-entries-sidebar -->
			</div><!-- .iftp-cf7-entries-layout -->
		</div><!-- .wrap -->

		<script>
		(function() {
			/* Range warning */
			var from = document.getElementById('iftp-id-from');
			var to   = document.getElementById('iftp-id-to');
			var warn = document.getElementById('iftp-range-warning');
			function checkRange() {
				if (!from || !to || !warn) return;
				var diff = parseInt(to.value, 10) - parseInt(from.value, 10) + 1;
				warn.style.display = (!isNaN(diff) && diff > <?php echo (int) self::MAX_DELETE; ?>) ? '' : 'none';
			}
			if (from) from.addEventListener('input', checkRange);
			if (to)   to.addEventListener('input', checkRange);

			/* Help toggle */
			var btn  = document.querySelector('.iftp-help-toggle');
			var body = document.querySelector('.iftp-help-body');
			var arrow = document.querySelector('.iftp-help-arrow');
			if (btn && body) {
				btn.addEventListener('click', function() {
					var open = body.style.display !== 'none';
					body.style.display  = open ? 'none' : '';
					arrow.innerHTML     = open ? '&#9650;' : '&#9660;';
					btn.setAttribute('aria-expanded', String(!open));
				});
			}

			/* Page-number input: navigate on Enter */
			var pageInput = document.getElementById('iftp-paged-input');
			if (pageInput) {
				pageInput.addEventListener('keydown', function(e) {
					if (e.key !== 'Enter') return;
					e.preventDefault();
					var p = parseInt(this.value, 10);
					var total = parseInt(this.getAttribute('data-total'), 10);
					if (isNaN(p) || p < 1) p = 1;
					if (p > total) p = total;
					var url = new URL(window.location.href);
					url.searchParams.set('paged', p);
					window.location.href = url.toString();
				});
			}
		})();
		</script>
		<?php
	}



	private function render_search_bar( string $current_tab, string $search_field, string $search_op, string $search_query ): void {
		$fields = [
			'customer_name'  => __( 'Name', 'ifthenpay-payments-for-contactform7' ),
			'customer_email' => __( 'Email', 'ifthenpay-payments-for-contactform7' ),
			'form_title'     => __( 'Form', 'ifthenpay-payments-for-contactform7' ),
			'transaction_id' => __( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ),
			'payment_method' => __( 'Method', 'ifthenpay-payments-for-contactform7' ),
			'amount'         => __( 'Amount', 'ifthenpay-payments-for-contactform7' ),
		];
		$clear_url = add_query_arg( [ 'page' => 'ifthenpay-cf7-entries', 'status' => $current_tab ], admin_url( 'admin.php' ) );
		?>
		<form method="get" id="iftp-search-form" class="iftp-search-bar">
			<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
			<?php if ( $current_tab !== '' ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $current_tab ); ?>" />
			<?php endif; ?>
			<select name="search_field" aria-label="<?php esc_attr_e( 'Search field', 'ifthenpay-payments-for-contactform7' ); ?>">
				<?php foreach ( $fields as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $search_field, $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="search_op" aria-label="<?php esc_attr_e( 'Operator', 'ifthenpay-payments-for-contactform7' ); ?>">
				<option value="contains"<?php selected( $search_op, 'contains' ); ?>><?php esc_html_e( 'contains', 'ifthenpay-payments-for-contactform7' ); ?></option>
				<option value="is"<?php selected( $search_op, 'is' ); ?>><?php esc_html_e( 'is', 'ifthenpay-payments-for-contactform7' ); ?></option>
			</select>
			<input type="search" name="search_query" value="<?php echo esc_attr( $search_query ); ?>"
				class="regular-text" placeholder="<?php esc_attr_e( 'Type to search…', 'ifthenpay-payments-for-contactform7' ); ?>" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'ifthenpay-payments-for-contactform7' ); ?>" />
			<?php if ( $search_query !== '' ) : ?>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'ifthenpay-payments-for-contactform7' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	private function render_tablenav_top( int $current_page, int $total_pages, int $total, float $total_amount ): void {
		?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="action">
					<option value="-1"><?php esc_html_e( 'Bulk Actions', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="mark_read"><?php esc_html_e( 'Mark as Read', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="mark_unread"><?php esc_html_e( 'Mark as Unread', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-contactform7' ); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'ifthenpay-payments-for-contactform7' ); ?>" />
			</div>
			<?php $this->pagination( $current_page, $total_pages, $total, $total_amount ); ?>
			<br class="clear" />
		</div>
		<?php
	}

	private function render_tablenav_bottom( int $current_page, int $total_pages ): void {
		?>
		<div class="tablenav bottom">
			<div class="alignleft actions bulkactions">
				<select name="action2">
					<option value="-1"><?php esc_html_e( 'Bulk Actions', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="mark_read"><?php esc_html_e( 'Mark as Read', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="mark_unread"><?php esc_html_e( 'Mark as Unread', 'ifthenpay-payments-for-contactform7' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'ifthenpay-payments-for-contactform7' ); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'ifthenpay-payments-for-contactform7' ); ?>" />
			</div>
			<?php $this->pagination( $current_page, $total_pages ); ?>
			<br class="clear" />
		</div>
		<?php
	}

	private function pagination( int $current_page, int $total_pages, int $total = 0, float $total_amount = 0.0 ): void {

		if ( $total > 0 || $total_amount > 0.0 ) {
			$count_text = sprintf(
				esc_html( _n( '%d item', '%d items', $total, 'ifthenpay-payments-for-contactform7' ) ),
				$total
			);
			if ( $total_amount > 0.0 ) {
				$count_text .= ' &mdash; ' . esc_html( number_format( $total_amount, 2, '.', ',' ) ) . ' &euro;';
			}
			echo '<span class="displaying-num">' . wp_kses_post( $count_text ) . '</span>';
		}

		if ( $total_pages <= 1 ) {
			return;
		}

		$first_url = esc_url( add_query_arg( 'paged', 1 ) );
		$prev_url  = esc_url( add_query_arg( 'paged', max( 1, $current_page - 1 ) ) );
		$next_url  = esc_url( add_query_arg( 'paged', min( $total_pages, $current_page + 1 ) ) );
		$last_url  = esc_url( add_query_arg( 'paged', $total_pages ) );
		?>
		<span class="pagination-links">
			<?php if ( $current_page > 1 ) : ?>
			<a class="first-page button" href="<?php echo $first_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&laquo;</span><span class="screen-reader-text"><?php esc_html_e( 'First page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<a class="prev-page button" href="<?php echo $prev_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<?php else : ?>
			<span class="first-page button disabled" aria-hidden="true">&laquo;</span>
			<span class="prev-page button disabled" aria-hidden="true">&lsaquo;</span>
			<?php endif; ?>

			<span class="paging-input">
				<label class="screen-reader-text" for="iftp-paged-input"><?php esc_html_e( 'Current page', 'ifthenpay-payments-for-contactform7' ); ?></label>
				<input class="current-page" id="iftp-paged-input" type="text" value="<?php echo (int) $current_page; ?>" size="2"
					data-total="<?php echo (int) $total_pages; ?>" />
				<span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'ifthenpay-payments-for-contactform7' ); ?>
					<span class="total-pages"><?php echo (int) $total_pages; ?></span>
				</span>
			</span>

			<?php if ( $current_page < $total_pages ) : ?>
			<a class="next-page button" href="<?php echo $next_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&rsaquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Next page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<a class="last-page button" href="<?php echo $last_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&raquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Last page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<?php else : ?>
			<span class="next-page button disabled" aria-hidden="true">&rsaquo;</span>
			<span class="last-page button disabled" aria-hidden="true">&raquo;</span>
			<?php endif; ?>
		</span>
		<?php
	}



	private function render_single_entry( EntryDto $entry ): void {
		$back_url = admin_url( 'admin.php?page=ifthenpay-cf7-entries' );
		$del_url  = add_query_arg( [
			'page'        => 'ifthenpay-cf7-entries',
			'iftp_action' => 'delete',
			'entry_id'    => $entry->id,
			'_wpnonce'    => wp_create_nonce( 'iftp_cf7_delete_entry_' . $entry->id ),
		], admin_url( 'admin.php' ) );

		$form_data = [];
		if ( $entry->form_data !== '' && $entry->form_data !== '{}' ) {
			$decoded   = json_decode( $entry->form_data, true );
			$form_data = is_array( $decoded ) ? $decoded : [];
		}
		?>
		<div class="wrap iftp-cf7-entries-wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Entries', 'ifthenpay-payments-for-contactform7' ); ?></a>
				<?php printf( esc_html__( 'Entry #%d', 'ifthenpay-payments-for-contactform7' ), $entry->id ); ?>
				<a href="<?php echo esc_url( $del_url ); ?>" class="page-title-action" style="color:#b42318;border-color:#b42318;"
					onclick="return confirm('<?php esc_attr_e( 'Delete this entry permanently?', 'ifthenpay-payments-for-contactform7' ); ?>');">
					<?php esc_html_e( 'Delete', 'ifthenpay-payments-for-contactform7' ); ?>
				</a>
			</h1>

			<div class="iftp-cf7-entry-detail">
				<div class="iftp-cf7-detail-card">
					<h2><?php esc_html_e( 'Payment', 'ifthenpay-payments-for-contactform7' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr( $entry->payment_status ); ?>"><?php echo esc_html( $entry->status_label() ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><strong><?php echo esc_html( $entry->amount_formatted() ); ?></strong></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><?php echo esc_html( $entry->payment_method ?: '—' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><code><?php echo esc_html( $entry->transaction_id ?: '—' ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><code><?php echo esc_html( $entry->request_id ?? '—' ); ?></code></td>
						</tr>
						<?php if ( ! $entry->is_paid() && $entry->payment_url !== '' ) : ?>
						<tr>
							<th><?php esc_html_e( 'Payment Link', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><a href="<?php echo esc_url( $entry->payment_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open payment link', 'ifthenpay-payments-for-contactform7' ); ?> &#x2197;
							</a></td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'Created', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><?php echo esc_html( $entry->created_at ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Updated', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><?php echo esc_html( $entry->updated_at ); ?></td>
						</tr>
					</table>
				</div>

				<div class="iftp-cf7-detail-card">
					<h2><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Name', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><?php echo esc_html( $entry->customer_name ?: '—' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td><?php if ( $entry->customer_email !== '' ) : ?><a href="mailto:<?php echo esc_attr( $entry->customer_email ); ?>"><?php echo esc_html( $entry->customer_email ); ?></a><?php else : ?>—<?php endif; ?></td>
						</tr>
					</table>
				</div>

				<div class="iftp-cf7-detail-card">
					<h2><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<td>
								<?php echo esc_html( $entry->form_title ?: 'Form #' . $entry->form_id ); ?>
								<?php if ( $entry->form_id > 0 ) : ?>
								&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcf7&action=edit&post=' . $entry->form_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit form', 'ifthenpay-payments-for-contactform7' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<?php if ( ! empty( $form_data ) ) : ?>
				<div class="iftp-cf7-detail-card">
					<h2><?php esc_html_e( 'Submitted Data', 'ifthenpay-payments-for-contactform7' ); ?></h2>
					<table class="widefat striped">
						<thead><tr>
							<th style="width:200px"><?php esc_html_e( 'Field', 'ifthenpay-payments-for-contactform7' ); ?></th>
							<th><?php esc_html_e( 'Value', 'ifthenpay-payments-for-contactform7' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $form_data as $key => $value ) :
							if ( strpos( (string) $key, 'iftp_cf7_' ) === 0 ) { continue; }
						?>
							<tr>
								<td><strong><?php echo esc_html( (string) $key ); ?></strong></td>
								<td><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
