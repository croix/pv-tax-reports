<?php
/**
 * Shared per-line profitability data source.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

use PoorVida\TaxReports\Cogs\OrderCogsRepository;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * One row per product line actually realized (net of refunds) within a date
 * range, with its frozen cost joined in from {prefix}pvtax_order_cogs.
 *
 * Shared by the product- and order-level profitability reports and the trend
 * summary, so the trickiest part — netting refunds correctly, and never
 * treating "no captured cost" as a cost of zero — is written once, not three
 * times.
 *
 * Refunds are netted per order item via WooCommerce's own
 * `get_qty_refunded_for_item()` / `get_total_refunded_for_item()`, wrapped in
 * `abs()` — WooCommerce's documented sign convention for these two methods is
 * inconsistent across its own sources, so this does not trust either sign and
 * instead always subtracts a positive refunded amount from a positive sold
 * amount. A fully refunded line is left out entirely; a partially refunded
 * one has its cost reduced by the same proportion as its quantity, which
 * assumes a refunded unit is normally restocked — the common case for a food
 * producer, but a stated simplification, not a claim about every refund.
 */
final class ProfitabilityLines {

	private const INCLUDED_STATUSES = [ 'completed', 'processing' ];

	/**
	 * Wire up the data source.
	 *
	 * @param OrderCogsRepository $order_cogs Frozen sale-time costs.
	 */
	public function __construct( private readonly OrderCogsRepository $order_cogs ) {}

	/**
	 * Every product line realized in the range, one row per line.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return iterable<array{order_id:int, order_number:string, date:string, product_id:int, quantity:float, revenue:float, unit_cost:?float, cost:?float}>
	 */
	public function for_range( string $start, string $end ): iterable {
		foreach ( $this->orders( $start, $end ) as $order ) {
			$cost_by_item = [];

			foreach ( $this->order_cogs->for_order( $order->get_id() ) as $row ) {
				$cost_by_item[ (int) $row->order_item_id ] = $row;
			}

			$date_created = $order->get_date_created();
			$date         = null !== $date_created ? $date_created->date( 'Y-m-d' ) : '';

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$qty_sold     = (float) $item->get_quantity();
				$qty_refunded = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
				$qty_net      = $qty_sold - $qty_refunded;

				if ( $qty_net <= 0.0 ) {
					continue;
				}

				$revenue_gross    = (float) $item->get_total();
				$revenue_refunded = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
				$revenue_net      = $revenue_gross - $revenue_refunded;

				$cost_row  = $cost_by_item[ (int) $item_id ] ?? null;
				$unit_cost = ( null !== $cost_row && null !== $cost_row->unit_cost ) ? (float) $cost_row->unit_cost : null;

				yield [
					'order_id'     => (int) $order->get_id(),
					'order_number' => $order->get_order_number(),
					'date'         => $date,
					'product_id'   => null !== $cost_row ? (int) $cost_row->product_id : $this->product_id_for_item( $item ),
					'quantity'     => $qty_net,
					'revenue'      => $revenue_net,
					'unit_cost'    => $unit_cost,
					'cost'         => null !== $unit_cost ? $unit_cost * $qty_net : null,
				];
			}
		}
	}

	/**
	 * Same product/variation ID convention `OrderCogsRecorder` itself uses —
	 * the sold item's own product object's ID, which is the variation ID for
	 * a variation, not WooCommerce's `get_product_id()` (always the parent).
	 * Only reached when this line has no captured cost row to read it from.
	 *
	 * @param WC_Order_Item_Product $item Order line item.
	 */
	private function product_id_for_item( WC_Order_Item_Product $item ): int {
		$product = $item->get_product();

		return $product instanceof WC_Product ? (int) $product->get_id() : (int) $item->get_product_id();
	}

	/**
	 * Every qualifying order in the range.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return iterable<WC_Order>
	 */
	private function orders( string $start, string $end ): iterable {
		$orders = wc_get_orders(
			[
				'limit'        => -1,
				'return'       => 'objects',
				'type'         => 'shop_order',
				'status'       => self::INCLUDED_STATUSES,
				'date_created' => $start . '...' . $end,
			]
		);

		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				yield $order;
			}
		}
	}
}
