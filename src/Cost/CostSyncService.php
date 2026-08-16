<?php
/**
 * Cost sync orchestration.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

use PoorVida\TaxReports\Support\ProductPager;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls costs from BOM, matches them to products, and writes them — always
 * behind a preview.
 *
 * The first sync, and every sync after it, is two calls: build_preview() pulls
 * from BOM and computes what would change without writing anything, holding
 * the plan in a transient; apply() writes exactly that plan. Apply never
 * re-fetches from BOM, so what gets written is what was shown, even if BOM's
 * prices moved in the meantime — the preview is a commitment, not just a
 * warning.
 */
final class CostSyncService {

	public const PREVIEW_TRANSIENT = 'pvtax_sync_preview';

	private const PREVIEW_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Wire up the sync.
	 *
	 * @param BomApiClient   $client   BOM HTTP client.
	 * @param CostRepository $costs    Cost cache storage.
	 * @param CostResolver   $resolver Unit cost read/write.
	 */
	public function __construct(
		private readonly BomApiClient $client,
		private readonly CostRepository $costs,
		private readonly CostResolver $resolver,
	) {}

	/**
	 * Fetch from BOM, match against products, and store the plan for review.
	 *
	 * @return array{ok:true, token:string, as_of:string, currency:string, matched:list<array<string, mixed>>, unmapped_options:list<array<string, mixed>>, unmapped_products:list<array<string, mixed>>}|array{ok:false, error:string}
	 */
	public function build_preview(): array {
		$fetch = $this->client->fetch();

		if ( ! $fetch['ok'] ) {
			return $fetch;
		}

		$product_rows   = [];
		$products_by_id = [];

		foreach ( $this->eligible_products() as $product ) {
			$id       = (int) $product->get_id();
			$override = (string) $product->get_meta( ProductMapper::OVERRIDE_META_KEY, true );

			$product_rows[] = [
				'product_id' => $id,
				'sku'        => (string) $product->get_sku(),
				'override'   => '' !== $override ? $override : null,
			];

			$products_by_id[ $id ] = $product;
		}

		$plan = ProductMapper::match( $fetch['options'], $product_rows );

		$matched = [];

		foreach ( $plan['matched'] as $item ) {
			$product = $products_by_id[ $item['product_id'] ];
			$old     = $this->resolver->for_product( $product );

			$matched[] = [
				'product_id'  => $item['product_id'],
				'sku'         => $item['sku'],
				'name'        => $product->get_name(),
				'matched_via' => $item['matched_via'],
				'old_cost'    => $old['cost'],
				'new_cost'    => $this->numeric_or_null( $item['option']['costPerContainer'] ?? null ),
				'option'      => $item['option'],
			];
		}

		$unmapped_products = [];

		foreach ( $plan['unmapped_products'] as $item ) {
			$product = $products_by_id[ $item['product_id'] ];

			$unmapped_products[] = [
				'product_id' => $item['product_id'],
				'sku'        => $item['sku'],
				'name'       => $product->get_name(),
			];
		}

		$token = wp_generate_password( 24, false );

		$preview = [
			'token'             => $token,
			'as_of'             => $fetch['as_of'],
			'currency'          => $fetch['currency'],
			'matched'           => $matched,
			'unmapped_options'  => $plan['unmapped_options'],
			'unmapped_products' => $unmapped_products,
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
	 * Write the previewed plan: the product's cost field for every matched
	 * product, and an archive row in the cost cache for every pulled option.
	 *
	 * @param string $token Token from the preview being applied, guarding
	 *                      against applying a stale or mismatched preview.
	 *
	 * @return array{ok:true, updated:int, unmapped_options:int, unmapped_products:int}|array{ok:false, error:string}
	 */
	public function apply( string $token ): array {
		$stored = get_transient( self::PREVIEW_TRANSIENT );

		if ( ! is_array( $stored ) || ( $stored['token'] ?? null ) !== $token ) {
			return [
				'ok'    => false,
				'error' => __( 'This preview has expired or was superseded. Preview again before applying.', 'pv-tax-reports' ),
			];
		}

		$updated = 0;
		$rows    = [];

		foreach ( $stored['matched'] as $item ) {
			if ( null !== $item['new_cost'] ) {
				$product = wc_get_product( $item['product_id'] );

				if ( $product instanceof WC_Product ) {
					$this->resolver->write( $product, $item['new_cost'] );
					++$updated;
				}
			}

			$rows[] = $this->cost_row( $item['option'], $item['product_id'] );
		}

		foreach ( $stored['unmapped_options'] as $option ) {
			$rows[] = $this->cost_row( $option, null );
		}

		$this->costs->insert_pull( $rows );

		delete_transient( self::PREVIEW_TRANSIENT );

		return [
			'ok'                => true,
			'updated'           => $updated,
			'unmapped_options'  => count( $stored['unmapped_options'] ),
			'unmapped_products' => count( $stored['unmapped_products'] ),
		];
	}

	/**
	 * Build a cost cache row from a BOM option.
	 *
	 * @param array<string, mixed> $option     BOM package option.
	 * @param int|null             $product_id Matched product, if any.
	 *
	 * @return array{mpn:?string, upc:?string, package_option_id:?string, product_id:?int, cost_per_container:?float, ingredient_cost:?float, packaging_cost:?float}
	 */
	private function cost_row( array $option, ?int $product_id ): array {
		return [
			'mpn'                => is_string( $option['mpn'] ?? null ) ? $option['mpn'] : null,
			'upc'                => is_string( $option['upc'] ?? null ) ? $option['upc'] : null,
			'package_option_id'  => is_string( $option['packageOptionId'] ?? null ) ? $option['packageOptionId'] : null,
			'product_id'         => $product_id,
			'cost_per_container' => $this->numeric_or_null( $option['costPerContainer'] ?? null ),
			'ingredient_cost'    => $this->numeric_or_null( $option['ingredientCost'] ?? null ),
			'packaging_cost'     => $this->numeric_or_null( $option['packagingCost'] ?? null ),
		];
	}

	/**
	 * A float, or null when the value is not numeric.
	 *
	 * @param mixed $value Candidate value.
	 */
	private function numeric_or_null( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Products worth checking against BOM: anything with a SKU or a manual
	 * override, regardless of whether it manages stock — cost applies whether
	 * or not the snapshotter is tracking quantity for it.
	 *
	 * @return iterable<WC_Product>
	 */
	private function eligible_products(): iterable {
		foreach ( ProductPager::each() as $product ) {
			$sku      = $product->get_sku();
			$override = $product->get_meta( ProductMapper::OVERRIDE_META_KEY, true );

			if ( '' === $sku && '' === $override ) {
				continue;
			}

			yield $product;
		}
	}
}
