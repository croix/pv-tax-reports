<?php
/**
 * Cost sync screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Cost\CostSyncService;
use PoorVida\TaxReports\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls costs from BOM behind a mandatory preview.
 *
 * WooCommerce's Cost of Goods Sold field is hand-maintained today — a year of
 * that work is real, and overwriting it with no undo would be a bad first
 * impression. So every sync, not just the first, is preview then apply: apply
 * writes exactly what the preview showed, never a fresh fetch, so what changes
 * is never a surprise relative to what was reviewed.
 */
final class SyncPage {

	private const NONCE_PREVIEW = 'pvtax_preview_sync';
	private const NONCE_APPLY   = 'pvtax_apply_sync';

	/**
	 * Build the screen.
	 *
	 * @param CostSyncService $sync Cost sync orchestration.
	 */
	public function __construct( private readonly CostSyncService $sync ) {}

	/**
	 * Hook the preview and apply handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_pvtax_preview_sync', [ $this, 'handle_preview' ] );
		add_action( 'admin_post_pvtax_apply_sync', [ $this, 'handle_apply' ] );
	}

	/**
	 * Fetch from BOM and store the plan, without writing anything.
	 */
	public function handle_preview(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to sync costs.', 'pv-tax-reports' ), 403 );
		}

		check_admin_referer( self::NONCE_PREVIEW );

		$result = $this->sync->build_preview();

		$args = [ 'page' => AdminMenu::SLUG_SYNC ];

		if ( ! $result['ok'] ) {
			$args['sync_error'] = rawurlencode( $result['error'] );
		} else {
			$args['previewed'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * Write exactly what the current preview showed.
	 */
	public function handle_apply(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to sync costs.', 'pv-tax-reports' ), 403 );
		}

		check_admin_referer( self::NONCE_APPLY );

		$token  = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$result = $this->sync->apply( $token );

		$args = [ 'page' => AdminMenu::SLUG_SYNC ];

		if ( ! $result['ok'] ) {
			$args['sync_error'] = rawurlencode( $result['error'] );
		} else {
			$args['applied']           = $result['updated'];
			$args['unmapped_options']  = $result['unmapped_options'];
			$args['unmapped_products'] = $result['unmapped_products'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$this->render_notices();

		$configured = '' !== Options::bom_url() && '' !== Options::api_key();
		$preview    = $this->sync->pending_preview();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sync Costs from BOM', 'pv-tax-reports' ); ?></h1>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: link to the settings screen. */
							wp_kses_post( __( 'BOM URL and API key are not both set. Add them on the <a href="%s">settings screen</a> first.', 'pv-tax-reports' ) ),
							esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG_SETTINGS ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Preview shows every value that would change before anything is written. Applying writes exactly what the preview showed — it never re-checks BOM, so nothing changes here that was not already on screen.', 'pv-tax-reports' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pvtax_preview_sync" />
				<?php wp_nonce_field( self::NONCE_PREVIEW ); ?>
				<?php submit_button( __( 'Preview sync', 'pv-tax-reports' ), 'secondary', 'submit', false, $configured ? [] : [ 'disabled' => 'disabled' ] ); ?>
			</form>

			<?php if ( null !== $preview ) : ?>
				<?php $this->render_preview( $preview ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the stored preview and the apply control.
	 *
	 * @param array<string, mixed> $preview Preview payload.
	 */
	private function render_preview( array $preview ): void {
		?>
		<h2><?php esc_html_e( 'Preview', 'pv-tax-reports' ); ?></h2>

		<p class="description">
			<?php
			printf(
				/* translators: %s: BOM's reported timestamp. */
				esc_html__( 'BOM reports these costs as of %s.', 'pv-tax-reports' ),
				esc_html( '' !== $preview['as_of'] ? $preview['as_of'] : __( 'unknown', 'pv-tax-reports' ) )
			);
			?>
		</p>

		<?php if ( [] === $preview['matched'] ) : ?>
			<p><?php esc_html_e( 'No products matched a BOM cost.', 'pv-tax-reports' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:60rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Matched via', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Current cost', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'New cost', 'pv-tax-reports' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['matched'] as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><code><?php echo esc_html( $item['sku'] ); ?></code></td>
							<td><?php echo esc_html( $item['matched_via'] ); ?></td>
							<td><?php echo esc_html( null === $item['old_cost'] ? __( '— (uncosted)', 'pv-tax-reports' ) : number_format( (float) $item['old_cost'], 2 ) ); ?></td>
							<td>
								<?php if ( null === $item['new_cost'] ) : ?>
									<em><?php esc_html_e( 'no value from BOM', 'pv-tax-reports' ); ?></em>
								<?php else : ?>
									<strong><?php echo esc_html( number_format( (float) $item['new_cost'], 2 ) ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( [] !== $preview['unmapped_products'] ) : ?>
			<h3><?php esc_html_e( 'Products with no BOM match', 'pv-tax-reports' ); ?></h3>
			<p class="description" style="max-width:52rem">
				<?php esc_html_e( "Their SKU matched neither an MPN nor a UPC in BOM, and they carry no manual override. Their cost will not change. If a product's SKU is a UPC that BOM does not have on file yet, add it there rather than loosening how this plugin matches.", 'pv-tax-reports' ); ?>
			</p>
			<ul style="list-style:disc;margin-left:1.5rem">
				<?php foreach ( $preview['unmapped_products'] as $item ) : ?>
					<li><?php echo esc_html( $item['name'] ); ?> — <code><?php echo esc_html( '' !== $item['sku'] ? $item['sku'] : __( '(no SKU)', 'pv-tax-reports' ) ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( [] !== $preview['unmapped_options'] ) : ?>
			<h3><?php esc_html_e( 'BOM options with no matching product', 'pv-tax-reports' ); ?></h3>
			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Expected for anything made for on-site service rather than sold packaged. Otherwise, a question worth checking rather than ignoring.', 'pv-tax-reports' ); ?>
			</p>
			<ul style="list-style:disc;margin-left:1.5rem">
				<?php foreach ( $preview['unmapped_options'] as $option ) : ?>
					<li>
						<?php echo esc_html( is_string( $option['recipeName'] ?? null ) ? $option['recipeName'] : __( '(unnamed recipe)', 'pv-tax-reports' ) ); ?>
						<?php if ( is_string( $option['mpn'] ?? null ) || is_string( $option['upc'] ?? null ) ) : ?>
							— <code><?php echo esc_html( $option['mpn'] ?? $option['upc'] ); ?></code>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
			<input type="hidden" name="action" value="pvtax_apply_sync" />
			<input type="hidden" name="token" value="<?php echo esc_attr( $preview['token'] ); ?>" />
			<?php wp_nonce_field( self::NONCE_APPLY ); ?>
			<?php submit_button( __( 'Apply this sync', 'pv-tax-reports' ), 'primary', 'submit', false ); ?>
			<span class="description" style="margin-left:.5rem">
				<?php esc_html_e( 'Writes the new cost values shown above. Expires 10 minutes after preview.', 'pv-tax-reports' ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Notices from a redirect after preview or apply.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags on a redirect target.
		if ( isset( $_GET['sync_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['sync_error'] ) ) )
			);
		}

		if ( isset( $_GET['applied'] ) ) {
			$updated    = absint( wp_unslash( $_GET['applied'] ) );
			$unmapped_o = absint( wp_unslash( $_GET['unmapped_options'] ?? 0 ) );
			$unmapped_p = absint( wp_unslash( $_GET['unmapped_products'] ?? 0 ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: products updated, 2: unmapped BOM options, 3: unmapped products. */
						__( 'Sync applied: %1$d product costs updated, %2$d BOM options unmapped, %3$d products unmapped.', 'pv-tax-reports' ),
						$updated,
						$unmapped_o,
						$unmapped_p
					)
				)
			);
		}
	}
}
