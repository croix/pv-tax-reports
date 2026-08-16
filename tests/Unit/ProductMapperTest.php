<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Cost\ProductMapper;

/**
 * @covers \PoorVida\TaxReports\Cost\ProductMapper
 */
final class ProductMapperTest extends TestCase {

	private function verde(): array {
		return [
			'mpn'              => 'PV-SALSA-VERDE-16',
			'upc'              => '860012345678',
			'packageOptionId'  => 'opt-verde',
			'recipeName'       => 'Verde Ghost Salsa',
			'costPerContainer' => 2.4185,
		];
	}

	public function test_it_matches_by_mpn_first(): void {
		$products = [
			[
				'product_id' => 1,
				'sku'        => 'PV-SALSA-VERDE-16',
				'override'   => null,
			],
		];

		$result = ProductMapper::match( [ $this->verde() ], $products );

		$this->assertCount( 1, $result['matched'] );
		$this->assertSame( 'mpn', $result['matched'][0]['matched_via'] );
		$this->assertSame( [], $result['unmapped_options'] );
		$this->assertSame( [], $result['unmapped_products'] );
	}

	public function test_it_falls_back_to_upc_when_sku_is_a_upc(): void {
		// Per the scope: SKUs synced to Amazon carry the UPC rather than the MPN.
		$products = [
			[
				'product_id' => 1,
				'sku'        => '860012345678',
				'override'   => null,
			],
		];

		$result = ProductMapper::match( [ $this->verde() ], $products );

		$this->assertCount( 1, $result['matched'] );
		$this->assertSame( 'upc', $result['matched'][0]['matched_via'] );
	}

	public function test_mpn_wins_over_upc_when_a_sku_could_match_either(): void {
		// Resolution order per the design decision: MPN before UPC before override.
		$option = [
			'mpn'             => 'DUP',
			'upc'             => 'DUP',
			'packageOptionId' => 'opt-dup',
		];

		$products = [
			[
				'product_id' => 1,
				'sku'        => 'DUP',
				'override'   => null,
			],
		];

		$result = ProductMapper::match( [ $option ], $products );

		$this->assertSame( 'mpn', $result['matched'][0]['matched_via'] );
	}

	public function test_override_is_the_last_resort(): void {
		$products = [
			[
				'product_id' => 1,
				'sku'        => 'SOME-OTHER-SKU',
				'override'   => 'opt-verde',
			],
		];

		$result = ProductMapper::match( [ $this->verde() ], $products );

		$this->assertCount( 1, $result['matched'] );
		$this->assertSame( 'override', $result['matched'][0]['matched_via'] );
	}

	public function test_no_fuzzy_matching_leaves_it_unmapped(): void {
		$products = [
			[
				'product_id' => 1,
				'sku'        => 'pv-salsa-verde-16',
				'override'   => null,
			],
		];

		$result = ProductMapper::match( [ $this->verde() ], $products );

		$this->assertSame( [], $result['matched'] );
		$this->assertCount( 1, $result['unmapped_products'] );
	}

	public function test_an_option_with_no_matching_product_is_unmapped(): void {
		// The two drinks: no MPN, no UPC, and never referenced by an override.
		$drink = [
			'mpn'             => null,
			'upc'             => null,
			'packageOptionId' => 'opt-drink',
		];

		$result = ProductMapper::match( [ $drink ], [] );

		$this->assertSame( [], $result['matched'] );
		$this->assertCount( 1, $result['unmapped_options'] );
	}

	public function test_a_claimed_option_does_not_also_appear_unmapped(): void {
		$products = [
			[
				'product_id' => 1,
				'sku'        => 'PV-SALSA-VERDE-16',
				'override'   => null,
			],
		];

		$result = ProductMapper::match( [ $this->verde() ], $products );

		$this->assertSame( [], $result['unmapped_options'] );
	}
}
