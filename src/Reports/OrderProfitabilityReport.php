<?php
/**
 * Order profitability for a date range.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue, cost of goods, and margin per order for a range — same "gross
 * margin on goods, not overall order economics" scope as the product report:
 * shipping and fee revenue is excluded, since there is no cost basis for
 * either here.
 */
final class OrderProfitabilityReport {

	/**
	 * Wire up the report.
	 *
	 * @param ProfitabilityLines $lines Shared line-item data source.
	 */
	public function __construct( private readonly ProfitabilityLines $lines ) {}

	/**
	 * Revenue, cost, and profit per order for a range.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return array{start:string, end:string, rows:list<array{order_id:int, order_number:string, date:string, revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>}
	 */
	public function for_range( string $start, string $end ): array {
		$raw = iterator_to_array( $this->lines->for_range( $start, $end ), false );

		$grouped = self::aggregate_by_order( $raw );

		$rows = [];

		foreach ( $grouped as $order_id => $totals ) {
			$rows[] = array_merge( [ 'order_id' => $order_id ], $totals );
		}

		usort( $rows, static fn ( array $a, array $b ): int => $a['date'] <=> $b['date'] );

		return [
			'start' => $start,
			'end'   => $end,
			'rows'  => $rows,
		];
	}

	/**
	 * Group lines by order and total them.
	 *
	 * Pure — no WordPress or database calls. Same rule as the product
	 * report: profit is revenue minus *known* cost, so it is a ceiling
	 * rather than an exact figure whenever `uncosted_quantity` is nonzero.
	 *
	 * @param list<array{order_id:int, order_number:string, date:string, quantity:float, revenue:float, unit_cost:?float, cost:?float}> $lines Profitability lines.
	 *
	 * @return array<int, array{order_number:string, date:string, revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>
	 */
	public static function aggregate_by_order( array $lines ): array {
		$totals = [];

		foreach ( $lines as $line ) {
			$oid = $line['order_id'];

			if ( ! isset( $totals[ $oid ] ) ) {
				$totals[ $oid ] = [
					'order_number'      => $line['order_number'],
					'date'              => $line['date'],
					'revenue'           => 0.0,
					'cost'              => 0.0,
					'uncosted_quantity' => 0.0,
				];
			}

			$totals[ $oid ]['revenue'] += $line['revenue'];

			if ( null !== $line['cost'] ) {
				$totals[ $oid ]['cost'] += $line['cost'];
			} else {
				$totals[ $oid ]['uncosted_quantity'] += $line['quantity'];
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
