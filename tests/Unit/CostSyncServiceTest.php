<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PoorVida\TaxReports\Cost\BomApiClient;
use PoorVida\TaxReports\Cost\CostRepository;
use PoorVida\TaxReports\Cost\CostResolver;
use PoorVida\TaxReports\Cost\CostSyncService;
use PoorVida\TaxReports\Cost\ProductMapper;
use WC_Product;

/**
 * @covers \PoorVida\TaxReports\Cost\CostSyncService
 */
final class CostSyncServiceTest extends TestCase {

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a service with real collaborators.
	 *
	 * Neither method under test here touches the client or the repository, so
	 * real instances stand in — both are final and cannot be mocked, and
	 * there is nothing to stub on either of them for these tests.
	 */
	private function service(): CostSyncService {
		return new CostSyncService( new BomApiClient(), new CostRepository(), new CostResolver() );
	}

	public function test_saving_a_mapping_writes_the_override_meta_and_invalidates_the_preview(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'update_meta_data' )->once()->with( ProductMapper::OVERRIDE_META_KEY, 'opt-verde' );
		$product->shouldReceive( 'save' )->once();

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\expect( 'delete_transient' )->once()->with( CostSyncService::PREVIEW_TRANSIENT );

		$this->assertTrue( $this->service()->save_override( 42, 'opt-verde' ) );
	}

	public function test_saving_a_mapping_for_a_missing_product_fails_without_touching_the_transient(): void {
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\expect( 'delete_transient' )->never();

		$this->assertFalse( $this->service()->save_override( 999, 'opt-verde' ) );
	}

	public function test_clearing_a_mapping_deletes_the_override_meta(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'delete_meta_data' )->once()->with( ProductMapper::OVERRIDE_META_KEY );
		$product->shouldReceive( 'save' )->once();

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\expect( 'delete_transient' )->once()->with( CostSyncService::PREVIEW_TRANSIENT );

		$this->assertTrue( $this->service()->clear_override( 42 ) );
	}
}
