<?php
/**
 * Freezes cost of goods at the moment of sale.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cogs;

use PoorVida\TaxReports\Cost\CostResolver;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The drift rule, implemented.
 *
 * The product-level COGS field holds current cost, for future sales. These
 * rows hold what a unit cost when it actually sold. A cost change updates the
 * former and never the latter — without which an ingredient price rise in
 * March silently restates January's profit.
 */
final class OrderCogsRecorder {

	/**
	 * Wire up the recorder.
	 *
	 * @param OrderCogsRepository $repository Order COGS storage.
	 * @param CostResolver        $costs      Unit cost lookup.
	 */
	public function __construct(
		private readonly OrderCogsRepository $repository,
		private readonly CostResolver $costs,
	) {}

	/**
	 * Hook the order status transitions that represent a real sale.
	 */
	public function register(): void {
		foreach ( $this->trigger_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, [ $this, 'on_status_change' ], 10, 1 );
		}
	}

	/**
	 * Action callback. Separate from capture_order() so the latter can return a
	 * summary for the admin screens without an action handler leaking a value.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_status_change( int $order_id ): void {
		$this->capture_order( $order_id );
	}

	/**
	 * Statuses whose arrival freezes an order's costs.
	 *
	 * Both `processing` and `completed` count, because whichever a sale reaches
	 * first is the moment it became real — and Report 2 counts both as sales.
	 * Capture is idempotent, so the first transition wins and later ones are
	 * no-ops.
	 *
	 * @return list<string>
	 */
	private function trigger_statuses(): array {
		/**
		 * Order statuses that trigger COGS capture.
		 *
		 * @param list<string> $statuses Status slugs, without the `wc-` prefix.
		 */
		$statuses = apply_filters( 'pvtax_cogs_capture_statuses', [ 'processing', 'completed' ] );

		return is_array( $statuses ) ? array_values( array_map( 'strval', $statuses ) ) : [ 'processing', 'completed' ];
	}

	/**
	 * Capture every line on an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array{captured:int, uncosted:int} Lines newly frozen, and how many had no cost.
	 */
	public function capture_order( int $order_id ): array {
		$result = [
			'captured' => 0,
			'uncosted' => 0,
		];

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $result;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$cost     = $this->costs->for_product( $product );
			$quantity = (float) $item->get_quantity();

			if ( null === $cost['cost'] ) {
				++$result['uncosted'];
			}

			$written = $this->repository->insert_if_absent(
				[
					'order_id'      => (int) $order->get_id(),
					'order_item_id' => (int) $item_id,
					'product_id'    => (int) $product->get_id(),
					'quantity'      => $quantity,
					'unit_cost'     => $cost['cost'],
					'line_cost'     => null === $cost['cost'] ? null : $cost['cost'] * $quantity,
					'cost_source'   => $cost['source'],
				]
			);

			if ( $written ) {
				++$result['captured'];
			}
		}

		/**
		 * Fires after an order's costs are frozen.
		 *
		 * @param int                                 $order_id Order ID.
		 * @param array{captured:int, uncosted:int}   $result   Capture summary.
		 */
		do_action( 'pvtax_order_cogs_captured', $order_id, $result );

		return $result;
	}

	/**
	 * Repository accessor, for the admin screens.
	 */
	public function repository(): OrderCogsRepository {
		return $this->repository;
	}
}
