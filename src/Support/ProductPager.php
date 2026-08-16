<?php
/**
 * Paged iteration over every product and variation.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Support;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * `wc_get_products()` a page at a time, in a stable order.
 *
 * Shared by the snapshotter and the cost sync — both need every product and
 * variation in the store, differing only in which ones they keep.
 */
final class ProductPager {

	private const PAGE_SIZE = 200;

	/**
	 * Yield every product and variation, in any status a real sale could touch.
	 *
	 * @return iterable<WC_Product>
	 */
	public static function each(): iterable {
		$types = array_merge( array_keys( wc_get_product_types() ), [ 'variation' ] );
		$page  = 1;

		do {
			$batch = wc_get_products(
				[
					'limit'   => self::PAGE_SIZE,
					'page'    => $page,
					'status'  => [ 'publish', 'private', 'draft' ],
					'type'    => $types,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				]
			);

			if ( ! is_array( $batch ) ) {
				return;
			}

			$fetched = count( $batch );

			foreach ( $batch as $product ) {
				if ( $product instanceof WC_Product ) {
					yield $product;
				}
			}

			++$page;
		} while ( self::PAGE_SIZE === $fetched );
	}
}
