<?php
/**
 * Order COGS persistence.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cogs;

use PoorVida\TaxReports\Support\Dates;
use PoorVida\TaxReports\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes {prefix}pvtax_order_cogs.
 */
final class OrderCogsRepository {

	/**
	 * Insert a captured line, ignoring it if that order item is already frozen.
	 *
	 * The INSERT IGNORE against the unique key on order_item_id is the whole
	 * drift rule in one statement: once a line's cost is recorded, nothing
	 * later re-records it.
	 *
	 * @param array{order_id:int, order_item_id:int, product_id:int, quantity:float, unit_cost:float|null, line_cost:float|null, cost_source:string} $row Line.
	 *
	 * @return bool Whether a new row was written.
	 */
	public function insert_if_absent( array $row ): bool {
		global $wpdb;

		$table = Schema::table( 'order_cogs' );

		$unit_cost = null === $row['unit_cost'] ? 'NULL' : '%f';
		$line_cost = null === $row['line_cost'] ? 'NULL' : '%f';

		$values = [
			$row['order_id'],
			$row['order_item_id'],
			$row['product_id'],
			$row['quantity'],
		];

		if ( null !== $row['unit_cost'] ) {
			$values[] = $row['unit_cost'];
		}

		if ( null !== $row['line_cost'] ) {
			$values[] = $row['line_cost'];
		}

		$values[] = $row['cost_source'];
		$values[] = Dates::now_utc();

		$sql = "INSERT IGNORE INTO {$table} (order_id, order_item_id, product_id, quantity, unit_cost, line_cost, cost_source, captured_at)"
			. " VALUES (%d, %d, %d, %f, {$unit_cost}, {$line_cost}, %s, %s)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are built above and passed through prepare(); custom table has no cache layer.
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return is_int( $result ) && $result > 0;
	}

	/**
	 * Whether any line has been captured for an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function has_order( int $order_id ): bool {
		global $wpdb;

		$table = Schema::table( 'order_cogs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE order_id = %d LIMIT 1", $order_id ) );
	}

	/**
	 * Captured lines for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return list<object>
	 */
	public function for_order( int $order_id ): array {
		global $wpdb;

		$table = Schema::table( 'order_cogs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY order_item_id ASC", $order_id ) );
	}

	/**
	 * Total captured lines, and how many of them have no cost on file.
	 *
	 * @return array{lines:int, orders:int, uncosted:int}
	 */
	public function stats(): array {
		global $wpdb;

		$table = Schema::table( 'order_cogs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS lines_total, COUNT(DISTINCT order_id) AS orders_total, SUM(unit_cost IS NULL) AS uncosted_total FROM {$table}" );

		return [
			'lines'    => (int) ( $row->lines_total ?? 0 ),
			'orders'   => (int) ( $row->orders_total ?? 0 ),
			'uncosted' => (int) ( $row->uncosted_total ?? 0 ),
		];
	}
}
