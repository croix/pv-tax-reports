<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Filters;
use PoorVida\TaxReports\Reports\TaxableSalesReport as Report;

/**
 * @covers \PoorVida\TaxReports\Reports\TaxableSalesReport
 */
final class TaxableSalesReportTest extends TestCase {

	private const STATE = 'State Sales Tax';
	private const CITY  = 'DENTON CARROLLTON : City Tax';
	private const DART  = 'DART : Special Tax';

	/**
	 * A Texas web order, with overrides.
	 *
	 * @param list<array<string, mixed>> $lines Lines.
	 * @param array<string, mixed>       $over  Order field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function order( array $lines, array $over = [] ): array {
		return $over + [
			'order_id'      => 100,
			'parent_id'     => 0,
			'kind'          => 'order',
			'date'          => '2026-08-01',
			'marketplace'   => false,
			'texas'         => true,
			'state'         => 'US:TX',
			'taxable_share' => null,
			'lines'         => $lines,
		];
	}

	/**
	 * Texas tax at 8.25% on an amount, split the way checkout records it.
	 *
	 * @param float $amount Taxed amount.
	 *
	 * @return array<string, float>
	 */
	private function tx_tax( float $amount ): array {
		return [
			self::STATE => round( $amount * 0.0625, 2 ),
			self::CITY  => round( $amount * 0.01, 2 ),
			self::DART  => round( $amount * 0.01, 2 ),
		];
	}

	/**
	 * @param float                $net     Net.
	 * @param bool                 $taxable Product is taxable.
	 * @param array<string, float> $taxes   Taxes.
	 *
	 * @return array<string, mixed>
	 */
	private function item( float $net, bool $taxable, array $taxes = [] ): array {
		return [
			'type'    => 'line_item',
			'name'    => $taxable ? 'Liver Punch 2oz' : 'Hot sauce',
			'net'     => $net,
			'taxable' => $taxable,
			'taxes'   => $taxes,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shipping( float $net ): array {
		return [
			'type'  => 'shipping',
			'name'  => 'Flat rate',
			'net'   => $net,
			'taxes' => [],
		];
	}

	/**
	 * @param list<array<string, mixed>> ...$orders Order descriptions.
	 *
	 * @return array<string, mixed>
	 */
	private function report( array ...$orders ): array {
		$lines = [];

		foreach ( $orders as $order ) {
			array_push( $lines, ...Report::classify( $order ) );
		}

		return Report::summarize( $lines );
	}

	/**
	 * The point of the report: hot sauce is exempt food even though the
	 * store charged tax on it. That tax was collected in error, is still
	 * owed, and the sale is not a taxable sale.
	 */
	public function test_exempt_product_with_tax_charged_is_exempt_taxed_not_taxable(): void {
		$result = $this->report( $this->order( [ $this->item( 10.0, false, $this->tx_tax( 10.0 ) ) ] ) );

		$this->assertSame( 0.0, $result['totals']['taxable_sales'] );
		$this->assertSame( 10.0, $result['totals']['exempt_taxed_sales'] );
		$this->assertSame( 0.83, $result['totals']['tax_in_error'] );
		$this->assertSame( 0.83, $result['totals']['texas_tax_collected'] );
		$this->assertSame( 10.0, $result['totals']['total_texas_sales'] );
	}

	/**
	 * Tax collected in error is remitted, so on the return those sales are
	 * reported as taxable alongside the genuinely taxable ones — while the
	 * report still keeps the two apart.
	 */
	public function test_sales_reported_as_taxable_include_exempt_sales_taxed_in_error(): void {
		$result = $this->report(
			$this->order(
				[
					$this->item( 6.0, true, $this->tx_tax( 6.0 ) ),
					$this->item( 10.0, false, $this->tx_tax( 10.0 ) ),
					$this->item( 4.0, false ),
				]
			)
		);

		$this->assertSame( 6.0, $result['totals']['taxable_sales'] );
		$this->assertSame( 10.0, $result['totals']['exempt_taxed_sales'] );
		$this->assertSame( 16.0, $result['totals']['return_taxable_sales'] );
		$this->assertSame( 20.0, $result['totals']['total_texas_sales'] );
	}

	public function test_exempt_product_without_tax_is_plain_exempt(): void {
		$result = $this->report( $this->order( [ $this->item( 10.0, false ) ] ) );

		$this->assertSame( 10.0, $result['totals']['exempt_sales'] );
		$this->assertSame( 0.0, $result['totals']['exempt_taxed_sales'] );
		$this->assertSame( 10.0, $result['totals']['total_texas_sales'] );
	}

	/**
	 * Taxable is a fact about the product. A taxable drink that slipped
	 * through without tax is still a taxable sale — the tax is owed whether
	 * or not it was collected.
	 */
	public function test_taxable_product_is_taxable_even_if_no_tax_was_charged(): void {
		$result = $this->report( $this->order( [ $this->item( 6.0, true ) ] ) );

		$this->assertSame( 6.0, $result['totals']['taxable_sales'] );
		$this->assertSame( 0.0, $result['totals']['texas_tax_collected'] );
	}

	/**
	 * Shipping on taxable items is part of the taxable sales price, even
	 * when checkout charged no tax on it.
	 */
	public function test_shipping_on_all_taxable_items_is_taxable(): void {
		$result = $this->report( $this->order( [ $this->item( 20.0, true, $this->tx_tax( 20.0 ) ), $this->shipping( 5.0 ) ] ) );

		$this->assertSame( 25.0, $result['totals']['taxable_sales'] );
		$this->assertSame( 25.0, $result['totals']['total_texas_sales'] );
	}

	public function test_shipping_on_all_exempt_items_is_exempt(): void {
		$result = $this->report( $this->order( [ $this->item( 20.0, false ), $this->shipping( 5.0 ) ] ) );

		$this->assertSame( 0.0, $result['totals']['taxable_sales'] );
		$this->assertSame( 25.0, $result['totals']['exempt_sales'] );
	}

	/**
	 * Mixed order: shipping is taxable in proportion to item value, and the
	 * two halves of the split still add up to the shipping charged.
	 */
	public function test_shipping_on_a_mixed_order_is_split_by_item_value(): void {
		$order  = $this->order(
			[
				$this->item( 6.0, true, $this->tx_tax( 6.0 ) ),
				$this->item( 18.0, false, $this->tx_tax( 18.0 ) ),
				$this->shipping( 8.0 ),
			]
		);
		$lines  = Report::classify( $order );
		$result = Report::summarize( $lines );

		$this->assertCount( 4, $lines, 'Shipping on a mixed order splits into a taxable and an exempt line.' );
		$this->assertSame( 8.0, $result['totals']['taxable_sales'] ); // 6 + 25% of 8.
		$this->assertSame( 18.0, $result['totals']['exempt_taxed_sales'] ); // The taxed hot sauce.
		$this->assertSame( 6.0, $result['totals']['exempt_sales'] ); // 75% of 8 — exempt, and no tax was charged on the shipping.
		$this->assertSame( 32.0, $result['totals']['total_texas_sales'] );
	}

	public function test_taxable_share_is_by_item_value(): void {
		$this->assertSame( 0.25, Report::taxable_share( [ $this->item( 6.0, true ), $this->item( 18.0, false ), $this->shipping( 99.0 ) ] ) );
		$this->assertSame( 0.0, Report::taxable_share( [ $this->shipping( 5.0 ) ] ) );
		$this->assertSame( 1.0, Report::taxable_share( [ $this->item( 0.0, true ) ] ) );
	}

	/**
	 * Amazon's tax is the marketplace's to report: never in Texas sales,
	 * never in the jurisdiction breakdown — that is exactly how its "rate 1"
	 * tax line used to become a phantom City Tax row.
	 */
	public function test_marketplace_orders_are_outside_texas_sales_and_jurisdictions(): void {
		$result = $this->report(
			$this->order( [ $this->item( 30.0, false, [ 'Tax' => 2.48 ] ) ], [ 'marketplace' => true ] ),
			$this->order( [ $this->item( 10.0, false ) ] )
		);

		$this->assertSame( 10.0, $result['totals']['total_texas_sales'] );
		$this->assertSame( 0.0, $result['totals']['texas_tax_collected'] );
		$this->assertSame( 30.0, $result['by_category']['marketplace']['sales'] );
		$this->assertSame( 2.48, $result['by_category']['marketplace']['tax'] );
		$this->assertSame( [], $result['by_jurisdiction'] );
		$this->assertSame( 40.0, $result['totals']['gross_sales'] );
	}

	public function test_out_of_state_orders_are_not_texas_sales(): void {
		$result = $this->report(
			$this->order(
				[ $this->item( 20.0, false ), $this->shipping( 6.0 ) ],
				[
					'texas' => false,
					'state' => 'US:FL',
				]
			)
		);

		$this->assertSame( 0.0, $result['totals']['total_texas_sales'] );
		$this->assertSame( 26.0, $result['by_category']['out_of_state']['sales'] );
	}

	/**
	 * A fully refunded order and its refund net to zero — sales and tax.
	 * Refund lines reduce the taxable base, not just the tax.
	 */
	public function test_a_refund_nets_out_sales_base_and_tax(): void {
		$result = $this->report(
			$this->order( [ $this->item( 60.0, false, $this->tx_tax( 60.0 ) ) ] ),
			$this->order(
				[ $this->item( -60.0, false, $this->tx_tax( -60.0 ) ) ],
				[
					'order_id'  => 101,
					'parent_id' => 100,
					'kind'      => 'refund',
				]
			)
		);

		$this->assertSame( 0.0, $result['totals']['total_texas_sales'] );
		$this->assertSame( 0.0, $result['totals']['texas_tax_collected'] );

		$this->assertSame( [], $result['by_jurisdiction'], 'A jurisdiction that nets to nothing is not listed.' );
	}

	/**
	 * A shipping-only refund has no items to apportion by, so it uses its
	 * order's share.
	 */
	public function test_a_shipping_only_refund_uses_its_orders_share(): void {
		$lines = Report::classify(
			$this->order(
				[ $this->shipping( -8.0 ) ],
				[
					'kind'          => 'refund',
					'taxable_share' => 0.25,
				]
			)
		);

		$this->assertSame( -2.0, $lines[0]['net'] );
		$this->assertSame( Report::CATEGORY_TAXABLE, $lines[0]['category'] );
		$this->assertSame( -6.0, $lines[1]['net'] );
	}

	/**
	 * Each jurisdiction's base is the sales its tax was charged on, once per
	 * line — so state, city, and DART each show the same base and an
	 * effective rate that matches the real one.
	 */
	public function test_jurisdictions_show_the_base_they_taxed_and_a_sane_rate(): void {
		$result = $this->report(
			$this->order( [ $this->item( 40.0, false, $this->tx_tax( 40.0 ) ), $this->shipping( 7.0 ) ] ),
			$this->order( [ $this->item( 12.0, true, $this->tx_tax( 12.0 ) ) ], [ 'order_id' => 102 ] )
		);

		$by_label = array_column( $result['by_jurisdiction'], null, 'label' );

		$this->assertSame( 52.0, $by_label[ self::STATE ]['taxable_base'] );
		$this->assertSame( 52.0, $by_label[ self::CITY ]['taxable_base'] );
		$this->assertSame( 6.25, $by_label[ self::STATE ]['rate_percent'] );
		$this->assertSame( 1.0, $by_label[ self::CITY ]['rate_percent'] );
		$this->assertSame( 0.52, $by_label[ self::DART ]['tax'] );
	}

	/**
	 * Zero-amount tax entries are not tax charged.
	 */
	public function test_a_zero_tax_entry_does_not_count_as_taxed(): void {
		$result = $this->report( $this->order( [ $this->item( 10.0, false, [ self::STATE => 0.0 ] ) ] ) );

		$this->assertSame( 10.0, $result['totals']['exempt_sales'] );
		$this->assertSame( [], $result['by_jurisdiction'] );
	}

	/**
	 * Every line lands in exactly one category, so categories sum to gross.
	 */
	public function test_categories_sum_to_gross(): void {
		$result = $this->report(
			$this->order( [ $this->item( 6.0, true ), $this->item( 18.0, false, $this->tx_tax( 18.0 ) ), $this->shipping( 8.33 ) ] ),
			$this->order( [ $this->item( 20.0, false ) ], [ 'texas' => false ] ),
			$this->order( [ $this->item( 30.0, false ) ], [ 'marketplace' => true ] )
		);

		$this->assertSame( 82.33, round( array_sum( array_column( $result['by_category'], 'sales' ) ), 2 ) );
		$this->assertSame( 82.33, $result['totals']['gross_sales'] );
	}

	public function test_marketplace_channel_detection(): void {
		Filters\expectApplied( 'pvtax_marketplace_channels' )->andReturnFirstArg();

		$this->assertTrue( Report::is_marketplace( 'Amazon' ) );
		$this->assertTrue( Report::is_marketplace( 'ced_amazon' ) );
		$this->assertFalse( Report::is_marketplace( 'checkout' ) );
		$this->assertFalse( Report::is_marketplace( 'store-api' ) );
		$this->assertFalse( Report::is_marketplace( 'admin' ) );
	}

	public function test_state_key_normalizes_names_and_case(): void {
		$states = [
			'TX' => 'Texas',
			'FL' => 'Florida',
		];

		$this->assertSame( 'US:TX', Report::state_key( 'US', 'TX', $states ) );
		$this->assertSame( 'US:TX', Report::state_key( 'US', 'Texas', $states ) );
		$this->assertSame( 'US:TX', Report::state_key( 'us', ' tx ', $states ) );
		$this->assertSame( 'US:FL', Report::state_key( 'US', 'florida', $states ) );
		$this->assertSame( 'US:', Report::state_key( 'US', '', $states ) );
	}
}
