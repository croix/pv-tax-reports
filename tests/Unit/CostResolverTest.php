<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PoorVida\TaxReports\Cost\CostResolver;
use WC_Product;

/**
 * @covers \PoorVida\TaxReports\Cost\CostResolver
 */
final class CostResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( [] );
		Filters\expectApplied( 'pvtax_product_unit_cost' )->andReturnFirstArg();
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * A product with no cost on file must resolve to null, never to 0.00.
	 * Zero would silently value stock at nothing instead of flagging it as
	 * uncosted — the exact error a tax report must not make.
	 */
	public function test_a_missing_cost_is_null_not_zero(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->andReturn( '' );

		$resolved = ( new CostResolver() )->for_product( $product );

		$this->assertNull( $resolved['cost'] );
		$this->assertSame( CostResolver::SOURCE_NONE, $resolved['source'] );
	}

	public function test_it_reads_the_fallback_meta_key(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_cogs_value', true )->andReturn( '4.25' );

		$resolved = ( new CostResolver() )->for_product( $product );

		$this->assertSame( 4.25, $resolved['cost'] );
		$this->assertSame( CostResolver::SOURCE_META, $resolved['source'] );
	}

	/**
	 * A genuine zero cost is a real answer and must survive, distinct from
	 * "no cost recorded".
	 */
	public function test_an_explicit_zero_cost_is_kept(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_cogs_value', true )->andReturn( '0' );

		$resolved = ( new CostResolver() )->for_product( $product );

		$this->assertSame( 0.0, $resolved['cost'] );
		$this->assertSame( CostResolver::SOURCE_META, $resolved['source'] );
	}

	public function test_a_non_numeric_meta_value_is_treated_as_missing(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_cogs_value', true )->andReturn( 'n/a' );

		$this->assertNull( ( new CostResolver() )->for_product( $product )['cost'] );
	}

	/**
	 * The write path is the sync's half of the drift rule: it must land on
	 * whichever field for_product() reads, or a synced cost would silently
	 * fail to be picked up anywhere else in the plugin.
	 */
	public function test_writing_a_cost_lands_on_the_same_field_read_by_for_product(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'update_meta_data' )->once()->with( '_cogs_value', '3.5' );
		$product->shouldReceive( 'save' )->once();
		$product->shouldReceive( 'get_meta' )->with( '_cogs_value', true )->andReturn( '3.5' );

		( new CostResolver() )->write( $product, 3.5 );

		$this->assertSame( 3.5, ( new CostResolver() )->for_product( $product )['cost'] );
	}
}
