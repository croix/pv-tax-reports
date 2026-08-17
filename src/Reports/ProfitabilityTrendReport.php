<?php
/**
 * Monthly profitability trend.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue, cost of goods, and margin by calendar month, over a trailing
 * window — the "how are we trending" view, independent of whatever ad-hoc
 * range the product/order reports happen to be showing.
 */
final class ProfitabilityTrendReport {

	/**
	 * Wire up the report.
	 *
	 * @param ProfitabilityLines $lines Shared line-item data source.
	 */
	public function __construct( private readonly ProfitabilityLines $lines ) {}

	/**
	 * The trailing N months, oldest first, ending with the current month.
	 *
	 * @param int $months Number of calendar months to include.
	 *
	 * @return array{start:string, end:string, rows:list<array{month:string, revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>}
	 */
	public function for_trailing_months( int $months = 12 ): array {
		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-01', strtotime( $end . " -{$months} months" ) );

		$raw = iterator_to_array( $this->lines->for_range( $start, $end ), false );

		$grouped = self::aggregate_by_month( $raw );

		$rows = [];

		foreach ( $grouped as $month => $totals ) {
			$rows[] = array_merge( [ 'month' => $month ], $totals );
		}

		return [
			'start' => $start,
			'end'   => $end,
			'rows'  => $rows,
		];
	}

	/**
	 * Group lines by calendar month (Y-m) and total them, oldest first.
	 *
	 * Pure — no WordPress or database calls. Same rule as the other two
	 * profitability reports: profit is revenue minus *known* cost only.
	 *
	 * @param list<array{date:string, quantity:float, revenue:float, unit_cost:?float, cost:?float}> $lines Profitability lines.
	 *
	 * @return array<string, array{revenue:float, cost:float, uncosted_quantity:float, profit:float, margin:?float}>
	 */
	public static function aggregate_by_month( array $lines ): array {
		$totals = [];

		foreach ( $lines as $line ) {
			$month = substr( $line['date'], 0, 7 );

			if ( '' === $month ) {
				continue;
			}

			if ( ! isset( $totals[ $month ] ) ) {
				$totals[ $month ] = [
					'revenue'           => 0.0,
					'cost'              => 0.0,
					'uncosted_quantity' => 0.0,
				];
			}

			$totals[ $month ]['revenue'] += $line['revenue'];

			if ( null !== $line['cost'] ) {
				$totals[ $month ]['cost'] += $line['cost'];
			} else {
				$totals[ $month ]['uncosted_quantity'] += $line['quantity'];
			}
		}

		foreach ( $totals as &$row ) {
			$row['profit'] = $row['revenue'] - $row['cost'];
			$row['margin'] = $row['revenue'] > 0.0 ? $row['profit'] / $row['revenue'] : null;
		}

		unset( $row );

		ksort( $totals );

		return $totals;
	}
}
