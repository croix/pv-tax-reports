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
 * Gross sales, taxable sales, and tax collected for a date range.
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
	 * Export the requested range as CSV, if that is what was asked for.
	 */
	public function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		if ( ! isset( $_GET['page'] ) || AdminMenu::SLUG_REPORT_SALES !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['export'] ) || 'csv' !== $_GET['export'] ) {
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

		$rows = [];

		foreach ( $result['by_rate'] as $rate ) {
			$rows[] = [
				$rate['label'],
				number_format( $rate['taxable_sales'], 2, '.', '' ),
				number_format( $rate['tax_collected'], 2, '.', '' ),
			];
		}

		$rows[] = [
			__( 'TOTAL', 'pv-tax-reports' ),
			number_format( $result['taxable_sales'], 2, '.', '' ),
			number_format( $result['tax_collected'], 2, '.', '' ),
		];

		Csv::download(
			"taxable-sales-{$start}-to-{$end}.csv",
			[
				__( 'Rate / jurisdiction', 'pv-tax-reports' ),
				__( 'Taxable sales', 'pv-tax-reports' ),
				__( 'Tax collected', 'pv-tax-reports' ),
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
		$result          = $this->report->for_range( $start, $end );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Taxable Sales', 'pv-tax-reports' ); ?></h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_REPORT_SALES ); ?>" />
				<label for="pvtax-sales-start"><?php esc_html_e( 'From', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-sales-start" name="start" value="<?php echo esc_attr( $start ); ?>" />
				<label for="pvtax-sales-end"><?php esc_html_e( 'To', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-sales-end" name="end" value="<?php echo esc_attr( $end ); ?>" />
				<?php submit_button( __( 'Show sales', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( ! $result['ok'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php else : ?>
				<?php
				$export_url = add_query_arg(
					[
						'page'   => AdminMenu::SLUG_REPORT_SALES,
						'start'  => $start,
						'end'    => $end,
						'export' => 'csv',
					],
					admin_url( 'admin.php' )
				);
				?>
				<p class="description" style="max-width:52rem">
					<?php esc_html_e( 'Includes orders in processing or completed, and refunds against them, netted into the period the refund happened in. Cancelled and failed orders are excluded.', 'pv-tax-reports' ); ?>
				</p>

				<p>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>">
						<?php esc_html_e( 'Export CSV', 'pv-tax-reports' ); ?>
					</a>
				</p>

				<table class="widefat striped" style="max-width:40rem">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gross sales', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $result['gross_sales'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Taxable sales', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $result['taxable_sales'], 2 ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tax collected', 'pv-tax-reports' ); ?></th>
							<td><?php echo esc_html( number_format( $result['tax_collected'], 2 ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php if ( [] !== $result['by_rate'] ) : ?>
					<h2><?php esc_html_e( 'By rate / jurisdiction', 'pv-tax-reports' ); ?></h2>
					<p class="description" style="max-width:52rem">
						<?php esc_html_e( 'A sale taxed under more than one rate at once (e.g. state plus local) counts its full taxable amount under each — that mirrors how a return itself asks for sales subject to state tax and, separately, sales subject to local tax.', 'pv-tax-reports' ); ?>
					</p>
					<table class="widefat striped" style="max-width:50rem">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Rate', 'pv-tax-reports' ); ?></th>
								<th><?php esc_html_e( 'Taxable sales', 'pv-tax-reports' ); ?></th>
								<th><?php esc_html_e( 'Tax collected', 'pv-tax-reports' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['by_rate'] as $rate ) : ?>
								<tr>
									<td><?php echo esc_html( $rate['label'] ); ?></td>
									<td><?php echo esc_html( number_format( $rate['taxable_sales'], 2 ) ); ?></td>
									<td><?php echo esc_html( number_format( $rate['tax_collected'], 2 ) ); ?></td>
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
