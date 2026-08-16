<?php
/**
 * Stock snapshot persistence.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Snapshots;

use PoorVida\TaxReports\Support\Dates;
use PoorVida\TaxReports\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes {prefix}pvtax_stock_snapshots.
 */
final class StockSnapshotRepository {

	private const CHUNK = 100;

	/**
	 * Upsert a day's rows.
	 *
	 * Re-running a date overwrites that date and touches nothing else, which is
	 * what makes "snapshot now" safe to press twice.
	 *
	 * @param string                                                                                                 $date Y-m-d.
	 * @param list<array{product_id:int, sku:string, quantity:float|null, unit_cost:float|null, cost_source:string}> $rows Rows.
	 *
	 * @return int Rows written.
	 */
	public function upsert_day( string $date, array $rows ): int {
		global $wpdb;

		if ( [] === $rows ) {
			return 0;
		}

		$table   = Schema::table( 'stock_snapshots' );
		$now     = Dates::now_utc();
		$written = 0;

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $row ) {
				/*
				 * Nullable numerics get a literal NULL rather than a placeholder:
				 * prepare() coerces null to 0 for %f, which would quietly value an
				 * uncosted product at zero instead of flagging it as uncosted.
				 */
				$quantity  = null === $row['quantity'] ? 'NULL' : '%f';
				$unit_cost = null === $row['unit_cost'] ? 'NULL' : '%f';

				$placeholders[] = "(%s, %d, %s, {$quantity}, {$unit_cost}, %s, %s)";

				$values[] = $date;
				$values[] = $row['product_id'];
				$values[] = $row['sku'];

				if ( null !== $row['quantity'] ) {
					$values[] = $row['quantity'];
				}

				if ( null !== $row['unit_cost'] ) {
					$values[] = $row['unit_cost'];
				}

				$values[] = $row['cost_source'];
				$values[] = $now;
			}

			$sql = "INSERT INTO {$table} (snapshot_date, product_id, sku, quantity, unit_cost, cost_source, created_at) VALUES "
				. implode( ', ', $placeholders )
				. ' ON DUPLICATE KEY UPDATE sku = VALUES(sku), quantity = VALUES(quantity), unit_cost = VALUES(unit_cost), cost_source = VALUES(cost_source), created_at = VALUES(created_at)';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are built above and passed through prepare(); custom table has no cache layer.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( is_int( $result ) ) {
				$written += count( $chunk );
			}
		}

		return $written;
	}

	/**
	 * Every row for a date.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return list<object>
	 */
	public function for_date( string $date ): array {
		global $wpdb;

		$table = Schema::table( 'stock_snapshots' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_date = %s ORDER BY sku ASC", $date ) );
	}

	/**
	 * Earliest date with any snapshot, or null when none have been taken.
	 *
	 * Report 1 uses this to tell the difference between "nothing was in stock"
	 * and "the plugin was not recording yet".
	 */
	public function earliest_date(): ?string {
		global $wpdb;

		$table = Schema::table( 'stock_snapshots' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		$value = $wpdb->get_var( "SELECT MIN(snapshot_date) FROM {$table}" );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Most recent date with any snapshot.
	 */
	public function latest_date(): ?string {
		global $wpdb;

		$table = Schema::table( 'stock_snapshots' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		$value = $wpdb->get_var( "SELECT MAX(snapshot_date) FROM {$table}" );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Count of distinct snapshotted days.
	 */
	public function day_count(): int {
		global $wpdb;

		$table = Schema::table( 'stock_snapshots' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT snapshot_date) FROM {$table}" );
	}
}
