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
	 * Hook the preview, apply and mapping handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_pvtax_preview_sync', [ $this, 'handle_preview' ] );
		add_action( 'admin_post_pvtax_apply_sync', [ $this, 'handle_apply' ] );
		add_action( 'admin_post_pvtax_save_override', [ $this, 'handle_save_override' ] );
		add_action( 'admin_post_pvtax_clear_override', [ $this, 'handle_clear_override' ] );
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
	 * Pin a product to a BOM option chosen from the preview's unclaimed list.
	 */
	public function handle_save_override(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to sync costs.', 'pv-tax-reports' ), 403 );
		}

		$product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );

		check_admin_referer( $this->map_nonce_action( $product_id ) );

		$package_option_id = sanitize_text_field( wp_unslash( $_POST['package_option_id'] ?? '' ) );

		$args = [ 'page' => AdminMenu::SLUG_SYNC ];

		if ( 0 === $product_id || '' === $package_option_id ) {
			$args['sync_error'] = rawurlencode( __( 'Choose a BOM option to map to.', 'pv-tax-reports' ) );
		} elseif ( ! $this->sync->save_override( $product_id, $package_option_id ) ) {
			$args['sync_error'] = rawurlencode( __( 'That product could not be found.', 'pv-tax-reports' ) );
		} else {
			$args['mapped'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * Remove a manual mapping.
	 */
	public function handle_clear_override(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to sync costs.', 'pv-tax-reports' ), 403 );
		}

		$product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );

		check_admin_referer( $this->map_nonce_action( $product_id ) );

		$this->sync->clear_override( $product_id );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => AdminMenu::SLUG_SYNC,
					'unmapped' => '1',
				],
				admin_url( 'admin.php' )
			)
		);

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
	 * Render the stored preview, the mapping tools, and the apply control.
	 *
	 * @param array<string, mixed> $preview Preview payload.
	 */
	private function render_preview( array $preview ): void {
		// Only options carrying a stable packageOptionId can be picked from a mapping list.
		$selectable_options = array_values(
			array_filter(
				$preview['unmapped_options'],
				static fn( array $option ): bool => is_string( $option['packageOptionId'] ?? null ) && '' !== $option['packageOptionId']
			)
		);

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
			<table class="widefat striped" style="max-width:65rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Matched via', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Current cost', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'New cost', 'pv-tax-reports' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['matched'] as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><code><?php echo esc_html( $item['sku'] ); ?></code></td>
							<td>
								<?php echo esc_html( $item['matched_via'] ); ?>
								<?php if ( false === ( $item['option']['active'] ?? true ) ) : ?>
									<br /><em><?php esc_html_e( '(discontinued in BOM)', 'pv-tax-reports' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( null === $item['old_cost'] ? __( '— (uncosted)', 'pv-tax-reports' ) : number_format( (float) $item['old_cost'], 2 ) ); ?></td>
							<td>
								<?php if ( null === $item['new_cost'] ) : ?>
									<em><?php esc_html_e( 'no value from BOM', 'pv-tax-reports' ); ?></em>
								<?php else : ?>
									<strong><?php echo esc_html( number_format( (float) $item['new_cost'], 2 ) ); ?></strong>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'override' === $item['matched_via'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="pvtax_clear_override" />
										<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $item['product_id'] ); ?>" />
										<?php wp_nonce_field( $this->map_nonce_action( $item['product_id'] ) ); ?>
										<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Clear mapping', 'pv-tax-reports' ); ?></button>
									</form>
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
				<?php esc_html_e( "Their SKU matched neither an MPN nor a UPC in BOM, and they carry no working manual override. Their cost will not change. If a product's SKU is a UPC that BOM does not have on file yet, add it there rather than loosening how this plugin matches — or pick the matching BOM option below to map it by hand, sized to help you tell options apart.", 'pv-tax-reports' ); ?>
			</p>
			<table class="widefat striped" style="max-width:65rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'pv-tax-reports' ); ?></th>
						<th><?php esc_html_e( 'Map to', 'pv-tax-reports' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['unmapped_products'] as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><code><?php echo esc_html( '' !== $item['sku'] ? $item['sku'] : __( '(no SKU)', 'pv-tax-reports' ) ); ?></code></td>
							<td>
								<?php if ( null !== $item['override'] && '' !== $item['override'] ) : ?>
									<p class="description" style="margin:0 0 .5em">
										<?php
										printf(
											/* translators: %s: the stored override value. */
											esc_html__( 'Currently mapped to "%s", which did not match anything in this pull — check for a typo or a discontinued item.', 'pv-tax-reports' ),
											esc_html( $item['override'] )
										);
										?>
									</p>
								<?php endif; ?>
								<?php if ( [] === $selectable_options ) : ?>
									<em><?php esc_html_e( 'No unclaimed BOM options to map to.', 'pv-tax-reports' ); ?></em>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.5em;align-items:center;flex-wrap:wrap">
										<input type="hidden" name="action" value="pvtax_save_override" />
										<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $item['product_id'] ); ?>" />
										<?php wp_nonce_field( $this->map_nonce_action( $item['product_id'] ) ); ?>
										<select name="package_option_id">
											<option value=""><?php esc_html_e( '— choose a BOM option —', 'pv-tax-reports' ); ?></option>
											<?php foreach ( $selectable_options as $option ) : ?>
												<option value="<?php echo esc_attr( $option['packageOptionId'] ); ?>"><?php echo esc_html( $this->option_label( $option ) ); ?></option>
											<?php endforeach; ?>
										</select>
										<?php submit_button( __( 'Save mapping', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( [] !== $preview['unmapped_options'] ) : ?>
			<h3><?php esc_html_e( 'BOM options with no matching product', 'pv-tax-reports' ); ?></h3>
			<p class="description" style="max-width:52rem">
				<?php esc_html_e( 'Expected for anything made for on-site service rather than sold packaged. Otherwise, a question worth checking rather than ignoring.', 'pv-tax-reports' ); ?>
			</p>
			<ul style="list-style:disc;margin-left:1.5rem">
				<?php foreach ( $preview['unmapped_options'] as $option ) : ?>
					<li><?php echo esc_html( $this->option_label( $option ) ); ?></li>
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
	 * Human-readable label for a BOM package option, distinguishing it from
	 * others on the same recipe by size, container and any operator label —
	 * exactly the detail needed to pick the right one from a list.
	 *
	 * @param array<string, mixed> $option BOM package option.
	 */
	private function option_label( array $option ): string {
		$recipe_name = is_string( $option['recipeName'] ?? null ) && '' !== $option['recipeName']
			? $option['recipeName']
			: __( '(unnamed recipe)', 'pv-tax-reports' );

		if ( false === ( $option['active'] ?? true ) ) {
			/* translators: %s: recipe name. */
			$recipe_name = sprintf( __( '%s (discontinued)', 'pv-tax-reports' ), $recipe_name );
		}

		$parts = [ $recipe_name ];
		$size  = $option['packageSize'] ?? null;
		$unit  = is_string( $option['packageUnit'] ?? null ) ? $option['packageUnit'] : null;

		if ( is_numeric( $size ) && null !== $unit && '' !== $unit ) {
			$parts[] = $size . ' ' . $unit;
		} elseif ( is_numeric( $size ) ) {
			$parts[] = (string) $size;
		}

		$container = is_string( $option['containerType'] ?? null ) ? $option['containerType'] : null;

		if ( null !== $container && '' !== $container ) {
			$parts[] = $container;
		}

		$label = is_string( $option['label'] ?? null ) ? $option['label'] : null;

		if ( null !== $label && '' !== $label ) {
			$parts[] = $label;
		}

		$identity = null;

		if ( is_string( $option['mpn'] ?? null ) && '' !== $option['mpn'] ) {
			$identity = $option['mpn'];
		} elseif ( is_string( $option['upc'] ?? null ) && '' !== $option['upc'] ) {
			$identity = $option['upc'];
		}

		if ( null !== $identity ) {
			$parts[] = $identity;
		}

		return implode( ' — ', $parts );
	}

	/**
	 * Nonce action for mapping actions on a specific product, so a nonce
	 * issued for one product's form cannot be replayed against another.
	 *
	 * @param int $product_id Product being mapped or unmapped.
	 */
	private function map_nonce_action( int $product_id ): string {
		return 'pvtax_map_product_' . $product_id;
	}

	/**
	 * Notices from a redirect after preview, apply, or a mapping change.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags on a redirect target.
		if ( isset( $_GET['sync_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['sync_error'] ) ) )
			);
		}

		if ( isset( $_GET['mapped'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Mapping saved. Preview again to confirm it matched and see the new cost.', 'pv-tax-reports' )
			);
		}

		if ( isset( $_GET['unmapped'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Mapping cleared. Preview again to see current status.', 'pv-tax-reports' )
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
