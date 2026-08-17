<?php
/**
 * CSV download helper.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a CSV file to the browser and ends the request.
 *
 * Must run before any HTML output has started — hooked from `admin_init`,
 * not from a page's own render(), since by the time a submenu page callback
 * runs, WordPress has already sent the admin page's own headers.
 */
final class Csv {

	/**
	 * Send a CSV and terminate the request.
	 *
	 * @param string                   $filename Suggested download filename.
	 * @param array<int, string>       $header   Column headers.
	 * @param array<int, array<mixed>> $rows     Row data; each cell is cast to string.
	 */
	public static function download( string $filename, array $header, array $rows ): never {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// A CSV export is a raw byte stream to the HTTP response, not a file
		// on disk — WP_Filesystem has no bearing on php://output.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( 'php://output', 'w' );

		if ( false !== $handle ) {
			fputcsv( $handle, $header );

			foreach ( $rows as $row ) {
				fputcsv( $handle, array_map( 'strval', $row ) );
			}

			fclose( $handle );
		}
		// phpcs:enable

		exit;
	}
}
