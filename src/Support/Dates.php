<?php
/**
 * Date helpers.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Site-local date handling.
 *
 * A snapshot is dated by the store's own calendar day, not UTC. Getting this
 * wrong silently shifts every valuation by a day for stores west of UTC.
 */
final class Dates {

	/**
	 * Today's date in the site's timezone, as Y-m-d.
	 */
	public static function today(): string {
		$date = wp_date( 'Y-m-d' );

		return false === $date ? gmdate( 'Y-m-d' ) : $date;
	}

	/**
	 * Current UTC timestamp as a MySQL datetime, for created_at/captured_at columns.
	 */
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Validate a Y-m-d string, returning null when it is not a real date.
	 *
	 * @param string $value Candidate date.
	 */
	public static function normalize_date( string $value ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $value;
	}
}
