<?php
/**
 * Admin menu registration.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Cogs\OrderCogsRecorder;
use PoorVida\TaxReports\Cost\CostSyncService;
use PoorVida\TaxReports\Snapshots\SnapshotService;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the plugin's screens under WooCommerce.
 */
final class AdminMenu {

	public const CAPABILITY = 'manage_woocommerce';

	public const SLUG_STATUS   = 'pvtax-status';
	public const SLUG_SYNC     = 'pvtax-sync';
	public const SLUG_SETTINGS = 'pvtax-settings';

	/**
	 * Status and tools screen.
	 *
	 * @var StatusPage
	 */
	private StatusPage $status;

	/**
	 * Cost sync screen.
	 *
	 * @var SyncPage
	 */
	private SyncPage $sync;

	/**
	 * Settings screen.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings;

	/**
	 * Build the screens.
	 *
	 * @param SnapshotService   $snapshots  Snapshot service.
	 * @param OrderCogsRecorder $order_cogs Order COGS recorder.
	 * @param CostSyncService   $cost_sync  Cost sync service.
	 */
	public function __construct( SnapshotService $snapshots, OrderCogsRecorder $order_cogs, CostSyncService $cost_sync ) {
		$this->status   = new StatusPage( $snapshots, $order_cogs );
		$this->sync     = new SyncPage( $cost_sync );
		$this->settings = new SettingsPage();
	}

	/**
	 * Hook the menu and the pages' own handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );

		$this->status->register();
		$this->sync->register();
		$this->settings->register();
	}

	/**
	 * Register the submenu pages.
	 */
	public function add_pages(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Tax Reports', 'pv-tax-reports' ),
			__( 'Tax Reports', 'pv-tax-reports' ),
			self::CAPABILITY,
			self::SLUG_STATUS,
			[ $this->status, 'render' ]
		);

		add_submenu_page(
			'woocommerce',
			__( 'Sync Costs', 'pv-tax-reports' ),
			__( 'Sync Costs', 'pv-tax-reports' ),
			self::CAPABILITY,
			self::SLUG_SYNC,
			[ $this->sync, 'render' ]
		);

		add_submenu_page(
			'woocommerce',
			__( 'Tax Reports Settings', 'pv-tax-reports' ),
			__( 'Tax Reports Settings', 'pv-tax-reports' ),
			self::CAPABILITY,
			self::SLUG_SETTINGS,
			[ $this->settings, 'render' ]
		);
	}
}
