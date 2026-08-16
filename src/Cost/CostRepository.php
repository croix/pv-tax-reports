<?php
/**
 * Cost cache persistence.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

use PoorVida\TaxReports\Support\Dates;
use PoorVida\TaxReports\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to {prefix}pvtax_costs.
 *
 * Append-only: every pull inserts new rows rather than updating existing
 * ones, so "what did BOM say this cost in October" stays answerable. Applies
 * to every pulled option, matched or not — an option that is unmapped today
 * may be mapped later, and its cost history should already be there when it is.
 */
final class CostRepository {

	private const CHUNK = 100;

	/**
	 * Insert one row per pulled option.
	 *
	 * @param list<array{mpn:?string, upc:?string, package_option_id:?string, product_id:?int, cost_per_container:?float, ingredient_cost:?float, packaging_cost:?float}> $rows Rows.
	 *
	 * @return int Rows written.
	 */
	public function insert_pull( array $rows ): int {
		global $wpdb;

		if ( [] === $rows ) {
			return 0;
		}

		$table   = Schema::table( 'costs' );
		$now     = Dates::now_utc();
		$written = 0;

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $row ) {
				/*
				 * Nullable numerics and the product link get a literal NULL rather
				 * than a placeholder: prepare() coerces null to 0 for %f and %d,
				 * which would misrepresent an unmapped option or a missing cost
				 * component as a real value.
				 */
				$product_id         = null === $row['product_id'] ? 'NULL' : '%d';
				$cost_per_container = null === $row['cost_per_container'] ? 'NULL' : '%f';
				$ingredient_cost    = null === $row['ingredient_cost'] ? 'NULL' : '%f';
				$packaging_cost     = null === $row['packaging_cost'] ? 'NULL' : '%f';

				$placeholders[] = "(%s, %s, %s, {$product_id}, {$cost_per_container}, {$ingredient_cost}, {$packaging_cost}, %s, %s)";

				$values[] = $row['mpn'];
				$values[] = $row['upc'];
				$values[] = $row['package_option_id'];

				if ( null !== $row['product_id'] ) {
					$values[] = $row['product_id'];
				}

				if ( null !== $row['cost_per_container'] ) {
					$values[] = $row['cost_per_container'];
				}

				if ( null !== $row['ingredient_cost'] ) {
					$values[] = $row['ingredient_cost'];
				}

				if ( null !== $row['packaging_cost'] ) {
					$values[] = $row['packaging_cost'];
				}

				$values[] = $now;
				$values[] = $now;
			}

			$sql = "INSERT INTO {$table} (mpn, upc, package_option_id, product_id, cost_per_container, ingredient_cost, packaging_cost, fetched_at, effective_from) VALUES "
				. implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are built above and passed through prepare(); custom table has no cache layer.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( is_int( $result ) ) {
				$written += count( $chunk );
			}
		}

		return $written;
	}

	/**
	 * Most recent pull time, or null when nothing has ever been synced.
	 */
	public function last_pull_at(): ?string {
		global $wpdb;

		$table = Schema::table( 'costs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; custom table has no cache layer.
		$value = $wpdb->get_var( "SELECT MAX(fetched_at) FROM {$table}" );

		return is_string( $value ) ? $value : null;
	}
}
