<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PoorVida\TaxReports\Support\Options;

/**
 * @covers \PoorVida\TaxReports\Support\Options
 */
final class OptionsTest extends TestCase {

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	public function test_it_falls_back_to_defaults_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( '23:45', Options::get( 'snapshot_time' ) );
		$this->assertSame( '_cogs_total_value', Options::cogs_meta_key() );
	}

	public function test_it_survives_a_corrupt_option(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		// A non-empty default proves the merge ran rather than the accessor
		// just handing back an empty string.
		$this->assertSame( '23:45', Options::get( 'snapshot_time' ) );
		$this->assertSame( '', Options::get( 'bom_url' ) );
	}

	public function test_the_bom_host_is_not_hardcoded(): void {
		// The repository is public; the BOM host is configuration, not a default.
		$this->assertSame( '', Options::defaults()['bom_url'] );
	}

	public function test_it_parses_a_valid_snapshot_time(): void {
		Functions\when( 'get_option' )->justReturn( [ 'snapshot_time' => '06:05' ] );

		$this->assertSame( [ 6, 5 ], Options::snapshot_time() );
	}

	/**
	 * A bad time must not schedule the snapshot at midnight-ish by accident;
	 * it falls back to the documented default instead.
	 *
	 * @dataProvider provide_invalid_times
	 */
	public function test_it_rejects_an_invalid_snapshot_time( string $stored ): void {
		Functions\when( 'get_option' )->justReturn( [ 'snapshot_time' => $stored ] );

		$this->assertSame( [ 23, 45 ], Options::snapshot_time() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function provide_invalid_times(): array {
		return [
			'out of range hour' => [ '24:00' ],
			'out of range min'  => [ '12:60' ],
			'unpadded'          => [ '6:05' ],
			'empty'             => [ '' ],
			'junk'              => [ 'evening' ],
		];
	}

	public function test_an_empty_meta_key_falls_back(): void {
		Functions\when( 'get_option' )->justReturn( [ 'cogs_meta_key' => '   ' ] );

		$this->assertSame( '_cogs_total_value', Options::cogs_meta_key() );
	}

	public function test_it_strips_a_trailing_slash_from_the_bom_url(): void {
		Functions\when( 'get_option' )->justReturn( [ 'bom_url' => 'https://bom.example.com/' ] );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $v ): string => rtrim( $v, '/\\' ) );

		$this->assertSame( 'https://bom.example.com', Options::bom_url() );
	}

	public function test_no_excluded_categories_by_default(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( [], Options::excluded_category_slugs() );
	}

	public function test_it_splits_and_trims_excluded_categories(): void {
		Functions\when( 'get_option' )->justReturn( [ 'excluded_categories' => ' clothing, merch ,bundles' ] );

		$this->assertSame( [ 'clothing', 'merch', 'bundles' ], Options::excluded_category_slugs() );
	}

	public function test_excluded_categories_drops_empty_entries_from_stray_commas(): void {
		Functions\when( 'get_option' )->justReturn( [ 'excluded_categories' => 'clothing,,  ,merch' ] );

		$this->assertSame( [ 'clothing', 'merch' ], Options::excluded_category_slugs() );
	}

	/**
	 * Any install that saved the settings screen before the wrong default was
	 * fixed has `_cogs_value` (WooCommerce's *order item* meta key) baked
	 * into its stored option instead of the real product key,
	 * `_cogs_total_value` — the settings form round-trips whatever was
	 * showing, so the wrong default got persisted the first time anyone
	 * saved anything.
	 */
	public function test_it_corrects_a_stored_legacy_cogs_meta_key(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'bom_url'       => 'https://bom.example.com',
				'cogs_meta_key' => '_cogs_value',
			]
		);

		$saved = null;

		Functions\when( 'update_option' )->alias(
			static function ( string $option, array $value ) use ( &$saved ): bool {
				$saved = $value;

				return true;
			}
		);

		Options::migrate_legacy_cogs_meta_key();

		$this->assertIsArray( $saved );
		$this->assertSame( '_cogs_total_value', $saved['cogs_meta_key'] );
		$this->assertSame( 'https://bom.example.com', $saved['bom_url'] );
	}

	public function test_it_leaves_a_deliberately_chosen_meta_key_alone(): void {
		Functions\when( 'get_option' )->justReturn( [ 'cogs_meta_key' => '_my_custom_cost_field' ] );

		Functions\expect( 'update_option' )->never();

		Options::migrate_legacy_cogs_meta_key();

		$this->addToAssertionCount( 1 );
	}

	public function test_it_does_nothing_when_nothing_is_stored_yet(): void {
		Functions\when( 'get_option' )->justReturn( false );

		Functions\expect( 'update_option' )->never();

		Options::migrate_legacy_cogs_meta_key();

		$this->addToAssertionCount( 1 );
	}
}
