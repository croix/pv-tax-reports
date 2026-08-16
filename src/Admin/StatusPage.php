<?php
/**
 * Status and tools screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Cogs\OrderCogsRecorder;
use PoorVida\TaxReports\Cost\CostResolver;
use PoorVida\TaxReports\Snapshots\Scheduler;
use PoorVida\TaxReports\Snapshots\SnapshotService;
use PoorVida\TaxReports\Support\ProductPager;

defined( 'ABSPATH' ) || exit;

/**
 * Shows whether history is actually being recorded, and lets it be run by hand.
 *
 * This screen exists because the failure mode that matters here is silent: a
 * snapshotter that quietly stopped in September is only discovered at year end,
 * by which time the missing days are gone for good.
 */
final class StatusPage {

	private const NONCE = 'pvtax_snapshot_now';

	/**
	 * Build the screen.
	 *
	 * @param SnapshotService   $snapshots  Snapshot service.
	 * @param OrderCogsRecorder $order_cogs Order COGS recorder.
	 */
	public function __construct(
		private readonly SnapshotService $snapshots,
		private readonly OrderCogsRecorder $order_cogs,
	) {}

	/**
	 * Hook the manual snapshot handler.
	 */
	public function register(): void {
		add_action( 'admin_post_pvtax_snapshot_now', [ $this, 'handle_snapshot_now' ] );
	}

	/**
	 * Run a snapshot for today, on demand.
	 */
	public function handle_snapshot_now(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to run snapshots.', 'pv-tax-reports' ), 403 );
		}

		check_admin_referer( self::NONCE );

		$result = $this->snapshots->capture();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => AdminMenu::SLUG_STATUS,
					'snapshotted' => $result['products'],
					'uncosted'    => $result['uncosted'],
				],
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$repository = $this->snapshots->repository();
		$last_run   = $this->snapshots->last_run();
		$earliest   = $repository->earliest_date();
		$latest     = $repository->latest_date();
		$days       = $repository->day_count();
		$next       = Scheduler::next_scheduled();
		$cogs_stats = $this->order_cogs->repository()->stats();
		$costs      = new CostResolver();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tax Reports', 'pv-tax-reports' ); ?></h1>

			<?php $this->render_snapshot_notice(); ?>

			<?php if ( ! Scheduler::action_scheduler_available() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Action Scheduler is not available, so no nightly snapshot is scheduled. Nothing is being recorded automatically.', 'pv-tax-reports' ); ?></p>
				</div>
			<?php elseif ( null === $next ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'No nightly snapshot is scheduled. Every day this stays true is a day of stock history that cannot be reconstructed later.', 'pv-tax-reports' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Stock history', 'pv-tax-reports' ); ?></h2>

			<table class="widefat striped" style="max-width:52rem">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recording since', 'pv-tax-reports' ); ?></th>
						<td>
							<?php if ( null === $earliest ) : ?>
								<strong><?php esc_html_e( 'Never — no snapshot has been taken yet.', 'pv-tax-reports' ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( $earliest ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Most recent snapshot', 'pv-tax-reports' ); ?></th>
						<td><?php echo esc_html( $latest ?? __( '—', 'pv-tax-reports' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Days recorded', 'pv-tax-reports' ); ?></th>
						<td><?php echo esc_html( (string) $days ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next scheduled run', 'pv-tax-reports' ); ?></th>
						<td>
							<?php if ( null === $next ) : ?>
								<strong><?php esc_html_e( 'Not scheduled', 'pv-tax-reports' ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( wp_date( 'Y-m-d H:i T', $next ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Products without a cost', 'pv-tax-reports' ); ?></th>
						<td>
							<?php if ( null !== $last_run && $last_run['uncosted'] > 0 ) : ?>
								<strong><?php echo esc_html( (string) $last_run['uncosted'] ); ?></strong>
								<?php esc_html_e( '— these are recorded as uncosted, not as zero. They will show as uncosted lines on the valuation report.', 'pv-tax-reports' ); ?>
							<?php elseif ( null !== $last_run ) : ?>
								<?php esc_html_e( 'None as of the last run.', 'pv-tax-reports' ); ?>
							<?php else : ?>
								<?php esc_html_e( '—', 'pv-tax-reports' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cost source', 'pv-tax-reports' ); ?></th>
						<td>
							<?php echo esc_html( $costs->describe_source() ); ?>
							—
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG_SYNC ) ); ?>"><?php esc_html_e( 'sync costs from BOM', 'pv-tax-reports' ); ?></a>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
				<input type="hidden" name="action" value="pvtax_snapshot_now" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php submit_button( __( 'Snapshot now', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:.5rem">
					<?php esc_html_e( 'Records today. Safe to run more than once — it overwrites today and leaves every other day alone.', 'pv-tax-reports' ); ?>
				</span>
			</form>

			<h2><?php esc_html_e( 'Cost of goods captured at sale', 'pv-tax-reports' ); ?></h2>

			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Each order line records what it cost on the day it sold. Later cost changes update the product for future sales and never restate these — which is what keeps a filed year\'s numbers from moving underneath you.', 'pv-tax-reports' ); ?>
			</p>

			<table class="widefat striped" style="max-width:52rem">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Orders captured', 'pv-tax-reports' ); ?></th>
						<td><?php echo esc_html( (string) $cogs_stats['orders'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lines captured', 'pv-tax-reports' ); ?></th>
						<td><?php echo esc_html( (string) $cogs_stats['lines'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lines with no cost on file', 'pv-tax-reports' ); ?></th>
						<td>
							<?php echo esc_html( (string) $cogs_stats['uncosted'] ); ?>
							<?php if ( $cogs_stats['uncosted'] > 0 ) : ?>
								<em><?php esc_html_e( '— sold before a cost was set on the product.', 'pv-tax-reports' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Reports', 'pv-tax-reports' ); ?></h2>
			<p><?php esc_html_e( 'Inventory valuation and taxable sales are not built yet. Stock history and sale-time costs are being recorded now so those reports have something to read when they arrive.', 'pv-tax-reports' ); ?></p>

			<h2><?php esc_html_e( 'Diagnostics', 'pv-tax-reports' ); ?></h2>
			<p class="description" style="max-width:52rem">
				<?php esc_html_e( "Added to track down a report of costed products showing as uncosted on the sync preview. Shows exactly what WooCommerce's COGS API and raw product meta return for a few real products, next to what this plugin resolves. Safe to ignore once that's sorted out.", 'pv-tax-reports' ); ?>
			</p>

			<table class="widefat striped" style="max-width:40rem">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce version', 'pv-tax-reports' ); ?></th>
						<td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'unknown', 'pv-tax-reports' ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<table class="widefat striped" style="max-width:70rem;margin-top:.5rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'get_cogs_value() raw', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Raw _cogs_total_value meta', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Plugin resolves to', 'pv-tax-reports' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->sample_products( $costs ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><code><?php echo esc_html( $row['sku'] ); ?></code></td>
							<td><code><?php echo esc_html( $row['raw_cogs_value'] ); ?></code></td>
							<td><code><?php echo esc_html( $row['raw_meta'] ); ?></code></td>
							<td><?php echo esc_html( $row['resolved'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A handful of real products with a SKU, showing exactly what
	 * WooCommerce's COGS API and raw product meta return, next to what the
	 * plugin resolves for the same product.
	 *
	 * @param CostResolver $costs Resolver.
	 * @param int          $limit Maximum rows to gather.
	 *
	 * @return list<array{name:string, sku:string, raw_cogs_value:string, raw_meta:string, resolved:string}>
	 */
	private function sample_products( CostResolver $costs, int $limit = 8 ): array {
		$rows          = [];
		$api_available = $costs->cogs_api_available();

		foreach ( ProductPager::each() as $product ) {
			if ( '' === $product->get_sku() ) {
				continue;
			}

			if ( $api_available && method_exists( $product, 'get_cogs_value' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Deliberately exposing the raw value; this table exists to show it.
				$raw_cogs_value = var_export( $product->get_cogs_value(), true );
			} else {
				$raw_cogs_value = __( 'n/a (API unavailable)', 'pv-tax-reports' );
			}

			$raw_meta = $product->get_meta( '_cogs_total_value', true );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Deliberately exposing the raw value; this table exists to show it.
			$raw_meta = '' === $raw_meta ? __( '(no meta row)', 'pv-tax-reports' ) : var_export( $raw_meta, true );

			$resolved      = $costs->for_product( $product );
			$resolved_text = null === $resolved['cost']
				? __( 'null (uncosted)', 'pv-tax-reports' )
				: number_format( $resolved['cost'], 2 ) . ' (' . $resolved['source'] . ')';

			$rows[] = [
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'raw_cogs_value' => (string) $raw_cogs_value,
				'raw_meta'       => (string) $raw_meta,
				'resolved'       => $resolved_text,
			];

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Success notice after a manual run.
	 */
	private function render_snapshot_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags on a redirect target.
		if ( ! isset( $_GET['snapshotted'] ) ) {
			return;
		}

		$products = absint( wp_unslash( $_GET['snapshotted'] ) );
		$uncosted = absint( wp_unslash( $_GET['uncosted'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: product count, 2: uncosted count. */
					_n(
						'Snapshot taken: %1$d product recorded, %2$d without a cost.',
						'Snapshot taken: %1$d products recorded, %2$d without a cost.',
						$products,
						'pv-tax-reports'
					),
					$products,
					$uncosted
				)
			)
		);
	}
}
