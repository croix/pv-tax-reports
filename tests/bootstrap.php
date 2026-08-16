<?php
/**
 * PHPUnit bootstrap.
 *
 * These are unit tests: WordPress is not loaded, and its functions are mocked
 * with Brain Monkey. The plugin's logic is deliberately kept in classes that
 * can be exercised this way.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/fixtures/wp/' );
defined( 'PVTAX_FILE' ) || define( 'PVTAX_FILE', dirname( __DIR__ ) . '/pv-tax-reports.php' );
defined( 'PVTAX_DIR' ) || define( 'PVTAX_DIR', dirname( __DIR__ ) . '/' );
defined( 'PVTAX_URL' ) || define( 'PVTAX_URL', 'https://example.test/wp-content/plugins/pv-tax-reports/' );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

if ( ! defined( 'PoorVida\TaxReports\VERSION' ) ) {
	require_once __DIR__ . '/fixtures/constants.php';
}
