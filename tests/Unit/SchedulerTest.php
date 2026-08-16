<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PoorVida\TaxReports\Snapshots\Scheduler;

/**
 * @covers \PoorVida\TaxReports\Snapshots\Scheduler
 */
final class SchedulerTest extends TestCase {

	/**
	 * The snapshot has to fire at the configured time in the store's own
	 * timezone. Scheduling it in UTC would land it mid-afternoon in Arizona and
	 * split a day's sales across two snapshots.
	 */
	public function test_it_schedules_in_site_time_not_utc(): void {
		Functions\when( 'get_option' )->justReturn( [ 'snapshot_time' => '23:45' ] );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'America/Phoenix' ) );

		$timestamp = Scheduler::next_run_timestamp();
		$local     = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'America/Phoenix' ) );

		$this->assertSame( '23:45', $local->format( 'H:i' ) );
	}

	public function test_the_next_run_is_always_in_the_future(): void {
		Functions\when( 'get_option' )->justReturn( [ 'snapshot_time' => '00:01' ] );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'America/Phoenix' ) );

		$this->assertGreaterThan( time(), Scheduler::next_run_timestamp() );
	}

	public function test_it_never_schedules_more_than_a_day_out(): void {
		Functions\when( 'get_option' )->justReturn( [ 'snapshot_time' => '12:00' ] );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->assertLessThanOrEqual( time() + 86400, Scheduler::next_run_timestamp() );
	}
}
