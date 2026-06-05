<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Repository\EntryRepository;
use Ifthenpay\CF7\Repository\DTO\EntryDto;

final class EntriesPage {

	private const PER_PAGE = 20;

	/**
	 * Hooked to load-{page} so headers are not yet sent — safe to redirect.
	 */
	public function process_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = new EntryRepository();

		$iftp_action = isset( $_GET['iftp_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['iftp_action'] ) ) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if ( $iftp_action === 'delete' && isset( $_GET['entry_id'], $_GET['_wpnonce'] ) ) { // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$act_id    = absint( $_GET['entry_id'] ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$act_nonce = sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			if ( wp_verify_nonce( $act_nonce, 'iftp_cf7_entry_action_' . $act_id ) ) {
				$repo->delete( $act_id );
				wp_safe_redirect( admin_url( 'admin.php?page=ifthenpay-cf7-entries' ) );
				exit;
			}
		}

		$this->handle_bulk_actions( $repo );
	}



	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = new EntryRepository();

		$view_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['entry_id'] ) && wp_verify_nonce( $view_nonce, 'iftp_cf7_view_entry' ) ) { // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$entry_id = absint( $_GET['entry_id'] ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$entry    = $repo->get_by_id( $entry_id );
			if ( $entry !== null ) {
				$this->render_single_entry( $entry );
				return;
			}
		}

		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$status       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_field = isset( $_GET['search_field'] ) ? sanitize_key( wp_unslash( (string) $_GET['search_field'] ) ) : 'customer_name'; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_op    = isset( $_GET['search_op'] ) ? sanitize_key( wp_unslash( (string) $_GET['search_op'] ) ) : 'contains'; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_query = isset( $_GET['search_query'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search_query'] ) ) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_op    = in_array( $search_op, array( 'contains', 'is' ), true ) ? $search_op : 'contains';

		$db_status = in_array( $status, array( 'pending', 'completed', 'failed', 'cancelled' ), true ) ? $status : '';

		$total        = $repo->count_all( $db_status, $search_field, $search_op, $search_query );
		$total_amount = $repo->sum_amount( $db_status, $search_field, $search_op, $search_query );
		$entries      = $repo->get_all( $current_page, self::PER_PAGE, $db_status, $search_field, $search_op, $search_query );
		$total_pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		$this->render_list( $repo, $entries, $current_page, $total_pages, $total, $total_amount, $status, $search_field, $search_op, $search_query );
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
			: array();

		if ( empty( $ids ) ) {
			return;
		}

		match ( $action ) {
			'delete'  => $repo->bulk_delete( $ids ),
			default   => null,
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
		string $search_field = 'customer_name',
		string $search_op = 'contains',
		string $search_query = ''
	): void {
		$counts          = array(
			''          => $repo->count_all(),
			'pending'   => $repo->count_all( 'pending' ),
			'completed' => $repo->count_all( 'completed' ),
			'failed'    => $repo->count_all( 'failed' ),
			'cancelled' => $repo->count_all( 'cancelled' ),
		);
		$sidebar_revenue = $repo->sum_amount( 'completed' );
		?>
		<div class="wrap iftp-cf7-entries-wrap">
			<div class="iftp-page-header">
				<div class="iftp-header-left">
					<h1 class="wp-heading-inline">
						<?php esc_html_e( 'ifthenpay Entries', 'ifthenpay-payments-for-contactform7' ); ?>
					</h1>
					<span class="iftp-badge">
						<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2.2"/><rect x="10.2" y="9" width="3.6" height="11" rx="1.5"/></svg>
						ifthenpay
					</span>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php /* Two-column layout: main table + sidebar */ ?>
			<div class="iftp-cf7-entries-layout">

				<?php /* ── Main table ── */ ?>
				<div class="iftp-cf7-entries-main">

				<?php /* Filter tabs — inside panel, centred on table width */ ?>
				<ul class="subsubsub">
					<?php
					$tabs = array(
						''          => __( 'All', 'ifthenpay-payments-for-contactform7' ),
						'pending'   => __( 'Pending', 'ifthenpay-payments-for-contactform7' ),
						'completed' => __( 'Paid', 'ifthenpay-payments-for-contactform7' ),
						'failed'    => __( 'Failed', 'ifthenpay-payments-for-contactform7' ),
						'cancelled' => __( 'Cancelled', 'ifthenpay-payments-for-contactform7' ),
					);
					foreach ( $tabs as $key => $label ) {
						$url = add_query_arg(
							array(
								'page'   => 'ifthenpay-cf7-entries',
								'status' => $key,
							),
							admin_url( 'admin.php' )
						);
						$cls = $current_tab === $key ? 'current' : '';
						$cnt = (int) ( $counts[ $key ] ?? 0 );
						printf(
							'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
							esc_url( $url ),
							esc_attr( $cls ),
							esc_html( $label ),
							(int) $cnt
						);
					}
					?>
				</ul>

				<?php $this->render_search_bar( $current_tab, $search_field, $search_op, $search_query ); ?>

				<?php if ( empty( $entries ) ) : ?>
				<div class="iftp-cf7-empty-state">
					<div class="iftp-empty-icon">
						<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="13" y2="13"/></svg>
					</div>
					<h3><?php esc_html_e( 'No entries found', 'ifthenpay-payments-for-contactform7' ); ?></h3>
					<p><?php esc_html_e( 'No payment entries match your current filter. Try a different search or status.', 'ifthenpay-payments-for-contactform7' ); ?></p>
				</div>
				<?php else : ?>

				<form method="post" id="iftp-bulk-form">
					<?php wp_nonce_field( 'iftp_cf7_bulk_entries', '_wpnonce_bulk' ); ?>
					<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
					<?php if ( $current_tab !== '' ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $current_tab ); ?>" />
					<?php endif; ?>

					<?php $this->render_tablenav_top( $current_page, $total_pages, $total, $total_amount ); ?>

					<div class="iftp-entries-table-wrap">
					<table class="wp-list-table widefat fixed striped iftp-cf7-entries-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input id="cb-select-all" type="checkbox" />
								</td>
								<th class="column-id"><?php esc_html_e( 'ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-customer"><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-transaction"><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-request"><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-date"><?php esc_html_e( 'Date', 'ifthenpay-payments-for-contactform7' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $entries as $entry ) :
							$action_nonce = wp_create_nonce( 'iftp_cf7_entry_action_' . $entry->id );
							$view_url     = add_query_arg(
								array(
									'page'     => 'ifthenpay-cf7-entries',
									'entry_id' => $entry->id,
									'_wpnonce' => wp_create_nonce( 'iftp_cf7_view_entry' ),
								),
								admin_url( 'admin.php' )
							);
							$delete_url   = add_query_arg(
								array(
									'page'        => 'ifthenpay-cf7-entries',
									'iftp_action' => 'delete',
									'entry_id'    => $entry->id,
									'_wpnonce'    => $action_nonce,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<th class="check-column">
									<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
								</th>
								<td class="column-id">
									<a href="<?php echo esc_url( $view_url ); ?>">#<?php echo esc_html( (string) $entry->id ); ?></a>
									<div class="row-actions">
										<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'ifthenpay-payments-for-contactform7' ); ?></a></span>
										| <span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete"
											onclick="return confirm('<?php esc_attr_e( 'Move this entry to trash?', 'ifthenpay-payments-for-contactform7' ); ?>');"><?php esc_html_e( 'Trash', 'ifthenpay-payments-for-contactform7' ); ?></a></span>
									</div>
								</td>
								<td class="column-customer">
									<strong><?php echo esc_html( $entry->customer_name ?: '—' ); ?></strong>
									<?php if ( $entry->customer_email !== '' ) : ?>
									<br /><a href="mailto:<?php echo esc_attr( $entry->customer_email ); ?>" style="font-size:12px;"><?php echo esc_html( $entry->customer_email ); ?></a>
									<?php endif; ?>
								</td>
								<td class="column-transaction" style="font-size:12px;">
									<?php echo $entry->transaction_id ? '<code>' . esc_html( $entry->transaction_id ) . '</code>' : '—'; ?>
								</td>
								<td class="column-request" style="font-size:12px;">
									<?php echo $entry->request_id ? '<code>' . esc_html( $entry->request_id ) . '</code>' : '—'; ?>
								</td>
								<td class="column-form"><?php echo esc_html( $entry->form_title ?: 'Form #' . $entry->form_id ); ?></td>
								<td class="column-method">
									<?php
									$method_colors = array(
										'mbway'       => '#00a550',
										'multibanco'  => '#2271b1',
										'card'        => '#dba617',
										'payshop'     => '#e84c3d',
										'cofidis'     => '#003d8f',
										'cofidisinst' => '#003d8f',
									);
									$dot_color     = $method_colors[ strtolower( $entry->payment_method ) ] ?? '#8c8f94';
									if ( $entry->payment_method !== '' ) :
										?>
									<span class="iftp-method-pill">
										<span class="iftp-method-dot" style="background:<?php echo esc_attr( $dot_color ); ?>"></span>
										<?php echo esc_html( $entry->payment_method ); ?>
									</span>
										<?php
									else :
										?>
										—<?php endif; ?>
								</td>
								<td class="column-amount"><?php echo esc_html( $entry->amount_formatted() ); ?></td>
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
								<td class="manage-column column-cb check-column"><input id="cb-select-all-2" type="checkbox" /></td>
								<th class="column-id"><?php esc_html_e( 'ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-customer"><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-transaction"><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-request"><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-form"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-method"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-amount"><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
								<th class="column-date"><?php esc_html_e( 'Date', 'ifthenpay-payments-for-contactform7' ); ?></th>
							</tr>
						</tfoot>
					</table>
					</div><!-- .iftp-entries-table-wrap -->

					<?php $this->render_tablenav_bottom( $current_page, $total_pages ); ?>
				</form>

				<?php endif; /* empty / not empty */ ?>
				</div><!-- .iftp-cf7-entries-main -->

				<!-- Sidebar -->
				<div class="iftp-cf7-entries-sidebar">

					<!-- Revenue metabox -->
					<div class="iftp-metabox">
						<div class="iftp-metabox-head">
							<svg class="iftp-meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
							<h3><?php esc_html_e( 'Revenue', 'ifthenpay-payments-for-contactform7' ); ?></h3>
						</div>
						<div class="iftp-metabox-body">
							<div class="iftp-rev-amount">€<?php echo esc_html( number_format( $sidebar_revenue, 2, '.', ',' ) ); ?></div>
							<div class="iftp-rev-sub">
								<?php
								printf(
									/* translators: %d: number of paid transactions */
									esc_html__( 'from %d paid transactions', 'ifthenpay-payments-for-contactform7' ),
									(int) ( $counts['completed'] ?? 0 )
								);
								?>
							</div>
							<hr class="iftp-rev-divider" />
							<div class="iftp-stats-list">
								<div class="iftp-stat-row">
									<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#dba617"></span><?php esc_html_e( 'Pending', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-stat-val"><?php echo esc_html( (string) ( $counts['pending'] ?? 0 ) ); ?></span>
								</div>
								<div class="iftp-stat-row">
									<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#00a550"></span><?php esc_html_e( 'Paid', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-stat-val"><?php echo esc_html( (string) ( $counts['completed'] ?? 0 ) ); ?></span>
								</div>
								<div class="iftp-stat-row">
									<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#d63638"></span><?php esc_html_e( 'Failed', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-stat-val"><?php echo esc_html( (string) ( $counts['failed'] ?? 0 ) ); ?></span>
								</div>
								<div class="iftp-stat-row">
									<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#8c8f94"></span><?php esc_html_e( 'Cancelled', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-stat-val"><?php echo esc_html( (string) ( $counts['cancelled'] ?? 0 ) ); ?></span>
								</div>
							</div>
						</div>
					</div>

					<!-- How entries work metabox -->
					<div class="iftp-metabox">
						<div class="iftp-metabox-head">
							<svg class="iftp-meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
							<h3><?php esc_html_e( 'How entries work', 'ifthenpay-payments-for-contactform7' ); ?></h3>
						</div>
						<div class="iftp-info-body">
							<p><?php esc_html_e( 'An entry is created every time a visitor clicks Pay on one of your Contact Form 7 forms. Entries start as Pending until payment is confirmed via callback.', 'ifthenpay-payments-for-contactform7' ); ?></p>
							<p><?php esc_html_e( 'IDs are never reused — deleting entries does not reset the counter.', 'ifthenpay-payments-for-contactform7' ); ?></p>
						</div>
					</div>

				</div><!-- .iftp-cf7-entries-sidebar -->
			</div><!-- .iftp-cf7-entries-layout -->
		</div><!-- .wrap -->

		<script>
		(function() {
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
		$fields    = array(
			'customer_name'  => __( 'Name', 'ifthenpay-payments-for-contactform7' ),
			'customer_email' => __( 'Email', 'ifthenpay-payments-for-contactform7' ),
			'form_title'     => __( 'Form', 'ifthenpay-payments-for-contactform7' ),
			'transaction_id' => __( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ),
			'payment_method' => __( 'Method', 'ifthenpay-payments-for-contactform7' ),
			'amount'         => __( 'Amount', 'ifthenpay-payments-for-contactform7' ),
		);
		$clear_url = add_query_arg(
			array(
				'page'   => 'ifthenpay-cf7-entries',
				'status' => $current_tab,
			),
			admin_url( 'admin.php' )
		);
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
				/* translators: %d: number of entries */
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
			<a class="first-page button" href="<?php echo $first_url; // placeholderphpcs:ignore(try fixing) WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&laquo;</span><span class="screen-reader-text"><?php esc_html_e( 'First page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<a class="prev-page button" href="<?php echo $prev_url; // placeholderphpcs:ignore(try fixing) WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
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
			<a class="next-page button" href="<?php echo $next_url; // placeholderphpcs:ignore(try fixing) WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&rsaquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Next page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<a class="last-page button" href="<?php echo $last_url; // placeholderphpcs:ignore(try fixing) WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><span aria-hidden="true">&raquo;</span><span class="screen-reader-text"><?php esc_html_e( 'Last page', 'ifthenpay-payments-for-contactform7' ); ?></span></a>
			<?php else : ?>
			<span class="next-page button disabled" aria-hidden="true">&rsaquo;</span>
			<span class="last-page button disabled" aria-hidden="true">&raquo;</span>
			<?php endif; ?>
		</span>
		<?php
	}



	private function render_single_entry( EntryDto $entry ): void {
		$back_url = admin_url( 'admin.php?page=ifthenpay-cf7-entries' );
		$del_url  = add_query_arg(
			array(
				'page'        => 'ifthenpay-cf7-entries',
				'iftp_action' => 'delete',
				'entry_id'    => $entry->id,
				'_wpnonce'    => wp_create_nonce( 'iftp_cf7_entry_action_' . $entry->id ),
			),
			admin_url( 'admin.php' )
		);

		$form_data = array();
		if ( $entry->form_data !== '' && $entry->form_data !== '{}' ) {
			$decoded   = json_decode( $entry->form_data, true );
			$form_data = is_array( $decoded ) ? $decoded : array();
		}

		$method_colors = array(
			'mbway'       => '#00a550',
			'multibanco'  => '#2271b1',
			'card'        => '#dba617',
			'payshop'     => '#e84c3d',
			'cofidis'     => '#003d8f',
			'cofidisinst' => '#003d8f',
		);
		$dot_color     = $method_colors[ strtolower( $entry->payment_method ) ] ?? '#8c8f94';

		$name_parts = preg_split( '/\s+/', trim( $entry->customer_name ) );
		$initials   = '';
		foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
			if ( $part !== '' ) {
				$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
			}
		}
		if ( $initials === '' ) {
			$initials = '?';
		}

		$gravatar_url = '';
		if ( $entry->customer_email !== '' ) {
			$gravatar_url = 'https://www.gravatar.com/avatar/' . md5( strtolower( trim( $entry->customer_email ) ) ) . '?s=80&d=404';
		}
		?>
		<div class="wrap iftp-cf7-entries-wrap">

			<div class="iftp-page-header">
				<div class="iftp-header-left">
					<a href="<?php echo esc_url( $back_url ); ?>" class="iftp-back-link">
						<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
						<?php esc_html_e( 'Entries', 'ifthenpay-payments-for-contactform7' ); ?>
					</a>
					<h1 class="wp-heading-inline">
						<?php esc_html_e( 'Entry', 'ifthenpay-payments-for-contactform7' ); ?>
						<strong>#<?php echo esc_html( (string) $entry->id ); ?></strong>
					</h1>
					<span class="iftp-badge">
						<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2.2"/><rect x="10.2" y="9" width="3.6" height="11" rx="1.5"/></svg>
						ifthenpay
					</span>
				</div>
				<a href="<?php echo esc_url( $del_url ); ?>" class="iftp-delete-btn"
					onclick="return confirm('<?php esc_attr_e( 'Delete this entry permanently?', 'ifthenpay-payments-for-contactform7' ); ?>');">
					<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
					<?php esc_html_e( 'Delete Entry', 'ifthenpay-payments-for-contactform7' ); ?>
				</a>
			</div>
			<hr class="wp-header-end" />

			<div class="iftp-detail-layout">

				<!-- ── Main column ── -->
				<div class="iftp-detail-main">

					<!-- Payment card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
							<h2><?php esc_html_e( 'Payment', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-detail-table">
								<tr>
									<th><?php esc_html_e( 'Status', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr( $entry->payment_status ); ?>"><?php echo esc_html( $entry->status_label() ); ?></span></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><span class="iftp-amount"><?php echo esc_html( $entry->amount_formatted() ); ?></span></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td>
										<?php if ( $entry->payment_method !== '' ) : ?>
										<span class="iftp-method-pill">
											<span class="iftp-method-dot" style="background:<?php echo esc_attr( $dot_color ); ?>"></span>
											<?php echo esc_html( $entry->payment_method ); ?>
										</span>
											<?php
										else :
											?>
											—<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><code><?php echo esc_html( $entry->transaction_id ?: '—' ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><code><?php echo esc_html( $entry->request_id ?? '—' ); ?></code></td>
								</tr>
								<?php if ( $entry->payment_url !== '' ) : ?>
								<tr>
									<th><?php esc_html_e( 'Payment Link', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td>
										<a href="<?php echo esc_url( $entry->payment_url ); ?>" target="_blank" rel="noopener noreferrer" class="iftp-pay-link">
											<?php esc_html_e( 'Open payment link', 'ifthenpay-payments-for-contactform7' ); ?>
											<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
										</a>
									</td>
								</tr>
								<?php endif; ?>
							</table>
						</div>
					</div>

					<!-- Customer card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
							<h2><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-detail-table">
								<tr>
									<th><?php esc_html_e( 'Name', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><?php echo esc_html( $entry->customer_name ?: '—' ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Email', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td>
										<?php if ( $entry->customer_email !== '' ) : ?>
										<a href="mailto:<?php echo esc_attr( $entry->customer_email ); ?>"><?php echo esc_html( $entry->customer_email ); ?></a>
											<?php
										else :
											?>
											—<?php endif; ?>
									</td>
								</tr>
							</table>
						</div>
					</div>

					<!-- Form card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
							<h2><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-detail-table">
								<tr>
									<th><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></th>
									<td><?php echo esc_html( $entry->form_title ?: 'Form #' . $entry->form_id ); ?></td>
								</tr>
							</table>
						</div>
					</div>

					<?php if ( ! empty( $form_data ) ) : ?>
					<!-- Submitted data card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
							<h2><?php esc_html_e( 'Submitted Data', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-submitted">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Field', 'ifthenpay-payments-for-contactform7' ); ?></th>
										<th><?php esc_html_e( 'Value', 'ifthenpay-payments-for-contactform7' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php
								foreach ( $form_data as $key => $value ) :
									if ( strpos( (string) $key, 'iftp_cf7_' ) === 0 ) {
										continue; }
									?>
									<tr>
										<td><?php echo esc_html( (string) $key ); ?></td>
										<td><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
					<?php endif; ?>

				</div><!-- .iftp-detail-main -->

				<!-- ── Aside ── -->
				<div class="iftp-detail-aside">

					<!-- Summary card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
							<h2><?php esc_html_e( 'Summary', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-summary-body">
							<div class="iftp-summary-amount"><?php echo esc_html( $entry->amount_formatted() ); ?></div>
							<div class="iftp-summary-sub">
								<?php if ( $entry->payment_method !== '' ) : ?>
									<?php esc_html_e( 'via', 'ifthenpay-payments-for-contactform7' ); ?>
									<?php echo esc_html( $entry->payment_method ); ?> &middot;
								<?php endif; ?>
								<span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr( $entry->payment_status ); ?> iftp-status-sm">
									<?php echo esc_html( $entry->status_label() ); ?>
								</span>
							</div>
							<hr class="iftp-summary-divider" />
							<div class="iftp-meta-list">
								<div class="iftp-meta-row">
									<span class="iftp-meta-label"><?php esc_html_e( 'Entry', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-meta-value">#<?php echo esc_html( (string) $entry->id ); ?></span>
								</div>
								<div class="iftp-meta-row">
									<span class="iftp-meta-label"><?php esc_html_e( 'Transaction ID', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-meta-value"><code><?php echo esc_html( $entry->transaction_id ?: '—' ); ?></code></span>
								</div>
								<div class="iftp-meta-row">
									<span class="iftp-meta-label"><?php esc_html_e( 'Form', 'ifthenpay-payments-for-contactform7' ); ?></span>
									<span class="iftp-meta-value"><?php echo esc_html( $entry->form_title ?: 'Form #' . $entry->form_id ); ?></span>
								</div>
							</div>
						</div>
					</div>

					<!-- Customer aside card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
							<h2><?php esc_html_e( 'Customer', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-customer-body">
							<div class="iftp-customer-row">
								<div class="iftp-customer-avatar" aria-hidden="true">
									<?php echo esc_html( $initials ); ?>
									<?php if ( $gravatar_url !== '' ) : ?>
									<img class="iftp-gravatar" src="<?php echo esc_url( $gravatar_url ); ?>" alt="" onerror="this.style.display='none';" />
									<?php endif; ?>
								</div>
								<div>
									<div class="iftp-customer-name"><?php echo esc_html( $entry->customer_name ?: '—' ); ?></div>
									<?php if ( $entry->customer_email !== '' ) : ?>
									<div class="iftp-customer-email">
										<a href="mailto:<?php echo esc_attr( $entry->customer_email ); ?>"><?php echo esc_html( $entry->customer_email ); ?></a>
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>

					<!-- Timestamps card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<h2><?php esc_html_e( 'Timestamps', 'ifthenpay-payments-for-contactform7' ); ?></h2>
						</div>
						<div class="iftp-timestamps">
							<div class="iftp-ts-row">
								<span class="iftp-ts-label"><?php esc_html_e( 'Created', 'ifthenpay-payments-for-contactform7' ); ?></span>
								<span class="iftp-ts-value"><?php echo esc_html( $entry->created_at ); ?></span>
							</div>
							<div class="iftp-ts-row">
								<span class="iftp-ts-label"><?php esc_html_e( 'Updated', 'ifthenpay-payments-for-contactform7' ); ?></span>
								<span class="iftp-ts-value"><?php echo esc_html( $entry->updated_at ); ?></span>
							</div>
						</div>
					</div>

				</div><!-- .iftp-detail-aside -->

			</div><!-- .iftp-detail-layout -->
		</div><!-- .wrap -->
		<?php
	}
}
