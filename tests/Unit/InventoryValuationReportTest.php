<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Reports\InventoryValuationReport;

/**
 * @covers \PoorVida\TaxReports\Reports\InventoryValuationReport
 */
final class InventoryValuationReportTest extends TestCase {

	private function row( int $id, string $sku, ?float $quantity, ?float $unit_cost ): array {
		return [
			'product_id' => $id,
			'sku'        => $sku,
			'quantity'   => $quantity,
			'unit_cost'  => $unit_cost,
		];
	}

	public function test_it_extends_quantity_by_cost(): void {
		$result = InventoryValuationReport::compute( [ $this->row( 1, 'SKU-1', 4.0, 2.5 ) ] );

		$this->assertSame( 10.0, $result['lines'][0]['extended_value'] );
		$this->assertSame( 10.0, $result['total'] );
		$this->assertSame( 0, $result['uncosted_count'] );
	}

	/**
	 * An uncosted line must never be valued at zero — it has to be visibly
	 * absent from the total, not silently folded into it as $0.
	 */
	public function test_an_uncosted_line_is_excluded_from_the_total_not_zeroed(): void {
		$result = InventoryValuationReport::compute(
			[
				$this->row( 1, 'SKU-1', 4.0, 2.5 ),
				$this->row( 2, 'SKU-2', 3.0, null ),
			]
		);

		$this->assertNull( $result['lines'][1]['extended_value'] );
		$this->assertSame( 10.0, $result['total'] );
		$this->assertSame( 1, $result['uncosted_count'] );
	}

	/**
	 * A genuine zero cost is a real answer, distinct from "uncosted", and
	 * must still count toward the total (as zero) rather than being flagged.
	 */
	public function test_an_explicit_zero_cost_counts_toward_the_total(): void {
		$result = InventoryValuationReport::compute( [ $this->row( 1, 'SKU-1', 5.0, 0.0 ) ] );

		$this->assertSame( 0.0, $result['lines'][0]['extended_value'] );
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( 0, $result['uncosted_count'] );
	}

	public function test_a_missing_quantity_has_no_extended_value_either(): void {
		$result = InventoryValuationReport::compute( [ $this->row( 1, 'SKU-1', null, 2.5 ) ] );

		$this->assertNull( $result['lines'][0]['extended_value'] );
	}

	public function test_multiple_lines_sum_only_the_costed_ones(): void {
		$result = InventoryValuationReport::compute(
			[
				$this->row( 1, 'SKU-1', 2.0, 3.0 ),
				$this->row( 2, 'SKU-2', 1.0, 4.0 ),
				$this->row( 3, 'SKU-3', 5.0, null ),
			]
		);

		$this->assertSame( 10.0, $result['total'] );
		$this->assertSame( 1, $result['uncosted_count'] );
		$this->assertCount( 3, $result['lines'] );
	}

	public function test_an_empty_list_totals_to_zero(): void {
		$result = InventoryValuationReport::compute( [] );

		$this->assertSame( [], $result['lines'] );
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( 0, $result['uncosted_count'] );
	}
}
