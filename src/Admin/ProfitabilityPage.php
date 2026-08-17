<?php
/**
 * Profitability report screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Reports\OrderProfitabilityReport;
use PoorVida\TaxReports\Reports\ProductProfitabilityReport;
use PoorVida\TaxReports\Reports\ProfitabilityTrendReport;
use PoorVida\TaxReports\Support\Csv;
use PoorVida\TaxReports\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue, cost of goods, and margin — by product, by order, and trending
 * by month.
 *
 * Gross margin on goods sold, not overall order economics: shipping and fee
 * revenue is out of scope everywhere on this screen, since this plugin has
 * no cost basis for either. Wherever some quantity is uncosted, profit is
 * shown as a ceiling (revenue minus *known* cost) rather than refusing to
 * show a number at all — the uncosted quantity is surfaced explicitly so
 * that ceiling is never mistaken for an exact figure.
 */
final class ProfitabilityPage {

	private const TRAILING_MONTHS = 12;

	/**
	 * Wire up the screen.
	 *
	 * @param ProductProfitabilityReport $product_report Product profitability.
	 * @param OrderProfitabilityReport   $order_report   Order profitability.
	 * @param ProfitabilityTrendReport   $trend_report   Monthly trend.
	 */
	public function __construct(
		private readonly ProductProfitabilityReport $product_report,
		private readonly OrderProfitabilityReport $order_report,
		private readonly ProfitabilityTrendReport $trend_report,
	) {}

	/**
	 * Hook the CSV export. Runs on `admin_init`, before any admin page
	 * output has started — a submenu page's own render() runs too late to
	 * send file headers.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );
	}

	/**
	 * Export the requested view as CSV, if that is what was asked for.
	 */
	public function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		if ( ! isset( $_GET['page'] ) || AdminMenu::SLUG_REPORT_PROFITABILITY !== $_GET['page'] ) {
			return;
		}

		$view = isset( $_GET['export'] ) ? sanitize_text_field( wp_unslash( $_GET['export'] ) ) : '';

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		[ $start, $end ] = $this->requested_range();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'product' === $view ) {
			$this->export_product( $start, $end );
		} elseif ( 'order' === $view ) {
			$this->export_order( $start, $end );
		} elseif ( 'trend' === $view ) {
			$this->export_trend();
		}
	}

	/**
	 * Export the product-profitability view.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 */
	private function export_product( string $start, string $end ): void {
		$result = $this->product_report->for_range( $start, $end );

		$rows = [];

		foreach ( $result['rows'] as $row ) {
			$rows[] = [
				$row['name'],
				$row['quantity'],
				number_format( $row['revenue'], 2, '.', '' ),
				number_format( $row['cost'], 2, '.', '' ),
				number_format( $row['profit'], 2, '.', '' ),
				null === $row['margin'] ? '' : number_format( $row['margin'] * 100, 1, '.', '' ),
				$row['uncosted_quantity'],
			];
		}

		Csv::download(
			"product-profitability-{$start}-to-{$end}.csv",
			[
				__( 'Product', 'pv-tax-reports' ),
				__( 'Quantity', 'pv-tax-reports' ),
				__( 'Revenue', 'pv-tax-reports' ),
				__( 'Cost (known)', 'pv-tax-reports' ),
				__( 'Profit (ceiling)', 'pv-tax-reports' ),
				__( 'Margin %', 'pv-tax-reports' ),
				__( 'Uncosted quantity', 'pv-tax-reports' ),
			],
			$rows
		);
	}

	/**
	 * Export the order-profitability view.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 */
	private function export_order( string $start, string $end ): void {
		$result = $this->order_report->for_range( $start, $end );

		$rows = [];

		foreach ( $result['rows'] as $row ) {
			$rows[] = [
				$row['order_number'],
				$row['date'],
				number_format( $row['revenue'], 2, '.', '' ),
				number_format( $row['cost'], 2, '.', '' ),
				number_format( $row['profit'], 2, '.', '' ),
				null === $row['margin'] ? '' : number_format( $row['margin'] * 100, 1, '.', '' ),
			];
		}

		Csv::download(
			"order-profitability-{$start}-to-{$end}.csv",
			[
				__( 'Order', 'pv-tax-reports' ),
				__( 'Date', 'pv-tax-reports' ),
				__( 'Revenue', 'pv-tax-reports' ),
				__( 'Cost (known)', 'pv-tax-reports' ),
				__( 'Profit (ceiling)', 'pv-tax-reports' ),
				__( 'Margin %', 'pv-tax-reports' ),
			],
			$rows
		);
	}

	/**
	 * Export the trend view.
	 */
	private function export_trend(): void {
		$result = $this->trend_report->for_trailing_months( self::TRAILING_MONTHS );

		$rows = [];

		foreach ( $result['rows'] as $row ) {
			$rows[] = [
				$row['month'],
				number_format( $row['revenue'], 2, '.', '' ),
				number_format( $row['cost'], 2, '.', '' ),
				number_format( $row['profit'], 2, '.', '' ),
				null === $row['margin'] ? '' : number_format( $row['margin'] * 100, 1, '.', '' ),
			];
		}

		Csv::download(
			"profitability-trend-{$result['start']}-to-{$result['end']}.csv",
			[
				__( 'Month', 'pv-tax-reports' ),
				__( 'Revenue', 'pv-tax-reports' ),
				__( 'Cost (known)', 'pv-tax-reports' ),
				__( 'Profit (ceiling)', 'pv-tax-reports' ),
				__( 'Margin %', 'pv-tax-reports' ),
			],
			$rows
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		[ $start, $end ] = $this->requested_range();

		$product_result = $this->product_report->for_range( $start, $end );
		$order_result   = $this->order_report->for_range( $start, $end );
		$trend_result   = $this->trend_report->for_trailing_months( self::TRAILING_MONTHS );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Profitability', 'pv-tax-reports' ); ?></h1>

			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Gross margin on goods sold, not overall order economics — shipping and fee revenue is left out everywhere here, since there is no cost basis for either. Where some quantity has no captured cost, profit shown is a ceiling (revenue minus known cost only), and the uncosted quantity is called out so that ceiling is never mistaken for an exact figure.', 'pv-tax-reports' ); ?>
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_REPORT_PROFITABILITY ); ?>" />
				<label for="pvtax-profit-start"><?php esc_html_e( 'From', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-profit-start" name="start" value="<?php echo esc_attr( $start ); ?>" />
				<label for="pvtax-profit-end"><?php esc_html_e( 'To', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-profit-end" name="end" value="<?php echo esc_attr( $end ); ?>" />
				<?php submit_button( __( 'Show profitability', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'By product', 'pv-tax-reports' ); ?></h2>

			<p>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'product', $start, $end ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'pv-tax-reports' ); ?>
				</a>
			</p>

			<?php if ( [] === $product_result['rows'] ) : ?>
				<p><?php esc_html_e( 'No product sales in this range.', 'pv-tax-reports' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:70rem">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Quantity', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Revenue', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Profit', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Margin', 'pv-tax-reports' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $product_result['rows'] as $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $row['name'] ); ?>
									<?php if ( $row['uncosted_quantity'] > 0.0 ) : ?>
										<br />
										<em class="description">
											<?php
											printf(
												/* translators: %s: uncosted quantity. */
												esc_html__( '%s units uncosted — profit understated', 'pv-tax-reports' ),
												esc_html( (string) $row['uncosted_quantity'] )
											);
											?>
										</em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $row['quantity'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['revenue'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['cost'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['profit'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['margin'] ? '—' : number_format( $row['margin'] * 100, 1 ) . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'By order', 'pv-tax-reports' ); ?></h2>

			<p>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'order', $start, $end ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'pv-tax-reports' ); ?>
				</a>
			</p>

			<?php if ( [] === $order_result['rows'] ) : ?>
				<p><?php esc_html_e( 'No orders in this range.', 'pv-tax-reports' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Date', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Revenue', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Profit', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Margin', 'pv-tax-reports' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order_result['rows'] as $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $row['order_number'] ); ?>
									<?php if ( $row['uncosted_quantity'] > 0.0 ) : ?>
										<br /><em class="description"><?php esc_html_e( 'includes uncosted lines — profit understated', 'pv-tax-reports' ); ?></em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['date'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['revenue'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['cost'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['profit'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['margin'] ? '—' : number_format( $row['margin'] * 100, 1 ) . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Trend (trailing 12 months)', 'pv-tax-reports' ); ?></h2>

			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Independent of the date range above — always the trailing 12 calendar months, for an at-a-glance sense of direction.', 'pv-tax-reports' ); ?>
			</p>

			<p>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'trend', $start, $end ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'pv-tax-reports' ); ?>
				</a>
			</p>

			<?php if ( [] === $trend_result['rows'] ) : ?>
				<p><?php esc_html_e( 'No sales in the trailing 12 months.', 'pv-tax-reports' ); ?></p>
			<?php else : ?>
				<?php
				$max_revenue = array_reduce( $trend_result['rows'], static fn ( float $carry, array $row ): float => max( $carry, $row['revenue'] ), 0.0 );
				?>
				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Month', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Revenue', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Profit', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Margin', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Revenue trend', 'pv-tax-reports' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $trend_result['rows'] as $row ) : ?>
							<?php $bar_pct = $max_revenue > 0.0 ? min( 100.0, ( $row['revenue'] / $max_revenue ) * 100.0 ) : 0.0; ?>
							<tr>
								<td><?php echo esc_html( $row['month'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['revenue'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['cost'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $row['profit'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['margin'] ? '—' : number_format( $row['margin'] * 100, 1 ) . '%' ); ?></td>
								<td style="min-width:8rem">
									<div style="background:currentColor;opacity:.25;height:1em;width:<?php echo esc_attr( (string) $bar_pct ); ?>%"></div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a CSV export URL for one of the three views.
	 *
	 * @param string $view  'product', 'order', or 'trend'.
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 */
	private function export_url( string $view, string $start, string $end ): string {
		return add_query_arg(
			[
				'page'   => AdminMenu::SLUG_REPORT_PROFITABILITY,
				'start'  => $start,
				'end'    => $end,
				'export' => $view,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The requested range, defaulting to month-to-date, each end always a
	 * valid Y-m-d.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function requested_range(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		$raw_start = sanitize_text_field( wp_unslash( $_GET['start'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		$raw_end = sanitize_text_field( wp_unslash( $_GET['end'] ?? '' ) );

		$today = Dates::today();
		$start = Dates::normalize_date( $raw_start ) ?? gmdate( 'Y-m-01', strtotime( $today ) );
		$end   = Dates::normalize_date( $raw_end ) ?? $today;

		return [ $start, $end ];
	}
}
