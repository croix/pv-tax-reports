<?php
/**
 * Unit cost lookup.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

use PoorVida\TaxReports\Support\Options;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what does one of these cost right now".
 *
 * WooCommerce's own Cost of Goods Sold field is the only cost of goods in use
 * on this store, so it is the canonical read. Where that value comes from is a
 * separate question — hand-entered, or written by the BOM sync via write()
 * below — and nothing downstream needs to care which.
 */
final class CostResolver {

	public const SOURCE_WC_COGS = 'wc_cogs';
	public const SOURCE_META    = 'meta';
	public const SOURCE_FILTER  = 'filter';
	public const SOURCE_NONE    = 'none';

	/**
	 * Resolve the current unit cost for a product.
	 *
	 * @param WC_Product $product Product or variation.
	 *
	 * @return array{cost: float|null, source: string} Cost is null when none is on file.
	 */
	public function for_product( WC_Product $product ): array {
		$resolved = $this->read( $product );

		/**
		 * Override the resolved unit cost for a product.
		 *
		 * The BOM sync does not need this — it writes straight to whichever
		 * field {@see read()} consults, via write(). This is the escape hatch
		 * for anything costed some other way.
		 *
		 * @param array{cost: float|null, source: string} $resolved Resolved cost.
		 * @param WC_Product                              $product  Product.
		 */
		$filtered = apply_filters( 'pvtax_product_unit_cost', $resolved, $product );

		if ( ! is_array( $filtered ) || ! array_key_exists( 'cost', $filtered ) ) {
			return $resolved;
		}

		$cost = null === $filtered['cost'] ? null : (float) $filtered['cost'];

		return [
			'cost'   => $cost,
			'source' => isset( $filtered['source'] ) ? (string) $filtered['source'] : self::SOURCE_FILTER,
		];
	}

	/**
	 * Read the cost off the product, preferring WooCommerce's API.
	 *
	 * @param WC_Product $product Product or variation.
	 *
	 * @return array{cost: float|null, source: string}
	 */
	private function read( WC_Product $product ): array {
		/*
		 * WooCommerce's COGS support is feature-flagged, and the flag only
		 * reports enabled on versions where get_cogs_value() exists — the two
		 * ship together. Preferring the API over raw meta means a storage
		 * change on their side does not silently break this.
		 */
		if ( $this->cogs_api_available() ) {
			$value = $product->get_cogs_value();

			if ( is_numeric( $value ) ) {
				return [
					'cost'   => (float) $value,
					'source' => self::SOURCE_WC_COGS,
				];
			}

			// The API is present and simply has no value for this product.
			return [
				'cost'   => null,
				'source' => self::SOURCE_NONE,
			];
		}

		$meta = $product->get_meta( Options::cogs_meta_key(), true );

		if ( is_numeric( $meta ) ) {
			return [
				'cost'   => (float) $meta,
				'source' => self::SOURCE_META,
			];
		}

		return [
			'cost'   => null,
			'source' => self::SOURCE_NONE,
		];
	}

	/**
	 * Write a resolved unit cost to the product.
	 *
	 * This is the sync's half of the story: it takes ownership of whichever
	 * field {@see for_product()} reads from, so a value written here is read
	 * back unchanged by every other part of the plugin.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param float      $cost    New unit cost.
	 */
	public function write( WC_Product $product, float $cost ): void {
		if ( $this->cogs_api_available() ) {
			$product->set_cogs_value( $cost );
		} else {
			$product->update_meta_data( Options::cogs_meta_key(), (string) $cost );
		}

		$product->save();
	}

	/**
	 * Whether WooCommerce's Cost of Goods Sold feature is present and enabled.
	 */
	public function cogs_api_available(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return false;
		}

		return (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' );
	}

	/**
	 * Human-readable description of where costs are being read from, for the
	 * settings and tools screens.
	 */
	public function describe_source(): string {
		if ( $this->cogs_api_available() ) {
			return __( "WooCommerce's Cost of Goods Sold field", 'pv-tax-reports' );
		}

		/* translators: %s: product meta key. */
		return sprintf( __( 'Product meta key %s (WooCommerce COGS is unavailable or disabled)', 'pv-tax-reports' ), Options::cogs_meta_key() );
	}
}
