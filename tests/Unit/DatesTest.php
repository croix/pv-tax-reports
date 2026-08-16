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
}
