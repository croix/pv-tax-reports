<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Reports\ProfitabilityTrendReport;

/**
 * @covers \PoorVida\TaxReports\Reports\ProfitabilityTrendReport
 */
final class ProfitabilityTrendReportTest extends TestCase {

	private function line( string $date, float $quantity, float $revenue, ?float $cost ): array {
		return [
			'date'      => $date,
			'quantity'  => $quantity,
			'revenue'   => $revenue,
			'unit_cost' => null,
			'cost'      => $cost,
		];
	}

	public function test_it_groups_by_calendar_month(): void {
		$result = ProfitabilityTrendReport::aggregate_by_month(
			[
				$this->line( '2026-01-05', 1.0, 10.0, 4.0 ),
				$this->line( '2026-01-20', 1.0, 5.0, 2.0 ),
				$this->line( '2026-02-01', 1.0, 20.0, 8.0 ),
			]
		);

		$this->assertSame( 15.0, $result['2026-01']['revenue'] );
		$this->assertSame( 20.0, $result['2026-02']['revenue'] );
	}

	public function test_months_come_back_in_chronological_order(): void {
		$result = ProfitabilityTrendReport::aggregate_by_month(
			[
				$this->line( '2026-03-01', 1.0, 1.0, 0.0 ),
				$this->line( '2026-01-01', 1.0, 1.0, 0.0 ),
				$this->line( '2026-02-01', 1.0, 1.0, 0.0 ),
			]
		);

		$this->assertSame( [ '2026-01', '2026-02', '2026-03' ], array_keys( $result ) );
	}

	public function test_an_uncosted_line_is_flagged_not_zeroed(): void {
		$result = ProfitabilityTrendReport::aggregate_by_month(
			[ $this->line( '2026-01-05', 2.0, 20.0, null ) ]
		);

		$this->assertSame( 0.0, $result['2026-01']['cost'] );
		$this->assertSame( 20.0, $result['2026-01']['profit'] );
		$this->assertSame( 2.0, $result['2026-01']['uncosted_quantity'] );
	}

	public function test_a_line_with_no_date_is_skipped(): void {
		$result = ProfitabilityTrendReport::aggregate_by_month(
			[ $this->line( '', 1.0, 10.0, 4.0 ) ]
		);

		$this->assertSame( [], $result );
	}
}
