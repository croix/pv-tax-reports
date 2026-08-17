<?php
/**
 * Plugin Name:       Poor Vida Tax Reports
 * Plugin URI:        https://github.com/croix/pv-tax-reports
 * Description:       Inventory valuation as of a date and taxable sales reporting for WooCommerce, costed from BOM.
 * Version:           0.5.3
 * Update URI:        https://github.com/croix/pv-tax-reports
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Poor Vida
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pv-tax-reports
 * Requires Plugins:  woocommerce
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.5.3';
const DB_VERSION = 2;

define( 'PVTAX_FILE', __FILE__ );
define( 'PVTAX_DIR', plugin_dir_path( __FILE__ ) );
define( 'PVTAX_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader.
 *
 * The plugin has no runtime Composer dependencies on purpose: a release zip is
 * just the source tree, with no vendor directory to get out of sync.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = PVTAX_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, [ Support\Schema::class, 'install' ] );
register_deactivation_hook( __FILE__, [ Snapshots\Scheduler::class, 'unschedule' ] );

Plugin::instance()->boot();
