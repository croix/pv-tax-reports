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

	/**
	 * Calendar quarters, most recent first, starting with the one containing
	 * $today. The current quarter ends at $today, not at its calendar end —
	 * the rest of it hasn't happened yet.
	 *
	 * @param string $today Y-m-d.
	 * @param int    $count How many quarters.
	 *
	 * @return list<array{label:string, start:string, end:string, current:bool}>
	 */
	public static function quarters( string $today, int $count ): array {
		$year     = (int) substr( $today, 0, 4 );
		$quarter  = intdiv( (int) substr( $today, 5, 2 ) - 1, 3 ) + 1;
		$quarters = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$first_month = ( $quarter - 1 ) * 3 + 1;
			$start       = sprintf( '%04d-%02d-01', $year, $first_month );
			$end         = ( new \DateTimeImmutable( $start ) )->modify( '+3 months -1 day' )->format( 'Y-m-d' );

			$quarters[] = [
				'label'   => sprintf( 'Q%d %d', $quarter, $year ),
				'start'   => $start,
				'end'     => 0 === $i ? min( $end, $today ) : $end,
				'current' => 0 === $i,
			];

			if ( 1 === $quarter ) {
				$quarter = 4;
				--$year;
			} else {
				--$quarter;
			}
		}

		return $quarters;
	}
}
