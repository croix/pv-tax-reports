<?php
/**
 * One-time migration from a pre-native COGS plugin.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

use PoorVida\TaxReports\Support\ProductPager;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a cost from an older, pre-core COGS plugin into WooCommerce's own
 * native field, once, per product.
 *
 * The scope decision behind this plugin is that WooCommerce's own Cost of
 * Goods Sold field is the *only* cost of goods in play — no second field,
 * no second plugin. A store that adopted a cost plugin before WooCommerce's
 * own feature existed (SkyVerge's or YITH's Cost of Goods, both storing
 * under `_wc_cog_cost`) ends up with real cost data sitting in a field the
 * rest of this plugin — and WooCommerce itself — never looks at. This closes
 * that gap by copying it across, rather than teaching every reader about a
 * second source forever.
 *
 * Same preview-then-apply shape as the BOM sync, for the same reason: this
 * takes ownership of a field real historical work already lives in, and
 * overwriting it with no review would be a bad first impression. It never
 * overwrites a product that already has a native value — migrating one
 * product doesn't mean re-running this can clobber a value entered since.
 */
final class LegacyCogsMigrationService {

	public const PREVIEW_TRANSIENT = 'pvtax_legacy_cogs_preview';

	private const PREVIEW_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Wire up the migration.
	 *
	 * @param CostResolver $resolver Unit cost read/write.
	 */
	public function __construct( private readonly CostResolver $resolver ) {}

	/**
	 * Find every product with a legacy cost and no native one, without
	 * writing anything.
	 *
	 * @return array{ok:true, token:string, rows:list<array{product_id:int, sku:string, name:string, legacy_meta_key:string, legacy_value:float}>}|array{ok:false, error:string}
	 */
	public function build_preview(): array {
		if ( ! $this->resolver->cogs_api_available() ) {
			return [
				'ok'    => false,
				'error' => __( "WooCommerce's Cost of Goods Sold feature is not enabled, so there is nowhere native to migrate into. Enable it under WooCommerce → Settings → Advanced → Features first.", 'pv-tax-reports' ),
			];
		}

		$legacy_keys = $this->legacy_meta_keys();
		$rows        = [];

		foreach ( ProductPager::each() as $product ) {
			if ( '' === $product->get_sku() ) {
				continue;
			}

			$native = $this->resolver->for_product( $product );

			if ( null !== $native['cost'] ) {
				continue;
			}

			$found = self::find_legacy_value( $this->read_meta_values( $product, $legacy_keys ), $legacy_keys );

			if ( null === $found ) {
				continue;
			}

			$rows[] = [
				'product_id'      => (int) $product->get_id(),
				'sku'             => (string) $product->get_sku(),
				'name'            => $product->get_name(),
				'legacy_meta_key' => $found['key'],
				'legacy_value'    => $found['value'],
			];
		}

		$token = wp_generate_password( 24, false );

		$preview = [
			'token' => $token,
			'rows'  => $rows,
		];

		set_transient( self::PREVIEW_TRANSIENT, $preview, self::PREVIEW_TTL );

		return array_merge( [ 'ok' => true ], $preview );
	}

	/**
	 * The pending preview, if one is stored and has not expired.
	 *
	 * @return array<string, mixed>|null
	 */
	public function pending_preview(): ?array {
		$stored = get_transient( self::PREVIEW_TRANSIENT );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Write exactly what the previewed rows showed.
	 *
	 * @param string $token Token from the preview being applied.
	 *
	 * @return array{ok:true, migrated:int, skipped:int}|array{ok:false, error:string}
	 */
	public function apply( string $token ): array {
		$stored = get_transient( self::PREVIEW_TRANSIENT );

		if ( ! is_array( $stored ) || ( $stored['token'] ?? null ) !== $token ) {
			return [
				'ok'    => false,
				'error' => __( 'This preview has expired or was superseded. Preview again before applying.', 'pv-tax-reports' ),
			];
		}

		$migrated = 0;

		foreach ( $stored['rows'] as $row ) {
			$product = wc_get_product( $row['product_id'] );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// Re-check rather than trust the preview: don't clobber a native
			// value entered in the time since the preview was built.
			$native = $this->resolver->for_product( $product );

			if ( null !== $native['cost'] ) {
				continue;
			}

			$this->resolver->write( $product, $row['legacy_value'] );
			++$migrated;
		}

		delete_transient( self::PREVIEW_TRANSIENT );

		return [
			'ok'       => true,
			'migrated' => $migrated,
			'skipped'  => count( $stored['rows'] ) - $migrated,
		];
	}

	/**
	 * The first numeric value found across the configured legacy keys, in
	 * order — pure, so it can be tested without a real product.
	 *
	 * @param array<string, mixed> $meta_values Meta values keyed by meta key.
	 * @param array<int, string>   $legacy_keys Keys to check, in priority order.
	 *
	 * @return array{key:string, value:float}|null
	 */
	public static function find_legacy_value( array $meta_values, array $legacy_keys ): ?array {
		foreach ( $legacy_keys as $key ) {
			$value = $meta_values[ $key ] ?? null;

			if ( is_numeric( $value ) ) {
				return [
					'key'   => $key,
					'value' => (float) $value,
				];
			}
		}

		return null;
	}

	/**
	 * Read a batch of meta keys off a product in one place.
	 *
	 * @param WC_Product         $product Product.
	 * @param array<int, string> $keys    Meta keys to read.
	 *
	 * @return array<string, mixed>
	 */
	private function read_meta_values( WC_Product $product, array $keys ): array {
		$values = [];

		foreach ( $keys as $key ) {
			$values[ $key ] = $product->get_meta( $key, true );
		}

		return $values;
	}

	/**
	 * Meta keys checked for a legacy cost, in priority order.
	 *
	 * @return list<string>
	 */
	private function legacy_meta_keys(): array {
		/**
		 * Meta keys checked for a cost from a pre-native COGS plugin.
		 *
		 * Default is `_wc_cog_cost`, used by both SkyVerge's and YITH's
		 * "WooCommerce Cost of Goods" plugins.
		 *
		 * @param list<string> $keys Meta keys, in priority order.
		 */
		$keys = apply_filters( 'pvtax_legacy_cogs_meta_keys', [ '_wc_cog_cost' ] );

		return is_array( $keys ) ? array_map( 'strval', $keys ) : [ '_wc_cog_cost' ];
	}
}
