<?php
/**
 * Action Scheduler wiring for the nightly snapshot.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Snapshots;

use PoorVida\TaxReports\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a daily snapshot action scheduled.
 *
 * Action Scheduler is used rather than raw wp_cron because it is bundled with
 * WooCommerce, retries failures, and leaves an audit trail — all of which
 * matter for a job whose missed runs are unrecoverable.
 */
final class Scheduler {

	public const HOOK  = 'pvtax_daily_snapshot';
	public const GROUP = 'pv-tax-reports';

	private const DAY_IN_SECONDS = 86400;

	/**
	 * Wire up the scheduler.
	 *
	 * @param SnapshotService $snapshots Snapshot service.
	 */
	public function __construct( private readonly SnapshotService $snapshots ) {}

	/**
	 * Ensure the recurring action exists once WooCommerce and Action Scheduler
	 * have loaded.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'ensure_scheduled' ], 20 );
		add_action( 'update_option_' . Options::OPTION, [ $this, 'reschedule' ], 10, 0 );
	}

	/**
	 * Schedule the daily action if it is not already queued.
	 */
	public function ensure_scheduled(): void {
		if ( ! self::action_scheduler_available() ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, [], self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( self::next_run_timestamp(), self::DAY_IN_SECONDS, self::HOOK, [], self::GROUP );
	}

	/**
	 * Move the daily action after the snapshot time is changed in settings.
	 */
	public function reschedule(): void {
		if ( ! self::action_scheduler_available() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, [], self::GROUP );

		as_schedule_recurring_action( self::next_run_timestamp(), self::DAY_IN_SECONDS, self::HOOK, [], self::GROUP );
	}

	/**
	 * Remove the daily action on deactivation.
	 */
	public static function unschedule(): void {
		if ( ! self::action_scheduler_available() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, [], self::GROUP );
	}

	/**
	 * Timestamp of the next occurrence of the configured local time.
	 *
	 * Built in the site's timezone and converted to UTC, so a store on
	 * Mountain time snapshots at 23:45 Mountain rather than 23:45 UTC — which
	 * would land mid-afternoon and split a day's sales across two snapshots.
	 */
	public static function next_run_timestamp(): int {
		[ $hour, $minute ] = Options::snapshot_time();

		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );
		$next     = $now->setTime( $hour, $minute, 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Next scheduled run as a UTC timestamp, or null when nothing is queued.
	 */
	public static function next_scheduled(): ?int {
		if ( ! self::action_scheduler_available() ) {
			return null;
		}

		$next = as_next_scheduled_action( self::HOOK, [], self::GROUP );

		return is_int( $next ) ? $next : null;
	}

	/**
	 * Whether Action Scheduler's API is loaded.
	 */
	public static function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Snapshot service accessor.
	 */
	public function snapshots(): SnapshotService {
		return $this->snapshots;
	}
}
