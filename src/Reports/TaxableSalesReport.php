<?php
/**
 * Texas sales-tax figures for a date range.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Reports;

use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Order_Refund;
use WC_Product;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/**
 * Total Texas sales, taxable sales, exempt sales, and tax collected — the
 * figures a Texas sales and use tax return asks for — with every line kept
 * so the totals can be audited back to orders.
 *
 * Reads through WooCommerce's own order CRUD (`wc_get_orders()`,
 * `$order->get_items()`, `$item->get_taxes()`), never raw SQL against
 * `posts` — the site may be on HPOS, where orders live in `wc_orders`
 * instead, and hand-rolled SQL would silently return nothing.
 *
 * The accounting itself is two pure steps, testable without WordPress:
 * {@see self::classify()} turns one order or refund into categorized lines,
 * and {@see self::summarize()} totals them.
 *
 * Rules, each of which a previous version of this report got wrong:
 *
 * - **Whether a sale is taxable is a fact about the product, not about
 *   whether tax was charged.** Most of the catalog is exempt food (Comptroller
 *   Pub 96-280: sauces, salsas, spices); the store nevertheless charged tax on
 *   it. A product line is taxable when the product's WooCommerce tax status
 *   is "Taxable" (and not the Zero rate class). An exempt line that had tax
 *   charged anyway is reported separately: that tax was collected in error
 *   and still has to be remitted, so on the return those sales are reported
 *   as taxable too (`return_taxable_sales`).
 * - **Shipping and handling on taxable items is part of the taxable sales
 *   price.** On a mixed order it is allocated in proportion to the item
 *   value, taxable versus exempt.
 * - **Texas sales are sales delivered in Texas, made directly** — orders
 *   from a marketplace (Amazon) are the marketplace provider's to report,
 *   and an order shipped out of state is not a Texas sale. Both are shown,
 *   but neither is in the Texas totals.
 * - **A jurisdiction's label comes from the order's own tax line**, as
 *   recorded at checkout — not from looking the rate ID up in today's tax
 *   table, where the same ID may since have been reused or deleted.
 *   (Amazon's imported tax line carries rate ID 1, which in the store's own
 *   table is a Carrollton rate; looking it up turned Amazon's tax into a
 *   phantom "City Tax" row.)
 * - **A refund only nets against an order the report counts.** Fully
 *   refunded orders are counted (status "refunded"), so a sale and its
 *   refund in the same period net to zero instead of leaving an orphan
 *   negative. A refund reduces the taxable base as well as the tax.
 */
final class TaxableSalesReport {

	/**
	 * Order statuses that represent a real sale. "refunded" is included so
	 * that its refund has an order to net against; cancelled, failed,
	 * pending, and on-hold orders never happened as far as a tax return is
	 * concerned.
	 */
	private const INCLUDED_STATUSES = [ 'completed', 'processing', 'refunded' ];

	/**
	 * Report categories, in display order.
	 */
	public const CATEGORY_TAXABLE      = 'taxable';
	public const CATEGORY_EXEMPT_TAXED = 'exempt_taxed';
	public const CATEGORY_EXEMPT       = 'exempt';
	public const CATEGORY_OUT_OF_STATE = 'out_of_state';
	public const CATEGORY_MARKETPLACE  = 'marketplace';

	/**
	 * Every category, in display order.
	 */
	public const CATEGORIES = [
		self::CATEGORY_TAXABLE,
		self::CATEGORY_EXEMPT_TAXED,
		self::CATEGORY_EXEMPT,
		self::CATEGORY_OUT_OF_STATE,
		self::CATEGORY_MARKETPLACE,
	];

	/**
	 * Categories that make up Total Texas Sales.
	 */
	public const TEXAS_CATEGORIES = [
		self::CATEGORY_TAXABLE,
		self::CATEGORY_EXEMPT_TAXED,
		self::CATEGORY_EXEMPT,
	];

	/**
	 * `created_via` fragments that identify a marketplace order. Matched
	 * case-insensitively as substrings. Filterable via
	 * `pvtax_marketplace_channels`.
	 */
	private const MARKETPLACE_CHANNELS = [ 'amazon', 'ebay', 'walmart', 'etsy', 'tiktok' ];

	/**
	 * Report for a date range.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return array{ok:true, start:string, end:string, totals:array<string, float>, by_category:array<string, array{sales:float, tax:float}>, by_jurisdiction:list<array{label:string, rate_percent:?float, taxable_base:float, tax:float}>, lines:list<array<string, mixed>>}|array{ok:false, error:string}
	 */
	public function for_range( string $start, string $end ): array {
		if ( $start > $end ) {
			return [
				'ok'    => false,
				'error' => __( 'The start date must be on or before the end date.', 'pv-tax-reports' ),
			];
		}

		$lines = [];

		foreach ( $this->orders_and_refunds( $start, $end ) as $order ) {
			array_push( $lines, ...self::classify( $order ) );
		}

		return [
			'ok'    => true,
			'start' => $start,
			'end'   => $end,
			'lines' => $lines,
		] + self::summarize( $lines );
	}

	/**
	 * Split one order or refund into categorized report lines.
	 *
	 * Pure. The input is a plain description of the order (built from
	 * WooCommerce by {@see self::describe()}):
	 *
	 * - order_id, parent_id (0 for an order), kind ('order'|'refund'), date
	 * - marketplace (bool), texas (bool), state (string)
	 * - taxable_share: ?float — the share of item value that is taxable, used
	 *   to split shipping and fees. Null means "work it out from this order's
	 *   own item lines"; a refund passes its parent order's share, since a
	 *   shipping-only refund has no items of its own to go by.
	 * - lines: list of {type, name, net, taxable (?bool, product lines only),
	 *   taxes (jurisdiction label => amount), note}
	 *
	 * Each output line carries its category, net amount, and tax by
	 * jurisdiction. A shipping or fee line on a mixed Texas order comes out as
	 * two lines — the taxable share and the exempt share — so every output
	 * line has exactly one category and the lines sum to the totals.
	 *
	 * @param array<string, mixed> $order Order description.
	 *
	 * @return list<array{order_id:int, parent_id:int, kind:string, date:string, state:string, type:string, name:string, category:string, net:float, tax:float, taxes:array<string, float>, note:string}>
	 */
	public static function classify( array $order ): array {
		$share = $order['taxable_share'] ?? null;
		$share = null === $share ? self::taxable_share( $order['lines'] ) : (float) $share;
		$out   = [];

		foreach ( $order['lines'] as $line ) {
			$taxes = array_filter(
				array_map( 'floatval', $line['taxes'] ?? [] ),
				static fn ( float $amount ): bool => 0.0 !== $amount
			);

			$base = [
				'order_id'  => (int) $order['order_id'],
				'parent_id' => (int) ( $order['parent_id'] ?? 0 ),
				'kind'      => (string) ( $order['kind'] ?? 'order' ),
				'date'      => (string) $order['date'],
				'state'     => (string) ( $order['state'] ?? '' ),
				'type'      => (string) $line['type'],
				'name'      => (string) ( $line['name'] ?? '' ),
				'note'      => (string) ( $line['note'] ?? '' ),
			];

			$net    = (float) $line['net'];
			$taxed  = [] !== $taxes;
			$single = static fn ( string $category ): array => $base + [
				'category' => $category,
				'net'      => $net,
				'tax'      => array_sum( $taxes ),
				'taxes'    => $taxes,
			];

			if ( ! empty( $order['marketplace'] ) ) {
				$out[] = $single( self::CATEGORY_MARKETPLACE );
				continue;
			}

			if ( empty( $order['texas'] ) ) {
				$out[] = $single( self::CATEGORY_OUT_OF_STATE );
				continue;
			}

			$exempt_category = $taxed ? self::CATEGORY_EXEMPT_TAXED : self::CATEGORY_EXEMPT;

			if ( 'line_item' === $line['type'] ) {
				$out[] = $single( ! empty( $line['taxable'] ) ? self::CATEGORY_TAXABLE : $exempt_category );
				continue;
			}

			// Shipping or a fee: taxable in proportion to what it delivered.
			if ( $share >= 1.0 ) {
				$out[] = $single( self::CATEGORY_TAXABLE );
				continue;
			}

			if ( $share <= 0.0 ) {
				$out[] = $single( $exempt_category );
				continue;
			}

			$taxable_net   = round( $net * $share, 2 );
			$taxable_taxes = array_map( static fn ( float $amount ): float => round( $amount * $share, 2 ), $taxes );
			$exempt_taxes  = [];

			foreach ( $taxes as $label => $amount ) {
				$exempt_taxes[ $label ] = $amount - $taxable_taxes[ $label ];
			}

			$exempt_taxes = array_filter( $exempt_taxes, static fn ( float $amount ): bool => 0.0 !== $amount );
			$split_note   = sprintf( '%s%% of shipping/fees allocated to taxable items', number_format( $share * 100, 1 ) );

			$out[] = [
				'category' => self::CATEGORY_TAXABLE,
				'net'      => $taxable_net,
				'tax'      => array_sum( $taxable_taxes ),
				'taxes'    => $taxable_taxes,
				'note'     => trim( $base['note'] . ' ' . $split_note ),
			] + $base;

			$out[] = [
				'category' => $exempt_category,
				'net'      => $net - $taxable_net,
				'tax'      => array_sum( $exempt_taxes ),
				'taxes'    => $exempt_taxes,
				'note'     => trim( $base['note'] . ' ' . $split_note ),
			] + $base;
		}

		return $out;
	}

	/**
	 * Share of item value that is taxable, from an order's product lines.
	 *
	 * Pure. An order whose items net to nothing (all discounted to zero, say)
	 * has no value to apportion by, so it falls back to "taxable if any item
	 * is".
	 *
	 * @param list<array<string, mixed>> $lines Order description lines.
	 */
	public static function taxable_share( array $lines ): float {
		$all     = 0.0;
		$taxable = 0.0;
		$any     = false;

		foreach ( $lines as $line ) {
			if ( 'line_item' !== $line['type'] ) {
				continue;
			}

			$net  = abs( (float) $line['net'] );
			$all += $net;

			if ( ! empty( $line['taxable'] ) ) {
				$taxable += $net;
				$any      = true;
			}
		}

		if ( $all <= 0.0 ) {
			return $any ? 1.0 : 0.0;
		}

		return $taxable / $all;
	}

	/**
	 * Total classified lines.
	 *
	 * Pure. A jurisdiction's taxable base counts each line once — the line's
	 * net amount when that jurisdiction's tax was charged on it — so state,
	 * city, and special district rows each show the full base they taxed,
	 * and no line is counted twice under one jurisdiction however many rate
	 * IDs share its label. Marketplace lines are left out of the
	 * jurisdiction breakdown: that tax is the marketplace's to remit.
	 *
	 * @param list<array<string, mixed>> $lines Result of {@see self::classify()}, concatenated.
	 *
	 * @return array{totals:array<string, float>, by_category:array<string, array{sales:float, tax:float}>, by_jurisdiction:list<array{label:string, rate_percent:?float, taxable_base:float, tax:float}>}
	 */
	public static function summarize( array $lines ): array {
		$by_category = [];

		foreach ( self::CATEGORIES as $category ) {
			$by_category[ $category ] = [
				'sales' => 0.0,
				'tax'   => 0.0,
			];
		}

		$jurisdictions = [];

		foreach ( $lines as $line ) {
			$category = (string) $line['category'];

			$by_category[ $category ]['sales'] += (float) $line['net'];
			$by_category[ $category ]['tax']   += (float) $line['tax'];

			if ( self::CATEGORY_MARKETPLACE === $category ) {
				continue;
			}

			foreach ( $line['taxes'] as $label => $amount ) {
				$label = (string) $label;

				if ( ! isset( $jurisdictions[ $label ] ) ) {
					$jurisdictions[ $label ] = [
						'label'        => $label,
						'rate_percent' => null,
						'taxable_base' => 0.0,
						'tax'          => 0.0,
					];
				}

				$jurisdictions[ $label ]['taxable_base'] += (float) $line['net'];
				$jurisdictions[ $label ]['tax']          += (float) $amount;
			}
		}

		$by_category = array_map(
			static fn ( array $row ): array => [
				'sales' => round( $row['sales'], 2 ),
				'tax'   => round( $row['tax'], 2 ),
			],
			$by_category
		);

		$texas_sales = 0.0;
		$texas_tax   = 0.0;

		foreach ( self::TEXAS_CATEGORIES as $category ) {
			$texas_sales += $by_category[ $category ]['sales'];
			$texas_tax   += $by_category[ $category ]['tax'];
		}

		$gross = 0.0;

		foreach ( $by_category as $row ) {
			$gross += $row['sales'];
		}

		// A label that only ever appears on an order fully reversed by its
		// refund nets to nothing; it's noise, not a jurisdiction to report.
		$jurisdictions = array_filter(
			$jurisdictions,
			static fn ( array $row ): bool => abs( $row['taxable_base'] ) >= 0.005 || abs( $row['tax'] ) >= 0.005
		);

		ksort( $jurisdictions );

		return [
			'totals'          => [
				'gross_sales'          => round( $gross, 2 ),
				'total_texas_sales'    => round( $texas_sales, 2 ),
				'taxable_sales'        => $by_category[ self::CATEGORY_TAXABLE ]['sales'],
				'exempt_taxed_sales'   => $by_category[ self::CATEGORY_EXEMPT_TAXED ]['sales'],
				'exempt_sales'         => $by_category[ self::CATEGORY_EXEMPT ]['sales'],
				// Tax collected in error is remitted, so those sales go on the
				// return as taxable too.
				'return_taxable_sales' => round( $by_category[ self::CATEGORY_TAXABLE ]['sales'] + $by_category[ self::CATEGORY_EXEMPT_TAXED ]['sales'], 2 ),
				'texas_tax_collected'  => round( $texas_tax, 2 ),
				'tax_on_taxable'       => $by_category[ self::CATEGORY_TAXABLE ]['tax'],
				'tax_in_error'         => $by_category[ self::CATEGORY_EXEMPT_TAXED ]['tax'],
			],
			'by_category'     => $by_category,
			'by_jurisdiction' => array_values(
				array_map(
					static fn ( array $row ): array => [
						'label'        => $row['label'],
						'rate_percent' => $row['taxable_base'] > 0.0 ? round( $row['tax'] / $row['taxable_base'] * 100, 2 ) : null,
						'taxable_base' => round( $row['taxable_base'], 2 ),
						'tax'          => round( $row['tax'], 2 ),
					],
					$jurisdictions
				)
			),
		];
	}

	/**
	 * Whether an order came from a marketplace, by its `created_via`.
	 *
	 * Pure apart from the filter.
	 *
	 * @param string $created_via `$order->get_created_via()`.
	 */
	public static function is_marketplace( string $created_via ): bool {
		/**
		 * `created_via` fragments that mark an order as a marketplace sale.
		 *
		 * @param list<string> $channels Lower-case substrings.
		 */
		$channels    = (array) apply_filters( 'pvtax_marketplace_channels', self::MARKETPLACE_CHANNELS );
		$created_via = strtolower( $created_via );

		foreach ( $channels as $channel ) {
			if ( '' !== $channel && str_contains( $created_via, strtolower( (string) $channel ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every counted order, and every refund against a counted order, whose
	 * own date falls in the range, described for {@see self::classify()}.
	 *
	 * A refund is netted against the period it happened in, not the period
	 * of the original sale — the same way a sales tax return recognizes a
	 * reversal in the period it occurred, not by amending an earlier one.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 *
	 * @return iterable<array<string, mixed>>
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
			if ( $order instanceof WC_Order ) {
				yield $this->describe( $order, $order );
			}
		}

		$refunds = wc_get_orders( array_merge( $common, [ 'type' => 'shop_order_refund' ] ) );

		foreach ( (array) $refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$parent = wc_get_order( $refund->get_parent_id() );

			// A refund against an order the report doesn't count (cancelled,
			// failed, deleted) would net against a sale that was never added.
			if ( ! $parent instanceof WC_Order || ! in_array( $parent->get_status(), self::INCLUDED_STATUSES, true ) ) {
				continue;
			}

			yield $this->describe( $refund, $parent );
		}
	}

	/**
	 * Describe an order or refund for {@see self::classify()}.
	 *
	 * @param WC_Abstract_Order $order  The order or refund itself.
	 * @param WC_Order          $placed The order — itself, or a refund's parent.
	 *
	 * @return array<string, mixed>
	 */
	private function describe( WC_Abstract_Order $order, WC_Order $placed ): array {
		$labels = $this->tax_labels( $placed );
		$lines  = [];

		foreach ( [ 'line_item', 'shipping', 'fee' ] as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				if (
					! $item instanceof WC_Order_Item_Product
					&& ! $item instanceof WC_Order_Item_Shipping
					&& ! $item instanceof WC_Order_Item_Fee
				) {
					continue;
				}

				$taxes = [];
				$raw   = $item->get_taxes();

				foreach ( ( is_array( $raw['total'] ?? null ) ? $raw['total'] : [] ) as $rate_id => $amount ) {
					$label           = $labels[ $rate_id ] ?? $this->fallback_label( (int) $rate_id );
					$taxes[ $label ] = ( $taxes[ $label ] ?? 0.0 ) + (float) $amount;
				}

				$line = [
					'type'  => $type,
					'name'  => $item->get_name(),
					'net'   => (float) $item->get_total(),
					'taxes' => $taxes,
					'note'  => '',
				];

				if ( $item instanceof WC_Order_Item_Product ) {
					[ $line['taxable'], $line['note'] ] = $this->product_taxable( $item, $taxes );
				}

				$lines[] = $line;
			}
		}

		$is_refund = $order instanceof WC_Order_Refund;
		$state     = $this->destination_state( $placed );
		$date      = $order->get_date_created();
		$described = [
			'order_id'      => $order->get_id(),
			'parent_id'     => $is_refund ? $placed->get_id() : 0,
			'kind'          => $is_refund ? 'refund' : 'order',
			'date'          => null === $date ? '' : $date->date( 'Y-m-d' ),
			'marketplace'   => self::is_marketplace( $placed->get_created_via() ),
			'texas'         => 'US:TX' === $state,
			'state'         => $state,
			'taxable_share' => null,
			'lines'         => $lines,
		];

		if ( $is_refund ) {
			$described['taxable_share'] = self::taxable_share( $this->describe( $placed, $placed )['lines'] );

			// A refund for an amount, with no line items: nothing to classify
			// by, so apportion it like the order it refunds. WooCommerce
			// refunds no tax on an unitemized refund.
			$unitemized = (float) $order->get_amount();

			if ( [] === $lines && abs( $unitemized ) >= 0.01 ) {
				$described['lines'][] = [
					'type'  => 'fee',
					'name'  => __( 'Refund (no line items)', 'pv-tax-reports' ),
					'net'   => -abs( $unitemized ),
					'taxes' => [],
					'note'  => __( 'Unitemized refund, apportioned like its order.', 'pv-tax-reports' ),
				];
			}
		}

		return $described;
	}

	/**
	 * Whether a product line is a taxable sale under Texas law, by the
	 * product's own WooCommerce tax status — not by whether tax happened to
	 * be charged.
	 *
	 * @param WC_Order_Item_Product $item  Line.
	 * @param array<string, float>  $taxes Tax charged on it, by jurisdiction.
	 *
	 * @return array{0:bool, 1:string} Taxable, and a note for the audit export.
	 */
	private function product_taxable( WC_Order_Item_Product $item, array $taxes ): array {
		$product = $item->get_product();
		$note    = '';

		if ( $product instanceof WC_Product ) {
			$taxable = 'taxable' === $product->get_tax_status() && 'zero-rate' !== $product->get_tax_class();
		} else {
			$taxable = [] !== array_filter( $taxes );
			$note    = __( 'Product no longer exists; classified by whether tax was charged.', 'pv-tax-reports' );
		}

		/**
		 * Whether an order line is a taxable sale for the report.
		 *
		 * @param bool                  $taxable From the product's tax status.
		 * @param WC_Order_Item_Product $item    The order line.
		 */
		return [ (bool) apply_filters( 'pvtax_line_is_taxable', $taxable, $item ), $note ];
	}

	/**
	 * Tax rate ID => label, from the order's own tax lines — what was
	 * recorded at checkout, not today's tax table.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<int|string, string>
	 */
	private function tax_labels( WC_Order $order ): array {
		$labels = [];

		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! $tax instanceof WC_Order_Item_Tax ) {
				continue;
			}

			$label = trim( (string) $tax->get_label() );

			if ( '' === $label ) {
				$label = trim( (string) $tax->get_rate_code() );
			}

			$labels[ $tax->get_rate_id() ] = '' !== $label ? $label : $this->fallback_label( $tax->get_rate_id() );
		}

		return $labels;
	}

	/**
	 * Label for a rate ID with no tax line on its order — the store's tax
	 * table, then just the ID.
	 *
	 * @param int $rate_id Tax rate ID.
	 */
	private function fallback_label( int $rate_id ): string {
		$label = WC_Tax::get_rate_label( $rate_id );

		/* translators: %d: tax rate ID. */
		return '' !== $label ? $label : sprintf( __( 'Rate #%d', 'pv-tax-reports' ), $rate_id );
	}

	/**
	 * Where an order was delivered, as "COUNTRY:STATE": the shipping
	 * address, else the billing address, else (local pickup, no address) the
	 * store's own location.
	 *
	 * @param WC_Order $order Order.
	 */
	private function destination_state( WC_Order $order ): string {
		if ( '' !== $order->get_shipping_country() ) {
			[ $country, $state ] = [ $order->get_shipping_country(), $order->get_shipping_state() ];
		} elseif ( '' !== $order->get_billing_country() ) {
			[ $country, $state ] = [ $order->get_billing_country(), $order->get_billing_state() ];
		} else {
			[ $country, $state ] = [ WC()->countries->get_base_country(), WC()->countries->get_base_state() ];
		}

		$states = WC()->countries->get_states( $country );

		return self::state_key( $country, $state, is_array( $states ) ? $states : [] );
	}

	/**
	 * "COUNTRY:STATE" with the state as its code, whichever way it was
	 * stored. Orders created through the REST API (and some imports) can
	 * carry the state's name — "Texas" — or a lower-case code, which would
	 * otherwise read as not Texas.
	 *
	 * Pure.
	 *
	 * @param string                $country Country code.
	 * @param string                $state   State as stored on the order.
	 * @param array<string, string> $states  WooCommerce's code => name list for the country.
	 */
	public static function state_key( string $country, string $state, array $states ): string {
		$country = strtoupper( trim( $country ) );
		$state   = trim( $state );

		foreach ( $states as $code => $name ) {
			if ( 0 === strcasecmp( $state, (string) $code ) || 0 === strcasecmp( $state, html_entity_decode( (string) $name ) ) ) {
				return $country . ':' . $code;
			}
		}

		return $country . ':' . strtoupper( $state );
	}
}
