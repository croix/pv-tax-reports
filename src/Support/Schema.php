<?php
/**
 * Custom table schema.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables via dbDelta.
 */
final class Schema {

	public const DB_VERSION_OPTION = 'pvtax_db_version';

	/**
	 * Fully qualified table name for a plugin table.
	 *
	 * @param string $name Unprefixed name, e.g. 'costs'.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'pvtax_' . $name;
	}

	/**
	 * Run on activation.
	 */
	public static function install(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, (string) \PoorVida\TaxReports\DB_VERSION, false );
	}

	/**
	 * Run on load; a no-op unless the shipped schema is newer than the stored one.
	 *
	 * This exists because a plugin updated in place (GitHub release, or an
	 * unzip over the top) never fires the activation hook.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, '0' );

		if ( \PoorVida\TaxReports\DB_VERSION === $installed ) {
			return;
		}

		self::install();
	}

	/**
	 * Issue the dbDelta calls.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		/*
		 * Append-only cost cache pulled from BOM. A new pull inserts rather than
		 * updates, so "what did BOM say this cost in October" stays answerable.
		 * Both keys are stored because a WooCommerce SKU is sometimes the MPN
		 * and sometimes the UPC.
		 */
		$costs = self::table( 'costs' );
		dbDelta(
			"CREATE TABLE {$costs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				mpn varchar(64) DEFAULT NULL,
				upc varchar(64) DEFAULT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				cost_per_container decimal(16,6) DEFAULT NULL,
				ingredient_cost decimal(16,6) DEFAULT NULL,
				packaging_cost decimal(16,6) DEFAULT NULL,
				source varchar(32) NOT NULL DEFAULT 'bom',
				fetched_at datetime NOT NULL,
				effective_from datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY mpn (mpn),
				KEY upc (upc),
				KEY product_id (product_id),
				KEY effective_from (effective_from)
			) {$collate};"
		);

		/*
		 * Daily stock, per product. unit_cost is copied in at snapshot time so a
		 * later cost change cannot restate a past valuation.
		 *
		 * The composite primary key is the whole idempotency story: re-running
		 * today's snapshot overwrites today's rows and touches nothing else.
		 */
		$snapshots = self::table( 'stock_snapshots' );
		dbDelta(
			"CREATE TABLE {$snapshots} (
				snapshot_date date NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				sku varchar(100) DEFAULT NULL,
				quantity decimal(16,4) DEFAULT NULL,
				unit_cost decimal(16,6) DEFAULT NULL,
				cost_source varchar(32) DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (snapshot_date,product_id),
				KEY product_id (product_id)
			) {$collate};"
		);

		/*
		 * COGS frozen at the moment of sale. This is what makes prior-year
		 * profit stable: a cost change updates the product field for future
		 * sales and never touches these rows.
		 */
		$order_cogs = self::table( 'order_cogs' );
		dbDelta(
			"CREATE TABLE {$order_cogs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				quantity decimal(16,4) NOT NULL DEFAULT 0,
				unit_cost decimal(16,6) DEFAULT NULL,
				line_cost decimal(16,6) DEFAULT NULL,
				cost_source varchar(32) DEFAULT NULL,
				captured_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_item_id (order_item_id),
				KEY order_id (order_id),
				KEY product_id (product_id)
			) {$collate};"
		);
	}
}
