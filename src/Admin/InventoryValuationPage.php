<?php
/**
 * Inventory valuation report screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Reports\InventoryValuationReport;
use PoorVida\TaxReports\Support\Csv;
use PoorVida\TaxReports\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * What was in stock on a date, and what it was worth.
 */
final class InventoryValuationPage {

	/**
	 * Wire up the screen.
	 *
	 * @param InventoryValuationReport $report Report.
	 */
	public function __construct( private readonly InventoryValuationReport $report ) {}

	/**
	 * Hook the CSV export. Must run before any admin page output has
	 * started, so it hangs off `admin_init` rather than the page's own
	 * render() — by the time a submenu page renders, WordPress has already
	 * sent the page's own HTTP headers.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );
	}

	/**
	 * Export the requested date as CSV, if that is what was asked for.
	 */
	public function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		if ( ! isset( $_GET['page'] ) || AdminMenu::SLUG_REPORT_INVENTORY !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['export'] ) || 'csv' !== $_GET['export'] ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$date = $this->requested_date();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->report->for_date( $date );

		if ( ! $result['ok'] ) {
			wp_die( esc_html__( 'No stock data is available for that date.', 'pv-tax-reports' ) );
		}

		$rows = [];

		foreach ( $result['lines'] as $line ) {
			$rows[] = [
				$line['sku'],
				$line['name'],
				null === $line['quantity'] ? '' : $line['quantity'],
				null === $line['unit_cost'] ? 'uncosted' : number_format( $line['unit_cost'], 2, '.', '' ),
				null === $line['extended_value'] ? '' : number_format( $line['extended_value'], 2, '.', '' ),
			];
		}

		Csv::download(
			"inventory-valuation-{$date}.csv",
			[
				__( 'SKU', 'pv-tax-reports' ),
				__( 'Product', 'pv-tax-reports' ),
				__( 'Quantity', 'pv-tax-reports' ),
				__( 'Unit cost', 'pv-tax-reports' ),
				__( 'Extended value', 'pv-tax-reports' ),
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

		$date   = $this->requested_date();
		$result = $this->report->for_date( $date );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Inventory Valuation', 'pv-tax-reports' ); ?></h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_REPORT_INVENTORY ); ?>" />
				<label for="pvtax-valuation-date"><?php esc_html_e( 'As of', 'pv-tax-reports' ); ?></label>
				<input type="date" id="pvtax-valuation-date" name="date" value="<?php echo esc_attr( $date ); ?>" />
				<?php submit_button( __( 'Show valuation', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( ! $result['ok'] ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php if ( 'predates_recording' === $result['reason'] ) : ?>
							<?php if ( null === $result['earliest'] ) : ?>
								<?php esc_html_e( 'No stock history has been recorded yet at all — check that the nightly snapshot is scheduled.', 'pv-tax-reports' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: requested date, 2: first recorded date. */
									esc_html__( '%1$s predates this plugin\'s recording, which started %2$s. No total is shown for dates before that — there is no history to report.', 'pv-tax-reports' ),
									esc_html( $date ),
									esc_html( $result['earliest'] )
								);
								?>
							<?php endif; ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: requested date. */
								esc_html__( 'No snapshot was recorded for %s — the nightly snapshot may not have run that day.', 'pv-tax-reports' ),
								esc_html( $date )
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php else : ?>
				<?php
				$export_url = add_query_arg(
					[
						'page'   => AdminMenu::SLUG_REPORT_INVENTORY,
						'date'   => $date,
						'export' => 'csv',
					],
					admin_url( 'admin.php' )
				);
				?>
				<p class="description" style="max-width:52rem">
					<?php esc_html_e( "Valued at each product's cost on file as of this date — current cost from BOM as of that day, not the actual cost of the specific batch sold. A later cost change does not restate this.", 'pv-tax-reports' ); ?>
				</p>

				<p>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>">
						<?php esc_html_e( 'Export CSV', 'pv-tax-reports' ); ?>
					</a>
				</p>

				<table class="widefat striped" style="max-width:60rem">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Quantity', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Unit cost', 'pv-tax-reports' ); ?></th>
							<th><?php esc_html_e( 'Extended value', 'pv-tax-reports' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['lines'] as $line ) : ?>
							<tr>
								<td><?php echo esc_html( $line['name'] ); ?></td>
								<td><code><?php echo esc_html( $line['sku'] ); ?></code></td>
								<td><?php echo esc_html( null === $line['quantity'] ? '—' : (string) $line['quantity'] ); ?></td>
								<td>
									<?php if ( null === $line['unit_cost'] ) : ?>
										<em><?php esc_html_e( 'uncosted', 'pv-tax-reports' ); ?></em>
									<?php else : ?>
										<?php echo esc_html( number_format( $line['unit_cost'], 2 ) ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( null === $line['extended_value'] ? '—' : number_format( $line['extended_value'], 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th colspan="4" style="text-align:right"><?php esc_html_e( 'Total', 'pv-tax-reports' ); ?></th>
							<th><?php echo esc_html( number_format( $result['total'], 2 ) ); ?></th>
						</tr>
					</tfoot>
				</table>

				<?php if ( $result['uncosted_count'] > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of uncosted lines. */
								_n(
									'%d line is uncosted and excluded from the total above.',
									'%d lines are uncosted and excluded from the total above.',
									$result['uncosted_count'],
									'pv-tax-reports'
								),
								$result['uncosted_count']
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The requested date, defaulting to today, always a valid Y-m-d.
	 */
	private function requested_date(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report request, nothing is written.
		$raw = sanitize_text_field( wp_unslash( $_GET['date'] ?? '' ) );

		return Dates::normalize_date( $raw ) ?? Dates::today();
	}
}
