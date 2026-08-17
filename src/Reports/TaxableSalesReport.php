<?php
/**
 * Taxable sales for a date range.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

use WC_Abstract_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Gross sales, taxable sales, and tax collected — broken out by rate.
 *
 * Reads through WooCommerce's own order CRUD (`wc_get_orders()`,
 * `$order->get_items()`, `$item->get_taxes()`), never raw SQL against
 * `posts` — the site may be on HPOS, where orders live in `wc_orders`
 * instead, and hand-rolled SQL would silently return nothing.
 */
final class TaxableSalesReport {

	/**
	 * Order statuses that represent a real sale. Cancelled and failed orders
	 * never happened as far as a tax return is concerned.
	 */
	private const INCLUDED_STATUSES = [ 'completed', 'processing' ];

	/**
	 * Order item types checked for taxability — a taxable fee or shipping
	 * charge belongs in the figure on the return just as much as a product
	 * line does.
	 */
	private const ITEM_TYPES = [ 'line_item', 'shipping', 'fee' ];

	/**
	 * Totals for a date range.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return array{ok:true, start:string, end:string, gross_sales:float, taxable_sales:float, tax_collected:float, by_rate:list<array{rate_id:int, label:string, taxable_sales:float, tax_collected:float}>}|array{ok:false, error:string}
	 */
	public function for_range( string $start, string $end ): array {
		if ( $start > $end ) {
			return [
				'ok'    => false,
				'error' => __( 'The start date must be on or before the end date.', 'pv-tax-reports' ),
			];
		}

		$totals = [
			'gross_sales'   => 0.0,
			'taxable_sales' => 0.0,
			'tax_collected' => 0.0,
			'by_rate'       => [],
		];

		foreach ( $this->orders_and_refunds( $start, $end ) as $order ) {
			foreach ( self::ITEM_TYPES as $type ) {
				foreach ( $order->get_items( $type ) as $item ) {
					if (
						! $item instanceof WC_Order_Item_Product
						&& ! $item instanceof WC_Order_Item_Shipping
						&& ! $item instanceof WC_Order_Item_Fee
					) {
						continue;
					}

					$net        = (float) $item->get_total();
					$rate_taxes = $this->tax_by_rate( $item->get_taxes() );

					$totals = self::accumulate( $net, $rate_taxes, $totals );
				}
			}
		}

		$by_rate = [];

		foreach ( $totals['by_rate'] as $rate_id => $rate_totals ) {
			$by_rate[] = [
				'rate_id'       => (int) $rate_id,
				'label'         => $this->rate_label( (int) $rate_id ),
				'taxable_sales' => $rate_totals['taxable_sales'],
				'tax_collected' => $rate_totals['tax_collected'],
			];
		}

		return [
			'ok'            => true,
			'start'         => $start,
			'end'           => $end,
			'gross_sales'   => $totals['gross_sales'],
			'taxable_sales' => $totals['taxable_sales'],
			'tax_collected' => $totals['tax_collected'],
			'by_rate'       => $by_rate,
		];
	}

	/**
	 * Fold one line's contribution into running totals.
	 *
	 * Pure — no WordPress or database calls — so the actual accounting rules
	 * (an untaxed line counts toward gross but not taxable; a negative
	 * refund amount nets out by straightforward addition; a line taxed under
	 * two overlapping rates counts its full base under both, since a state
	 * row and a local row on a return can both claim the same sale) can be
	 * tested directly, without a live order.
	 *
	 * @param float                                                                                                                                          $net         Line net amount (post-discount, pre-tax); negative for a refund.
	 * @param array<int|string, float>                                                                                                                       $tax_by_rate Tax rate ID => tax amount for this line; negative for a refund.
	 * @param array{gross_sales:float, taxable_sales:float, tax_collected:float, by_rate:array<int|string, array{taxable_sales:float, tax_collected:float}>} $totals Running totals.
	 *
	 * @return array{gross_sales:float, taxable_sales:float, tax_collected:float, by_rate:array<int|string, array{taxable_sales:float, tax_collected:float}>}
	 */
	public static function accumulate( float $net, array $tax_by_rate, array $totals ): array {
		$totals['gross_sales'] += $net;

		$line_taxed = false;

		foreach ( $tax_by_rate as $rate_id => $amount ) {
			$amount = (float) $amount;

			if ( 0.0 === $amount ) {
				continue;
			}

			$line_taxed               = true;
			$totals['tax_collected'] += $amount;

			if ( ! isset( $totals['by_rate'][ $rate_id ] ) ) {
				$totals['by_rate'][ $rate_id ] = [
					'taxable_sales' => 0.0,
					'tax_collected' => 0.0,
				];
			}

			$totals['by_rate'][ $rate_id ]['taxable_sales'] += $net;
			$totals['by_rate'][ $rate_id ]['tax_collected'] += $amount;
		}

		if ( $line_taxed ) {
			$totals['taxable_sales'] += $net;
		}

		return $totals;
	}

	/**
	 * Every order and refund whose own date falls in the range.
	 *
	 * A refund is netted against the period it happened in, not the period
	 * of the original sale — the same way a sales tax return recognizes a
	 * reversal in the period it occurred, not by amending an earlier one.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return iterable<WC_Abstract_Order>
	 */
	private function orders_and_refunds( string $start, string $end ): iterable {
		$common = [
			'limit'        => -1,
			'return'       => 'objects',
			'date_created' => $start . '...' . $end,
		];

		$orders = wc_get_orders(
			array_merge(
				$common,
				[
					'type'   => 'shop_order',
					'status' => self::INCLUDED_STATUSES,
				]
			)
		);

		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Abstract_Order ) {
				yield $order;
			}
		}

		$refunds = wc_get_orders( array_merge( $common, [ 'type' => 'shop_order_refund' ] ) );

		foreach ( (array) $refunds as $refund ) {
			if ( $refund instanceof WC_Abstract_Order ) {
				yield $refund;
			}
		}
	}

	/**
	 * Tax rate ID => tax amount, from an order item's raw tax breakdown.
	 *
	 * @param array<string, mixed> $taxes Result of `$item->get_taxes()`.
	 *
	 * @return array<int|string, float>
	 */
	private function tax_by_rate( array $taxes ): array {
		$total = is_array( $taxes['total'] ?? null ) ? $taxes['total'] : [];

		return array_map( 'floatval', $total );
	}

	/**
	 * Human-readable label for a tax rate, via WooCommerce's own tax rate
	 * store — not a hand-rolled query against its table.
	 *
	 * @param int $rate_id Tax rate ID.
	 */
	private function rate_label( int $rate_id ): string {
		$label = WC_Tax::get_rate_label( $rate_id );

		/* translators: %d: tax rate ID. */
		return '' !== $label ? $label : sprintf( __( 'Rate #%d', 'pv-tax-reports' ), $rate_id );
	}
}
