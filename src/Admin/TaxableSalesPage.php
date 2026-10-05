<?php
/**
 * Taxable sales report screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Reports\TaxableSalesReport;
use PoorVida\TaxReports\Support\Csv;
use PoorVida\TaxReports\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Texas sales-tax figures for a date range.
 */
final class TaxableSalesPage {

	/**
	 * Wire up the screen.
	 *
	 * @param TaxableSalesReport $report Report.
	 */
	public function __construct( private readonly TaxableSalesReport $report ) {}

	/**
	 * Hook the CSV export. Runs on `admin_init`, before any admin page
	 * output has started — a submenu page's own render() runs too late to
	 * send file headers.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );
	}

	/**
	 * Human-readable category names.
	 *
	 * @return array<string, string>
	 */
	private static function category_labels(): array {
		return [
			TaxableSalesReport::CATEGORY_TAXABLE      => __( 'Taxable sales', 'pv-tax-reports' ),
			TaxableSalesReport::CATEGORY_EXEMPT_TAXED => __( 'Exempt sales, tax collected in error', 'pv-tax-reports' ),
			TaxableSalesReport::CATEGORY_EXEMPT       => __( 'Exempt sales, no tax collected', 'pv-tax-reports' ),
			TaxableSalesReport::CATEGORY_OUT_OF_STATE => __( 'Out-of-state (not Texas sales)', 'pv-tax-reports' ),
			TaxableSalesReport::CATEGORY_MARKETPLACE  => __( 'Marketplace — Amazon etc. (provider reports)', 'pv-tax-reports' ),
		];
	}

	/**
	 * Export the requested range as CSV, if that is what was asked for:
	 * `export=csv` for every line with its order, `export=summary` for the
	 * totals.
	 */
	public function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		if ( ! isset( $_GET['page'] ) || AdminMenu::SLUG_REPORT_SALES !== $_GET['page'] ) {
			return;
		}

		$export = isset( $_GET['export'] ) ? sanitize_key( wp_unslash( $_GET['export'] ) ) : '';

		if ( 'csv' !== $export && 'summary' !== $export ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		[ $start, $end ] = $this->requested_range();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->report->for_range( $start, $end );

		if ( ! $result['ok'] ) {
			wp_die( esc_html( $result['error'] ) );
		}

		if ( 'summary' === $export ) {
			$this->export_summary( $result );
		}

		$labels = self::category_labels();
		$rows   = [];

		foreach ( $result['lines'] as $line ) {
			$detail = [];

			foreach ( $line['taxes'] as $label => $amount ) {
				$detail[] = $label . ' ' . self::money( $amount );
			}

			$rows[] = [
				$line['order_id'],
				'refund' === $line['kind'] ? $line['parent_id'] : '',
				$line['date'],
				$line['kind'],
				$line['state'],
				$line['type'],
				$line['name'],
				$labels[ $line['category'] ],
				self::money( $line['net'] ),
				self::money( $line['tax'] ),
				implode( '; ', $detail ),
				$line['note'],
			];
		}

		Csv::download(
			"texas-sales-lines-{$start}-to-{$end}.csv",
			[
				__( 'Order / refund ID', 'pv-tax-reports' ),
				__( 'Refund of order', 'pv-tax-reports' ),
				__( 'Date', 'pv-tax-reports' ),
				__( 'Kind', 'pv-tax-reports' ),
				__( 'Ship-to', 'pv-tax-reports' ),
				__( 'Line type', 'pv-tax-reports' ),
				__( 'Item', 'pv-tax-reports' ),
				__( 'Category', 'pv-tax-reports' ),
				__( 'Net sales', 'pv-tax-reports' ),
				__( 'Tax', 'pv-tax-reports' ),
				__( 'Tax by jurisdiction', 'pv-tax-reports' ),
				__( 'Note', 'pv-tax-reports' ),
			],
			$rows
		);
	}

	/**
	 * Send the totals as CSV.
	 *
	 * @param array<string, mixed> $result Report result.
	 */
	private function export_summary( array $result ): never {
		$labels = self::category_labels();
		$rows   = [
			[ __( 'Total Texas sales', 'pv-tax-reports' ), self::money( $result['totals']['total_texas_sales'] ), self::money( $result['totals']['texas_tax_collected'] ) ],
			[ __( 'Taxable sales to report (taxable + exempt sales taxed in error)', 'pv-tax-reports' ), self::money( $result['totals']['return_taxable_sales'] ), '' ],
		];

		foreach ( $result['by_category'] as $category => $row ) {
			$rows[] = [ $labels[ $category ], self::money( $row['sales'] ), self::money( $row['tax'] ) ];
		}

		$rows[] = [ '', '', '' ];

		foreach ( $result['by_jurisdiction'] as $row ) {
			/* translators: %s: jurisdiction. */
			$rows[] = [ sprintf( __( 'Jurisdiction: %s', 'pv-tax-reports' ), $row['label'] ), self::money( $row['taxable_base'] ), self::money( $row['tax'] ) ];
		}

		Csv::download(
			"texas-sales-summary-{$result['start']}-to-{$result['end']}.csv",
			[
				__( 'Line', 'pv-tax-reports' ),
				__( 'Sales', 'pv-tax-reports' ),
				__( 'Tax', 'pv-tax-reports' ),
			],
			$rows
		);
	}

	/**
	 * Format an amount for a CSV cell.
	 *
	 * @param float $amount Amount.
	 */
	private static function money( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * A Y-m-d date as e.g. "Sep 30, 2026".
	 *
	 * @param string $date Y-m-d.
	 */
	private static function long_date( string $date ): string {
		return ( new \DateTimeImmutable( $date ) )->format( 'M j, Y' );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		[ $start, $end, $range_error ] = $this->requested_range();
		$result                        = $this->report->for_range( $start, $end );
		$labels                        = self::category_labels();
		$page_url                      = admin_url( 'admin.php' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Taxable Sales', 'pv-tax-reports' ); ?></h1>

			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_REPORT_SALES ); ?>" />
				<label for="pvtax-sales-start"><?php esc_html_e( 'From', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-sales-start" name="start" value="<?php echo esc_attr( $start ); ?>" required />
				<label for="pvtax-sales-end"><?php esc_html_e( 'To', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-sales-end" name="end" value="<?php echo esc_attr( $end ); ?>" required />
				<?php submit_button( __( 'Show sales', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
			</form>

			<p>
				<?php esc_html_e( 'Quarter:', 'pv-tax-reports' ); ?>
				<?php foreach ( Dates::quarters( Dates::today(), 6 ) as $quarter ) : ?>
					<?php
					$active = $quarter['start'] === $start && $quarter['end'] === $end;
					$label  = $quarter['current']
						/* translators: %s: quarter, e.g. "Q4 2026". */
						? sprintf( __( '%s to date', 'pv-tax-reports' ), $quarter['label'] )
						: $quarter['label'];
					$quarter_url = add_query_arg(
						[
							'page'  => AdminMenu::SLUG_REPORT_SALES,
							'start' => $quarter['start'],
							'end'   => $quarter['end'],
						],
						$page_url
					);
					?>
					<a class="button button-small<?php echo $active ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $quarter_url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>

			<?php if ( null !== $range_error ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $range_error ); ?></p></div>
			<?php endif; ?>

			<h2>
				<?php
				printf(
					/* translators: 1: start date, 2: end date. */
					esc_html__( 'Showing %1$s through %2$s', 'pv-tax-reports' ),
					esc_html( self::long_date( $start ) ),
					esc_html( self::long_date( $end ) )
				);
				?>
			</h2>

			<?php if ( ! $result['ok'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php else : ?>
				<?php
				$export = static fn ( string $kind ): string => add_query_arg(
					[
						'page'   => AdminMenu::SLUG_REPORT_SALES,
						'start'  => $start,
						'end'    => $end,
						'export' => $kind,
					],
					$page_url
				);
				$totals = $result['totals'];
				?>
				<p class="description" style="max-width:56rem">
					<?php esc_html_e( 'Orders in processing, completed, or refunded, by order date; refunds against those orders net into the period the refund happened in. A product line is taxable when the product\'s WooCommerce tax status is "Taxable" — not because tax happened to be charged. Shipping and fees are taxable in proportion to the taxable items they delivered. Texas sales are direct (non-marketplace) orders delivered in Texas.', 'pv-tax-reports' ); ?>
				</p>

				<p>
					<a class="button" href="<?php echo esc_url( $export( 'csv' ) ); ?>"><?php esc_html_e( 'Export lines CSV (audit)', 'pv-tax-reports' ); ?></a>
					<a class="button" href="<?php echo esc_url( $export( 'summary' ) ); ?>"><?php esc_html_e( 'Export summary CSV', 'pv-tax-reports' ); ?></a>
				</p>

				<h2><?php esc_html_e( 'For the Texas return — website sales only', 'pv-tax-reports' ); ?></h2>
				<p class="description" style="max-width:56rem">
					<?php esc_html_e( 'Only sales made through this store. In-person (Square) and wholesale-invoice sales are not in WooCommerce and must be added from their own records before filing.', 'pv-tax-reports' ); ?>
				</p>
				<table class="widefat striped" style="max-width:44rem">
					<tbody>
						<tr>
							<th scope="row"><strong><?php esc_html_e( 'Total Texas sales', 'pv-tax-reports' ); ?></strong></th>
							<td><strong><?php echo esc_html( number_format( $totals['total_texas_sales'], 2 ) ); ?></strong></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Taxable sales', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['taxable_sales'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exempt sales on which tax was collected in error', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['exempt_taxed_sales'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exempt sales, no tax collected', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['exempt_sales'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><strong><?php esc_html_e( 'Taxable sales to report (taxable + exempt sales taxed in error)', 'pv-tax-reports' ); ?></strong></th>
							<td><strong><?php echo esc_html( number_format( $totals['return_taxable_sales'], 2 ) ); ?></strong></td>
						</tr>
						<tr>
							<th scope="row"><strong><?php esc_html_e( 'Texas tax collected (all must be remitted)', 'pv-tax-reports' ); ?></strong></th>
							<td><strong><?php echo esc_html( number_format( $totals['texas_tax_collected'], 2 ) ); ?></strong></td>
						</tr>
						<tr>
							<th scope="row">&nbsp;&nbsp;<?php esc_html_e( '— on taxable sales', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['tax_on_taxable'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row">&nbsp;&nbsp;<?php esc_html_e( '— collected in error on exempt sales', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['tax_in_error'], 2 ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'All sales by category', 'pv-tax-reports' ); ?></h2>
				<table class="widefat striped" style="max-width:44rem">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Sales', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Tax', 'pv-tax-reports' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['by_category'] as $category => $row ) : ?>
							<tr>
								<td><?php echo esc_html( $labels[ $category ] ); ?></td>
								<td><?php echo esc_html( number_format( $row['sales'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['tax'], 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gross sales (net of refunds)', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $totals['gross_sales'], 2 ) ); ?></td>
							<td></td>
						</tr>
					</tbody>
				</table>

				<?php if ( [] !== $result['by_jurisdiction'] ) : ?>
					<h2><?php esc_html_e( 'Tax collected by jurisdiction', 'pv-tax-reports' ); ?></h2>
					<p class="description" style="max-width:56rem">
						<?php esc_html_e( 'Direct sales only (marketplace tax is the marketplace\'s to remit). The base is the sales each jurisdiction\'s tax was actually charged on, labelled as recorded on the order. The effective rate should match the jurisdiction\'s rate — if it doesn\'t, check the lines CSV.', 'pv-tax-reports' ); ?>
					</p>
					<table class="widefat striped" style="max-width:50rem">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Jurisdiction', 'pv-tax-reports' ); ?></th>
								<th><?php esc_html_e( 'Sales taxed', 'pv-tax-reports' ); ?></th>
								<th><?php esc_html_e( 'Tax collected', 'pv-tax-reports' ); ?></th>
								<th><?php esc_html_e( 'Effective rate', 'pv-tax-reports' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['by_jurisdiction'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['label'] ); ?></td>
									<td><?php echo esc_html( number_format( $row['taxable_base'], 2 ) ); ?></td>
									<td><?php echo esc_html( number_format( $row['tax'], 2 ) ); ?></td>
									<td><?php echo esc_html( null === $row['rate_percent'] ? '—' : number_format( $row['rate_percent'], 2 ) . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The requested range, each end always a valid Y-m-d. With no dates
	 * given, the most recent complete calendar quarter — the period a
	 * quarterly filer is most likely here for. A date that doesn't parse is
	 * reported, never silently replaced.
	 *
	 * @return array{0:string, 1:string, 2:?string} Start, end, and a warning when a requested date was unusable.
	 */
	private function requested_range(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		$raw_start = sanitize_text_field( wp_unslash( $_GET['start'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		$raw_end = sanitize_text_field( wp_unslash( $_GET['end'] ?? '' ) );

		$last_quarter = Dates::quarters( Dates::today(), 2 )[1];
		$start        = Dates::normalize_date( $raw_start );
		$end          = Dates::normalize_date( $raw_end );
		$warning      = null;

		if ( ( '' !== $raw_start && null === $start ) || ( '' !== $raw_end && null === $end ) ) {
			$warning = __( 'A requested date was not a valid date, so the last full quarter is shown instead.', 'pv-tax-reports' );
		}

		if ( null === $start || null === $end ) {
			return [ $last_quarter['start'], $last_quarter['end'], $warning ];
		}

		return [ $start, $end, $warning ];
	}
}
