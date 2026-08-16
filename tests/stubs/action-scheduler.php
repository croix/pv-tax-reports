<?php
/**
 * Action Scheduler function signatures, for static analysis only.
 *
 * Action Scheduler ships inside WooCommerce rather than as a Composer package,
 * so there is nothing for PHPStan to scan. This file is never loaded at
 * runtime; the plugin guards every call with function_exists().
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

/**
 * @param string            $hook  Hook name.
 * @param array<int, mixed> $args  Callback arguments.
 * @param string            $group Group name.
 *
 * @return int|bool Timestamp of the next run, true when scheduled without one, false when not scheduled.
 */
function as_next_scheduled_action( string $hook, array $args = [], string $group = '' ) {
}

/**
 * @param int               $timestamp First run, as a Unix timestamp.
 * @param int               $interval  Seconds between runs.
 * @param string            $hook      Hook name.
 * @param array<int, mixed> $args      Callback arguments.
 * @param string            $group     Group name.
 */
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = [], string $group = '' ): int {
}

/**
 * @param string                 $hook  Hook name.
 * @param array<int, mixed>|null $args  Callback arguments.
 * @param string                 $group Group name.
 */
function as_unschedule_all_actions( string $hook, ?array $args = [], string $group = '' ): void {
}
