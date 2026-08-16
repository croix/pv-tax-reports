<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Cost\LegacyCogsMigrationService;

/**
 * @covers \PoorVida\TaxReports\Cost\LegacyCogsMigrationService
 */
final class LegacyCogsMigrationServiceTest extends TestCase {

	public function test_it_finds_a_value_under_the_default_skyverge_key(): void {
		$found = LegacyCogsMigrationService::find_legacy_value(
			[ '_wc_cog_cost' => '4.25' ],
			[ '_wc_cog_cost' ]
		);

		$this->assertSame(
			[
				'key'   => '_wc_cog_cost',
				'value' => 4.25,
			],
			$found
		);
	}

	public function test_it_returns_null_when_no_configured_key_has_a_value(): void {
		$found = LegacyCogsMigrationService::find_legacy_value(
			[ '_wc_cog_cost' => '' ],
			[ '_wc_cog_cost' ]
		);

		$this->assertNull( $found );
	}

	public function test_a_non_numeric_value_does_not_count(): void {
		$found = LegacyCogsMigrationService::find_legacy_value(
			[ '_wc_cog_cost' => 'n/a' ],
			[ '_wc_cog_cost' ]
		);

		$this->assertNull( $found );
	}

	/**
	 * An explicit zero is a real cost, not "nothing to migrate" — the same
	 * null-vs-zero distinction the rest of the plugin holds to.
	 */
	public function test_an_explicit_zero_still_counts_as_a_value_to_migrate(): void {
		$found = LegacyCogsMigrationService::find_legacy_value(
			[ '_wc_cog_cost' => '0' ],
			[ '_wc_cog_cost' ]
		);

		$this->assertSame(
			[
				'key'   => '_wc_cog_cost',
				'value' => 0.0,
			],
			$found
		);
	}

	public function test_it_checks_keys_in_priority_order(): void {
		$found = LegacyCogsMigrationService::find_legacy_value(
			[
				'_wc_cog_cost'    => '',
				'_some_other_key' => '9.99',
			],
			[ '_wc_cog_cost', '_some_other_key' ]
		);

		$this->assertSame(
			[
				'key'   => '_some_other_key',
				'value' => 9.99,
			],
			$found
		);
	}

	public function test_a_key_missing_entirely_is_treated_like_an_empty_one(): void {
		$found = LegacyCogsMigrationService::find_legacy_value( [], [ '_wc_cog_cost' ] );

		$this->assertNull( $found );
	}
}
