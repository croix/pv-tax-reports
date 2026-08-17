<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Reports\OrderProfitabilityReport;

/**
 * @covers \PoorVida\TaxReports\Reports\OrderProfitabilityReport
 */
final class OrderProfitabilityReportTest extends TestCase {

	private function line( int $order_id, string $order_number, string $date, float $quantity, float $revenue, ?float $cost ): array {
		return [
			'order_id'     => $order_id,
			'order_number' => $order_number,
			'date'         => $date,
			'quantity'     => $quantity,
			'revenue'      => $revenue,
			'unit_cost'    => null,
			'cost'         => $cost,
		];
	}

	public function test_it_totals_multiple_lines_on_the_same_order(): void {
		$result = OrderProfitabilityReport::aggregate_by_order(
			[
				$this->line( 1, '1001', '2026-01-05', 2.0, 40.0, 16.0 ),
				$this->line( 1, '1001', '2026-01-05', 1.0, 15.0, 5.0 ),
			]
		);

		$this->assertSame( 55.0, $result[1]['revenue'] );
		$this->assertSame( 21.0, $result[1]['cost'] );
		$this->assertSame( 34.0, $result[1]['profit'] );
		$this->assertSame( '1001', $result[1]['order_number'] );
		$this->assertSame( '2026-01-05', $result[1]['date'] );
	}

	public function test_an_uncosted_line_is_flagged_not_zeroed(): void {
		$result = OrderProfitabilityReport::aggregate_by_order(
			[ $this->line( 1, '1001', '2026-01-05', 3.0, 30.0, null ) ]
		);

		$this->assertSame( 0.0, $result[1]['cost'] );
		$this->assertSame( 30.0, $result[1]['profit'] );
		$this->assertSame( 3.0, $result[1]['uncosted_quantity'] );
	}

	public function test_orders_are_kept_separate(): void {
		$result = OrderProfitabilityReport::aggregate_by_order(
			[
				$this->line( 1, '1001', '2026-01-05', 1.0, 10.0, 4.0 ),
				$this->line( 2, '1002', '2026-01-06', 1.0, 20.0, 5.0 ),
			]
		);

		$this->assertSame( 6.0, $result[1]['profit'] );
		$this->assertSame( 15.0, $result[2]['profit'] );
	}
}
