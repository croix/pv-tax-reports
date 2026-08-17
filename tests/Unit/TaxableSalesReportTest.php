<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Reports\TaxableSalesReport;

/**
 * @covers \PoorVida\TaxReports\Reports\TaxableSalesReport
 */
final class TaxableSalesReportTest extends TestCase {

	/**
	 * @return array{gross_sales:float, taxable_sales:float, tax_collected:float, by_rate:array<int|string, array{taxable_sales:float, tax_collected:float}>}
	 */
	private function empty_totals(): array {
		return [
			'gross_sales'   => 0.0,
			'taxable_sales' => 0.0,
			'tax_collected' => 0.0,
			'by_rate'       => [],
		];
	}

	/**
	 * A tax-exempt line is real revenue, but not a taxable-sales line — the
	 * exact distinction the return itself needs.
	 */
	public function test_an_untaxed_line_counts_toward_gross_but_not_taxable(): void {
		$totals = TaxableSalesReport::accumulate( 100.0, [], $this->empty_totals() );

		$this->assertSame( 100.0, $totals['gross_sales'] );
		$this->assertSame( 0.0, $totals['taxable_sales'] );
		$this->assertSame( 0.0, $totals['tax_collected'] );
		$this->assertSame( [], $totals['by_rate'] );
	}

	public function test_a_taxed_line_counts_toward_gross_taxable_and_its_rate(): void {
		$totals = TaxableSalesReport::accumulate( 100.0, [ 1 => 7.5 ], $this->empty_totals() );

		$this->assertSame( 100.0, $totals['gross_sales'] );
		$this->assertSame( 100.0, $totals['taxable_sales'] );
		$this->assertSame( 7.5, $totals['tax_collected'] );
		$this->assertSame(
			[
				'taxable_sales' => 100.0,
				'tax_collected' => 7.5,
			],
			$totals['by_rate'][1]
		);
	}

	/**
	 * A rate entry present but zero (e.g. a zero-rate jurisdiction) must not
	 * count the line as taxed — scope calls for lines that "actually
	 * attracted tax".
	 */
	public function test_a_zero_tax_entry_does_not_count_as_taxed(): void {
		$totals = TaxableSalesReport::accumulate( 100.0, [ 1 => 0.0 ], $this->empty_totals() );

		$this->assertSame( 0.0, $totals['taxable_sales'] );
		$this->assertSame( 0.0, $totals['tax_collected'] );
		$this->assertArrayNotHasKey( 1, $totals['by_rate'] );
	}

	/**
	 * WooCommerce stores refund amounts as negative numbers already, so
	 * netting a refund is just addition — no special-casing required.
	 */
	public function test_a_refund_nets_out_by_plain_addition(): void {
		$totals = $this->empty_totals();
		$totals = TaxableSalesReport::accumulate( 100.0, [ 1 => 7.5 ], $totals );
		$totals = TaxableSalesReport::accumulate( -100.0, [ 1 => -7.5 ], $totals );

		$this->assertSame( 0.0, $totals['gross_sales'] );
		$this->assertSame( 0.0, $totals['taxable_sales'] );
		$this->assertSame( 0.0, $totals['tax_collected'] );
		$this->assertSame(
			[
				'taxable_sales' => 0.0,
				'tax_collected' => 0.0,
			],
			$totals['by_rate'][1]
		);
	}

	/**
	 * A line taxed under two overlapping rates (e.g. state plus local)
	 * counts its full base under both, since a return asks for "sales
	 * subject to state tax" and "sales subject to local tax" separately, not
	 * a revenue split between them. The overall taxable_sales total must
	 * still count the line only once.
	 */
	public function test_a_line_under_two_rates_counts_its_full_base_under_each_but_once_overall(): void {
		$totals = TaxableSalesReport::accumulate(
			100.0,
			[
				1 => 6.5,
				2 => 1.0,
			],
			$this->empty_totals()
		);

		$this->assertSame( 100.0, $totals['taxable_sales'] );
		$this->assertSame( 7.5, $totals['tax_collected'] );
		$this->assertSame( 100.0, $totals['by_rate'][1]['taxable_sales'] );
		$this->assertSame( 100.0, $totals['by_rate'][2]['taxable_sales'] );
		$this->assertSame( 6.5, $totals['by_rate'][1]['tax_collected'] );
		$this->assertSame( 1.0, $totals['by_rate'][2]['tax_collected'] );
	}

	public function test_multiple_lines_accumulate_across_calls(): void {
		$totals = $this->empty_totals();
		$totals = TaxableSalesReport::accumulate( 50.0, [ 1 => 3.5 ], $totals );
		$totals = TaxableSalesReport::accumulate( 25.0, [], $totals );
		$totals = TaxableSalesReport::accumulate( 10.0, [ 1 => 0.7 ], $totals );

		$this->assertSame( 85.0, $totals['gross_sales'] );
		$this->assertSame( 60.0, $totals['taxable_sales'] );
		$this->assertSame( 4.2, round( $totals['tax_collected'], 2 ) );
		$this->assertSame( 60.0, $totals['by_rate'][1]['taxable_sales'] );
	}
}
