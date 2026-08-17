<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Reports\ProductProfitabilityReport;

/**
 * @covers \PoorVida\TaxReports\Reports\ProductProfitabilityReport
 */
final class ProductProfitabilityReportTest extends TestCase {

	private function line( int $product_id, float $quantity, float $revenue, ?float $cost ): array {
		return [
			'product_id' => $product_id,
			'quantity'   => $quantity,
			'revenue'    => $revenue,
			'unit_cost'  => null,
			'cost'       => $cost,
		];
	}

	public function test_it_totals_revenue_cost_and_profit_per_product(): void {
		$result = ProductProfitabilityReport::aggregate_by_product(
			[ $this->line( 1, 4.0, 100.0, 40.0 ) ]
		);

		$this->assertSame( 4.0, $result[1]['quantity'] );
		$this->assertSame( 100.0, $result[1]['revenue'] );
		$this->assertSame( 40.0, $result[1]['cost'] );
		$this->assertSame( 60.0, $result[1]['profit'] );
		$this->assertSame( 0.6, $result[1]['margin'] );
		$this->assertSame( 0.0, $result[1]['uncosted_quantity'] );
	}

	/**
	 * An uncosted line's quantity is called out, and its cost is simply
	 * absent from the sum — profit is a ceiling, never an assumed-zero-cost
	 * figure that would overstate margin without saying so.
	 */
	public function test_an_uncosted_line_is_flagged_not_zeroed(): void {
		$result = ProductProfitabilityReport::aggregate_by_product(
			[
				$this->line( 1, 3.0, 60.0, 20.0 ),
				$this->line( 1, 2.0, 40.0, null ),
			]
		);

		$this->assertSame( 100.0, $result[1]['revenue'] );
		$this->assertSame( 20.0, $result[1]['cost'] );
		$this->assertSame( 80.0, $result[1]['profit'] );
		$this->assertSame( 2.0, $result[1]['uncosted_quantity'] );
	}

	public function test_margin_is_null_when_revenue_is_zero(): void {
		$result = ProductProfitabilityReport::aggregate_by_product(
			[ $this->line( 1, 1.0, 0.0, 0.0 ) ]
		);

		$this->assertNull( $result[1]['margin'] );
	}

	public function test_multiple_products_are_kept_separate(): void {
		$result = ProductProfitabilityReport::aggregate_by_product(
			[
				$this->line( 1, 1.0, 10.0, 4.0 ),
				$this->line( 2, 1.0, 20.0, 5.0 ),
			]
		);

		$this->assertSame( 6.0, $result[1]['profit'] );
		$this->assertSame( 15.0, $result[2]['profit'] );
	}

	public function test_an_empty_list_produces_no_rows(): void {
		$this->assertSame( [], ProductProfitabilityReport::aggregate_by_product( [] ) );
	}
}
