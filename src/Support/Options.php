<?php
/**
 * Settings storage.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors over the single settings option.
 */
final class Options {

	public const OPTION = 'pvtax_settings';

	/**
	 * Defaults, also the shape of the stored array.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			// Deliberately empty. This is a public repository, and hardcoding
			// the host would advertise it for no benefit; it is entered once on
			// the settings screen.
			'bom_url'             => '',
			'api_key'             => '',
			'snapshot_time'       => '23:45',
			'cogs_meta_key'       => '_cogs_value',
			'github_repo'         => 'croix/pv-tax-reports',
			'excluded_categories' => '',
		];
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return array_merge( self::defaults(), array_map( 'strval', $stored ) );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 */
	public static function get( string $key ): string {
		$all = self::all();

		return $all[ $key ] ?? '';
	}

	/**
	 * BOM base URL with any trailing slash removed.
	 */
	public static function bom_url(): string {
		return untrailingslashit( self::get( 'bom_url' ) );
	}

	/**
	 * BOM API key.
	 *
	 * A `PVTAX_BOM_API_KEY` constant in wp-config.php wins over the stored
	 * option, so the key can be kept out of the database entirely.
	 */
	public static function api_key(): string {
		if ( defined( 'PVTAX_BOM_API_KEY' ) && is_string( PVTAX_BOM_API_KEY ) && '' !== PVTAX_BOM_API_KEY ) {
			return PVTAX_BOM_API_KEY;
		}

		return self::get( 'api_key' );
	}

	/**
	 * Whether the API key came from wp-config.php rather than the database.
	 */
	public static function api_key_is_constant(): bool {
		return defined( 'PVTAX_BOM_API_KEY' ) && is_string( PVTAX_BOM_API_KEY ) && '' !== PVTAX_BOM_API_KEY;
	}

	/**
	 * Daily snapshot time as [hour, minute] in site local time.
	 *
	 * @return array{0:int, 1:int}
	 */
	public static function snapshot_time(): array {
		$raw = self::get( 'snapshot_time' );

		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $raw, $m ) ) {
			return [ 23, 45 ];
		}

		return [ (int) $m[1], (int) $m[2] ];
	}

	/**
	 * Product meta key to read cost from when WooCommerce's COGS API is absent.
	 */
	public static function cogs_meta_key(): string {
		$key = trim( self::get( 'cogs_meta_key' ) );

		return '' !== $key ? $key : '_cogs_value';
	}

	/**
	 * Product category slugs excluded from the cost sync entirely — e.g.
	 * merch that isn't costed from BOM at all.
	 *
	 * @return list<string>
	 */
	public static function excluded_category_slugs(): array {
		$raw = trim( self::get( 'excluded_categories' ) );

		if ( '' === $raw ) {
			return [];
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Persist a settings array, keeping unknown keys out.
	 *
	 * @param array<string, mixed> $values Raw values.
	 */
	public static function update( array $values ): void {
		$clean = [];

		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$clean[ $key ] = (string) $values[ $key ];
			}
		}

		update_option( self::OPTION, array_merge( self::all(), $clean ) );
	}
}
