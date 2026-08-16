<?php
/**
 * Daily stock snapshotting.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Snapshots;

use PoorVida\TaxReports\Cost\CostResolver;
use PoorVida\TaxReports\Support\Dates;
use PoorVida\TaxReports\Support\ProductPager;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Records what was on the shelf today, and what it cost today.
 *
 * WooCommerce keeps no stock ledger — `_stock` is a single current value — so
 * "what was in stock on 12/31" is unanswerable retroactively. This is the only
 * thing that makes it answerable, and it only covers days on which it ran.
 */
final class SnapshotService {

	public const LAST_RUN_OPTION = 'pvtax_last_snapshot';

	/**
	 * Products passed over during the current run because they do not manage
	 * stock. Counted so the tools screen can say "25 recorded, 4 not tracking
	 * stock" rather than leaving the difference unexplained.
	 *
	 * @var int
	 */
	private int $skipped = 0;

	/**
	 * Wire up the snapshotter.
	 *
	 * @param StockSnapshotRepository $repository Snapshot storage.
	 * @param CostResolver            $costs      Unit cost lookup.
	 */
	public function __construct(
		private readonly StockSnapshotRepository $repository,
		private readonly CostResolver $costs,
	) {}

	/**
	 * Hook the Action Scheduler callback.
	 */
	public function register(): void {
		add_action( Scheduler::HOOK, [ $this, 'run_scheduled' ] );
	}

	/**
	 * Action Scheduler entry point.
	 */
	public function run_scheduled(): void {
		$this->capture( Dates::today() );
	}

	/**
	 * Snapshot every stock-managed product for a date.
	 *
	 * @param string|null $date Y-m-d, defaulting to today in site time.
	 *
	 * @return array{date:string, products:int, uncosted:int, skipped:int, written:int}
	 */
	public function capture( ?string $date = null ): array {
		$date = $date ?? Dates::today();

		$rows     = [];
		$uncosted = 0;

		$this->skipped = 0;

		foreach ( $this->stock_managed_products() as $product ) {
			$cost = $this->costs->for_product( $product );

			if ( null === $cost['cost'] ) {
				++$uncosted;
			}

			$quantity = $product->get_stock_quantity();

			$rows[] = [
				'product_id'  => (int) $product->get_id(),
				'sku'         => (string) $product->get_sku(),
				'quantity'    => null === $quantity ? null : (float) $quantity,
				'unit_cost'   => $cost['cost'],
				'cost_source' => $cost['source'],
			];
		}

		$written = $this->repository->upsert_day( $date, $rows );

		$result = [
			'date'     => $date,
			'products' => count( $rows ),
			'uncosted' => $uncosted,
			'skipped'  => $this->skipped,
			'written'  => $written,
		];

		update_option(
			self::LAST_RUN_OPTION,
			[
				'date'     => $date,
				'ran_at'   => Dates::now_utc(),
				'products' => $result['products'],
				'uncosted' => $result['uncosted'],
			],
			false
		);

		/**
		 * Fires after a daily snapshot completes.
		 *
		 * @param array{date:string, products:int, uncosted:int, skipped:int, written:int} $result Run summary.
		 */
		do_action( 'pvtax_snapshot_captured', $result );

		return $result;
	}

	/**
	 * Every product and variation that manages its own stock.
	 *
	 * A variation whose parent manages stock reports `'parent'` rather than
	 * `true`, so the strict check keeps the quantity counted once — against the
	 * parent — instead of once per variation.
	 *
	 * @return iterable<WC_Product>
	 */
	private function stock_managed_products(): iterable {
		foreach ( ProductPager::each() as $product ) {
			if ( true !== $product->get_manage_stock() ) {
				++$this->skipped;

				continue;
			}

			yield $product;
		}
	}

	/**
	 * Summary of the last run, or null if it has never run.
	 *
	 * @return array{date:string, ran_at:string, products:int, uncosted:int}|null
	 */
	public function last_run(): ?array {
		$stored = get_option( self::LAST_RUN_OPTION );

		return is_array( $stored ) && isset( $stored['date'] ) ? $stored : null;
	}

	/**
	 * Repository accessor, for the admin screens.
	 */
	public function repository(): StockSnapshotRepository {
		return $this->repository;
	}
}
