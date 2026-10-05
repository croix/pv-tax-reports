<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use PoorVida\TaxReports\Support\Dates;

/**
 * @covers \PoorVida\TaxReports\Support\Dates
 */
final class DatesTest extends TestCase {

	public function test_it_accepts_a_real_date(): void {
		$this->assertSame( '2026-12-31', Dates::normalize_date( '2026-12-31' ) );
	}

	public function test_it_rejects_a_calendar_impossible_date(): void {
		$this->assertNull( Dates::normalize_date( '2026-02-30' ) );
	}

	public function test_it_rejects_a_loose_format(): void {
		// createFromFormat would happily read '2026-1-5'; the round-trip check
		// is what rejects it, so dates in the table are always zero-padded.
		$this->assertNull( Dates::normalize_date( '2026-1-5' ) );
	}

	public function test_it_rejects_a_datetime(): void {
		$this->assertNull( Dates::normalize_date( '2026-12-31 10:00:00' ) );
	}

	public function test_it_rejects_junk(): void {
		$this->assertNull( Dates::normalize_date( 'yesterday' ) );
		$this->assertNull( Dates::normalize_date( '' ) );
	}

	public function test_quarters_count_back_from_the_current_one(): void {
		$quarters = Dates::quarters( '2026-10-05', 3 );

		$this->assertSame(
			[
				[
					'label'   => 'Q4 2026',
					'start'   => '2026-10-01',
					'end'     => '2026-10-05',
					'current' => true,
				],
				[
					'label'   => 'Q3 2026',
					'start'   => '2026-07-01',
					'end'     => '2026-09-30',
					'current' => false,
				],
				[
					'label'   => 'Q2 2026',
					'start'   => '2026-04-01',
					'end'     => '2026-06-30',
					'current' => false,
				],
			],
			$quarters
		);
	}

	public function test_quarters_cross_a_year_boundary(): void {
		$quarters = Dates::quarters( '2026-02-14', 2 );

		$this->assertSame( '2025-10-01', $quarters[1]['start'] );
		$this->assertSame( '2025-12-31', $quarters[1]['end'] );
		$this->assertSame( 'Q4 2025', $quarters[1]['label'] );
	}
}
