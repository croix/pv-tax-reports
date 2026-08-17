<?php
/**
 * Settings screen.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Admin;

use PoorVida\TaxReports\Cost\CostResolver;
use PoorVida\TaxReports\Support\Options;
use PoorVida\TaxReports\Update\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * BOM connection details and snapshot timing.
 */
final class SettingsPage {

	private const NONCE               = 'pvtax_save_settings';
	private const NONCE_CHECK_UPDATES = 'pvtax_check_updates';

	/**
	 * Hook the save and update-check handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_pvtax_save_settings', [ $this, 'handle_save' ] );
		add_action( 'admin_post_pvtax_check_updates', [ $this, 'handle_check_for_updates' ] );
	}

	/**
	 * Force a fresh update check, bypassing both this plugin's own cache of
	 * the latest GitHub release and WordPress's own update-check cache.
	 *
	 * A release just published on GitHub can otherwise sit unseen for hours:
	 * this plugin caches the release lookup for 6 hours, and WordPress caches
	 * its own overall plugin-update check on top of that.
	 */
	public function handle_check_for_updates(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to check for updates.', 'pv-tax-reports' ), 403 );
		}

		check_admin_referer( self::NONCE_CHECK_UPDATES );

		delete_site_transient( GitHubUpdater::TRANSIENT );
		wp_update_plugins();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => AdminMenu::SLUG_SETTINGS,
					'checked_updates' => '1',
				],
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Persist submitted settings.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'pv-tax-reports' ), 403 );
		}

		check_admin_referer( self::NONCE );

		$values = [
			'bom_url'             => esc_url_raw( wp_unslash( $_POST['bom_url'] ?? '' ) ),
			'snapshot_time'       => sanitize_text_field( wp_unslash( $_POST['snapshot_time'] ?? '' ) ),
			'cogs_meta_key'       => sanitize_key( wp_unslash( $_POST['cogs_meta_key'] ?? '' ) ),
			'github_repo'         => sanitize_text_field( wp_unslash( $_POST['github_repo'] ?? '' ) ),
			'excluded_categories' => sanitize_text_field( wp_unslash( $_POST['excluded_categories'] ?? '' ) ),
		];

		/*
		 * An empty API key field leaves the stored key alone, so the masked
		 * field can be submitted untouched without wiping the key. Clearing it
		 * is done with the explicit checkbox.
		 */
		$submitted_key = trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ) );

		if ( '' !== $submitted_key ) {
			$values['api_key'] = $submitted_key;
		} elseif ( isset( $_POST['clear_api_key'] ) ) {
			$values['api_key'] = '';
		}

		Options::update( $values );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => AdminMenu::SLUG_SETTINGS,
					'updated' => '1',
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

		$options = Options::all();
		$costs   = new CostResolver();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tax Reports Settings', 'pv-tax-reports' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pv-tax-reports' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['checked_updates'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag. ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %s: link to the Plugins page. */
							wp_kses_post( __( 'Update check refreshed. Visit <a href="%s">Plugins</a> to see if a new version is now offered.', 'pv-tax-reports' ) ),
							esc_url( admin_url( 'plugins.php' ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pvtax_save_settings" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'BOM connection', 'pv-tax-reports' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The API key is issued from BOM itself: BOM → Settings → API keys → Create key. The raw key is shown once, at creation — if it is lost, issue a new one and revoke the old one from the same screen.', 'pv-tax-reports' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pvtax-bom-url"><?php esc_html_e( 'BOM URL', 'pv-tax-reports' ); ?></label></th>
						<td>
							<input name="bom_url" id="pvtax-bom-url" type="url" class="regular-text" value="<?php echo esc_attr( $options['bom_url'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pvtax-api-key"><?php esc_html_e( 'API key', 'pv-tax-reports' ); ?></label></th>
						<td>
							<?php if ( Options::api_key_is_constant() ) : ?>
								<p><em><?php esc_html_e( 'Set by the PVTAX_BOM_API_KEY constant in wp-config.php. Remove the constant to manage the key here.', 'pv-tax-reports' ); ?></em></p>
							<?php else : ?>
								<input name="api_key" id="pvtax-api-key" type="password" class="regular-text" autocomplete="off"
									placeholder="<?php echo '' !== $options['api_key'] ? esc_attr__( 'Saved — leave blank to keep', 'pv-tax-reports' ) : ''; ?>" />
								<p class="description">
									<label><input type="checkbox" name="clear_api_key" value="1" /> <?php esc_html_e( 'Clear the saved key', 'pv-tax-reports' ); ?></label>
								</p>
								<p class="description"><?php esc_html_e( 'Preferably define PVTAX_BOM_API_KEY in wp-config.php instead, so the key never lands in the database.', 'pv-tax-reports' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Stock snapshots', 'pv-tax-reports' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pvtax-snapshot-time"><?php esc_html_e( 'Daily snapshot time', 'pv-tax-reports' ); ?></label></th>
						<td>
							<input name="snapshot_time" id="pvtax-snapshot-time" type="time" value="<?php echo esc_attr( $options['snapshot_time'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: site timezone name. */
									esc_html__( 'Site time (%s). Late evening is best: the snapshot should land after the day\'s sales, not in the middle of them.', 'pv-tax-reports' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pvtax-cogs-meta-key"><?php esc_html_e( 'Cost meta key (fallback)', 'pv-tax-reports' ); ?></label></th>
						<td>
							<input name="cogs_meta_key" id="pvtax-cogs-meta-key" type="text" class="regular-text" value="<?php echo esc_attr( $options['cogs_meta_key'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: description of the active cost source. */
									esc_html__( 'Only used when WooCommerce\'s own Cost of Goods Sold API is unavailable. Currently reading from: %s', 'pv-tax-reports' ),
									esc_html( $costs->describe_source() )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cost sync', 'pv-tax-reports' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pvtax-excluded-categories"><?php esc_html_e( 'Excluded categories', 'pv-tax-reports' ); ?></label></th>
						<td>
							<input name="excluded_categories" id="pvtax-excluded-categories" type="text" class="regular-text" value="<?php echo esc_attr( $options['excluded_categories'] ); ?>" placeholder="clothing" />
							<p class="description"><?php esc_html_e( 'Comma-separated product category slugs to leave out of the cost sync entirely — for example, merch that has no BOM cost at all. Grouped and bundle products are always left out; their component products are mapped individually instead.', 'pv-tax-reports' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Updates', 'pv-tax-reports' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pvtax-github-repo"><?php esc_html_e( 'GitHub repository', 'pv-tax-reports' ); ?></label></th>
						<td>
							<input name="github_repo" id="pvtax-github-repo" type="text" class="regular-text" value="<?php echo esc_attr( $options['github_repo'] ); ?>" />
							<p class="description"><?php esc_html_e( 'owner/repo. Updates are offered from published releases.', 'pv-tax-reports' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
				<input type="hidden" name="action" value="pvtax_check_updates" />
				<?php wp_nonce_field( self::NONCE_CHECK_UPDATES ); ?>
				<?php submit_button( __( 'Check for updates now', 'pv-tax-reports' ), 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:.5rem">
					<?php esc_html_e( 'A release published on GitHub can otherwise sit uncached for up to 6 hours before it shows up here.', 'pv-tax-reports' ); ?>
				</span>
			</form>
		</div>
		<?php
	}
}
