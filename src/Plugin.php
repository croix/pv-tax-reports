<?php
/**
 * Plugin bootstrap.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports;

use PoorVida\TaxReports\Admin\AdminMenu;
use PoorVida\TaxReports\Cogs\OrderCogsRecorder;
use PoorVida\TaxReports\Cogs\OrderCogsRepository;
use PoorVida\TaxReports\Cost\CostResolver;
use PoorVida\TaxReports\Snapshots\Scheduler;
use PoorVida\TaxReports\Snapshots\SnapshotService;
use PoorVida\TaxReports\Snapshots\StockSnapshotRepository;
use PoorVida\TaxReports\Support\Schema;
use PoorVida\TaxReports\Update\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services to WordPress.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Snapshot service, null until WooCommerce has loaded.
	 *
	 * @var SnapshotService|null
	 */
	private ?SnapshotService $snapshots = null;

	/**
	 * Order COGS recorder, null until WooCommerce has loaded.
	 *
	 * @var OrderCogsRecorder|null
	 */
	private ?OrderCogsRecorder $order_cogs = null;

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the hooks that run regardless of whether WooCommerce loaded.
	 */
	public function boot(): void {
		add_action( 'before_woocommerce_init', [ $this, 'declare_woocommerce_compatibility' ] );
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
	}

	/**
	 * Opt in to HPOS. Every order read in this plugin goes through WooCommerce
	 * CRUD, so custom order tables are safe.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PVTAX_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cost_of_goods_sold', PVTAX_FILE, true );
	}

	/**
	 * Boot the services that need WooCommerce present.
	 */
	public function on_plugins_loaded(): void {
		load_plugin_textdomain( 'pv-tax-reports', false, dirname( plugin_basename( PVTAX_FILE ) ) . '/languages' );

		( new GitHubUpdater() )->register();

		if ( ! $this->woocommerce_is_active() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_woocommerce_notice' ] );

			return;
		}

		Schema::maybe_upgrade();

		$costs = new CostResolver();

		$this->snapshots = new SnapshotService( new StockSnapshotRepository(), $costs );
		$this->snapshots->register();

		( new Scheduler( $this->snapshots ) )->register();

		$this->order_cogs = new OrderCogsRecorder( new OrderCogsRepository(), $costs );
		$this->order_cogs->register();

		if ( is_admin() ) {
			( new AdminMenu( $this->snapshots, $this->order_cogs ) )->register();
		}
	}

	/**
	 * Snapshot service, or null when WooCommerce is missing.
	 */
	public function snapshots(): ?SnapshotService {
		return $this->snapshots;
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	private function woocommerce_is_active(): bool {
		return class_exists( \WooCommerce::class );
	}

	/**
	 * Admin notice shown when WooCommerce is not available.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Poor Vida Tax Reports needs WooCommerce to be active. No stock history is being recorded while WooCommerce is inactive.', 'pv-tax-reports' )
		);
	}
}
