<?php
/**
 * Product profitability for a date range.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue, cost of goods, and margin per product/variation for a range —
 * gross margin on goods sold, not overall order economics: shipping and fee
 * revenue is deliberately excluded, since this plugin has no cost basis for
 * either and mixing them in would inflate margin in a way nothing here
 * actually knows to be true.
 */
final class ProductProfitabilityReport {

	/**
	 * Wire up the report.
	 *
	 * @param ProfitabilityLines $lines Shared line-item data source.
	 */
	public function __construct( private readonly ProfitabilityLines $lines ) {}

	/**
	 * Revenue, cost, and profit per product for a range.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return array{start:string, end:string, rows:list<array{product_id:int, name:string, quantity:float, revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>}
	 */
	public function for_range( string $start, string $end ): array {
		$raw = iterator_to_array( $this->lines->for_range( $start, $end ), false );

		$grouped = self::aggregate_by_product( $raw );

		$rows = [];

		foreach ( $grouped as $product_id => $totals ) {
			$product = wc_get_product( $product_id );

			$rows[] = array_merge(
				[
					'product_id' => $product_id,
					'name'       => $product instanceof WC_Product
						? $product->get_name()
						/* translators: %d: product ID. */
						: sprintf( __( '(deleted product #%d)', 'pv-tax-reports' ), $product_id ),
				],
				$totals
			);
		}

		usort( $rows, static fn ( array $a, array $b ): int => $b['revenue'] <=> $a['revenue'] );

		return [
			'start' => $start,
			'end'   => $end,
			'rows'  => $rows,
		];
	}

	/**
	 * Group lines by product and total them.
	 *
	 * Pure — no WordPress or database calls — so the actual math (a line
	 * with no captured cost is left out of the cost sum entirely, not
	 * treated as costing zero, and margin is only computed when revenue is
	 * positive) is directly tested.
	 *
	 * profit is revenue minus *known* cost — if any quantity is uncosted,
	 * profit is a ceiling, not an exact figure, since the unknown cost is
	 * simply absent from the subtraction rather than assumed to be zero.
	 * `uncosted_quantity` is what makes that gap visible instead of silent.
	 *
	 * @param list<array{product_id:int, quantity:float, revenue:float, unit_cost:?float, cost:?float}> $lines Profitability lines.
	 *
	 * @return array<int, array{quantity:float, revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>
	 */
	public static function aggregate_by_product( array $lines ): array {
		$totals = [];

		foreach ( $lines as $line ) {
			$pid = $line['product_id'];

			if ( ! isset( $totals[ $pid ] ) ) {
				$totals[ $pid ] = [
					'quantity'          => 0.0,
					'revenue'           => 0.0,
					'cost'              => 0.0,
					'uncosted_quantity' => 0.0,
				];
			}

			$totals[ $pid ]['quantity'] += $line['quantity'];
			$totals[ $pid ]['revenue']  += $line['revenue'];

			if ( null !== $line['cost'] ) {
				$totals[ $pid ]['cost'] += $line['cost'];
			} else {
				$totals[ $pid ]['uncosted_quantity'] += $line['quantity'];
			}
		}

		foreach ( $totals as &$row ) {
			$row['profit'] = $row['revenue'] - $row['cost'];
			$row['margin'] = $row['revenue'] > 0.0 ? $row['profit'] / $row['revenue'] : null;
		}

		unset( $row );

		return $totals;
	}
}
