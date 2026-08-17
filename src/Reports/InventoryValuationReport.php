<?php
/**
 * Inventory valuation as of a date.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

use PoorVida\TaxReports\Snapshots\StockSnapshotRepository;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * What was in stock on a given date, and what it was worth.
 *
 * Reads the day's frozen snapshot rather than re-resolving today's live cost:
 * `unit_cost` was copied in at snapshot time specifically so a later cost
 * change can't restate a past valuation. "Current cost from BOM" in the
 * report means current as of the day being valued, not current as of the
 * day the report is run.
 */
final class InventoryValuationReport {

	/**
	 * Wire up the report.
	 *
	 * @param StockSnapshotRepository $snapshots Snapshot storage.
	 */
	public function __construct( private readonly StockSnapshotRepository $snapshots ) {}

	/**
	 * Value stock as of a date.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array{ok:true, date:string, lines:list<array{product_id:int, sku:string, name:string, quantity:?float, unit_cost:?float, extended_value:?float}>, total:float, uncosted_count:int}|array{ok:false, reason:'predates_recording'|'no_snapshot', date:string, earliest:?string}
	 */
	public function for_date( string $date ): array {
		$earliest = $this->snapshots->earliest_date();

		if ( null === $earliest || $date < $earliest ) {
			return [
				'ok'       => false,
				'reason'   => 'predates_recording',
				'date'     => $date,
				'earliest' => $earliest,
			];
		}

		$rows = $this->snapshots->for_date( $date );

		if ( [] === $rows ) {
			return [
				'ok'       => false,
				'reason'   => 'no_snapshot',
				'date'     => $date,
				'earliest' => $earliest,
			];
		}

		$raw_lines = [];

		foreach ( $rows as $row ) {
			$raw_lines[] = [
				'product_id' => (int) $row->product_id,
				'sku'        => (string) $row->sku,
				'quantity'   => null === $row->quantity ? null : (float) $row->quantity,
				'unit_cost'  => null === $row->unit_cost ? null : (float) $row->unit_cost,
			];
		}

		$computed = self::compute( $raw_lines );

		foreach ( $computed['lines'] as &$line ) {
			$product = wc_get_product( $line['product_id'] );

			$line['name'] = $product instanceof WC_Product
				? $product->get_name()
				/* translators: %d: product ID. */
				: sprintf( __( '(deleted product #%d)', 'pv-tax-reports' ), $line['product_id'] );
		}

		unset( $line );

		return [
			'ok'             => true,
			'date'           => $date,
			'lines'          => $computed['lines'],
			'total'          => $computed['total'],
			'uncosted_count' => $computed['uncosted_count'],
		];
	}

	/**
	 * Extend quantity by unit cost per line and total the costed ones.
	 *
	 * Pure — no WordPress or database calls — so the one thing worth getting
	 * exactly right here (never valuing an uncosted line at zero) can be
	 * tested directly.
	 *
	 * @param list<array{product_id:int, sku:string, quantity:?float, unit_cost:?float}> $rows Snapshot rows.
	 *
	 * @return array{lines:list<array{product_id:int, sku:string, quantity:?float, unit_cost:?float, extended_value:?float}>, total:float, uncosted_count:int}
	 */
	public static function compute( array $rows ): array {
		$lines    = [];
		$total    = 0.0;
		$uncosted = 0;

		foreach ( $rows as $row ) {
			$extended = ( null !== $row['quantity'] && null !== $row['unit_cost'] )
				? $row['quantity'] * $row['unit_cost']
				: null;

			if ( null === $row['unit_cost'] ) {
				++$uncosted;
			} else {
				$total += $extended ?? 0.0;
			}

			$lines[] = array_merge( $row, [ 'extended_value' => $extended ] );
		}

		return [
			'lines'          => $lines,
			'total'          => $total,
			'uncosted_count' => $uncosted,
		];
	}
}
