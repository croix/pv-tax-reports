<?php
/**
 * Uninstall handler.
 *
 * Deliberately removes settings only. The snapshot, cost and order-COGS tables
 * hold history that cannot be reconstructed after the fact, and which may be
 * needed to support a filed return. Dropping them is a manual decision, not a
 * side effect of deleting a plugin.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pvtax_settings' );
delete_option( 'pvtax_last_snapshot' );
delete_option( 'pvtax_db_version' );
delete_site_transient( 'pvtax_latest_release' );
