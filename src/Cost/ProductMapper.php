<?php
/**
 * WooCommerce product to BOM package option matching.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

defined( 'ABSPATH' ) || exit;

/**
 * Pure matching logic — no WordPress or WooCommerce calls, so it can be
 * exercised directly in tests.
 *
 * A WooCommerce SKU is sometimes the MPN and sometimes the UPC, so a product
 * is checked against both. Resolution order, per the design decision: exact
 * SKU → MPN, then exact SKU → UPC, then a manual override stored per product.
 * No fuzzy matching — a wrong cost is worse than an obvious gap.
 */
final class ProductMapper {

	/**
	 * Product meta key holding a manually chosen BOM `packageOptionId`.
	 *
	 * `packageOptionId` is used rather than MPN or UPC because it survives an
	 * MPN correction on the BOM side, which a mapping keyed on MPN would not.
	 */
	public const OVERRIDE_META_KEY = '_pvtax_bom_package_option_id';

	/**
	 * Match every product against the pulled BOM options.
	 *
	 * @param list<array<string, mixed>>                                $bom_options BOM package options.
	 * @param list<array{product_id:int, sku:string, override:?string}> $products Candidate products.
	 *
	 * @return array{
	 *     matched: list<array{product_id:int, sku:string, matched_via:string, option:array<string, mixed>}>,
	 *     unmapped_options: list<array<string, mixed>>,
	 *     unmapped_products: list<array{product_id:int, sku:string, override:?string}>
	 * }
	 */
	public static function match( array $bom_options, array $products ): array {
		$by_mpn               = [];
		$by_upc               = [];
		$by_package_option_id = [];

		foreach ( $bom_options as $option ) {
			$mpn        = self::string_or_null( $option['mpn'] ?? null );
			$upc        = self::string_or_null( $option['upc'] ?? null );
			$package_id = self::string_or_null( $option['packageOptionId'] ?? null );

			if ( null !== $mpn ) {
				$by_mpn[ $mpn ] = $option;
			}

			if ( null !== $upc ) {
				$by_upc[ $upc ] = $option;
			}

			if ( null !== $package_id ) {
				$by_package_option_id[ $package_id ] = $option;
			}
		}

		$matched            = [];
		$unmapped_products  = [];
		$claimed_option_ids = [];

		foreach ( $products as $product ) {
			$sku         = $product['sku'];
			$option      = null;
			$matched_via = '';

			if ( '' !== $sku && isset( $by_mpn[ $sku ] ) ) {
				$option      = $by_mpn[ $sku ];
				$matched_via = 'mpn';
			} elseif ( '' !== $sku && isset( $by_upc[ $sku ] ) ) {
				$option      = $by_upc[ $sku ];
				$matched_via = 'upc';
			} elseif ( null !== $product['override'] && isset( $by_package_option_id[ $product['override'] ] ) ) {
				$option      = $by_package_option_id[ $product['override'] ];
				$matched_via = 'override';
			}

			if ( null === $option ) {
				/*
				 * The override is carried through even though it did not match:
				 * a non-null value here means the product has a stored override
				 * that no longer points at a pulled option — worth surfacing as
				 * a typo or a discontinued item, not just "unmapped".
				 */
				$unmapped_products[] = [
					'product_id' => $product['product_id'],
					'sku'        => $sku,
					'override'   => $product['override'],
				];

				continue;
			}

			$matched[] = [
				'product_id'  => $product['product_id'],
				'sku'         => $sku,
				'matched_via' => $matched_via,
				'option'      => $option,
			];

			$package_id = self::string_or_null( $option['packageOptionId'] ?? null );

			if ( null !== $package_id ) {
				$claimed_option_ids[ $package_id ] = true;
			}
		}

		$unmapped_options = [];

		foreach ( $bom_options as $option ) {
			$package_id = self::string_or_null( $option['packageOptionId'] ?? null );

			if ( null !== $package_id && isset( $claimed_option_ids[ $package_id ] ) ) {
				continue;
			}

			$unmapped_options[] = $option;
		}

		return [
			'matched'           => $matched,
			'unmapped_options'  => $unmapped_options,
			'unmapped_products' => $unmapped_products,
		];
	}

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value Candidate value.
	 */
	private static function string_or_null( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}
}
