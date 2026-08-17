<?php
/**
 * Updates from GitHub releases.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Update;

use PoorVida\TaxReports\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Offers plugin updates from published GitHub releases.
 *
 * The repository is public, so no token is involved. Anonymous GitHub API
 * requests are rate-limited per IP, hence the transient.
 */
final class GitHubUpdater {

	public const TRANSIENT = 'pvtax_latest_release';

	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Hook the update filters.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'provide_plugin_info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 0 );
	}

	/**
	 * Plugin basename, e.g. pv-tax-reports/pv-tax-reports.php.
	 */
	private function basename(): string {
		return plugin_basename( PVTAX_FILE );
	}

	/**
	 * Plugin slug, e.g. pv-tax-reports.
	 */
	private function slug(): string {
		return dirname( $this->basename() );
	}

	/**
	 * Add this plugin to the update payload when a newer release exists.
	 *
	 * @param mixed $transient Update transient.
	 *
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! isset( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], \PoorVida\TaxReports\VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ $this->basename() ] = (object) [
			'id'          => 'github.com/' . Options::get( 'github_repo' ),
			'slug'        => $this->slug(),
			'plugin'      => $this->basename(),
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
		];

		return $transient;
	}

	/**
	 * Supply the "view details" modal content.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 *
	 * @return mixed
	 */
	public function provide_plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug() ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Poor Vida Tax Reports',
			'slug'          => $this->slug(),
			'version'       => $release['version'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => [
				'changelog' => wp_kses_post( wpautop( $release['notes'] ) ),
			],
		];
	}

	/**
	 * Drop the cached release after any upgrade runs.
	 */
	public function flush_cache(): void {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Fetch the latest published release, cached.
	 *
	 * @return array{version:string, url:string, package:string, notes:string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return [] === $cached ? null : $cached;
		}

		$repo = trim( Options::get( 'github_repo' ) );

		if ( '' === $repo || 1 !== preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'pv-tax-reports/' . \PoorVida\TaxReports\VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a rate-limited or offline host is not retried on every admin page load.
			set_site_transient( self::TRANSIENT, [], 15 * MINUTE_IN_SECONDS );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, [], 15 * MINUTE_IN_SECONDS );

			return null;
		}

		$release = [
			'version' => ltrim( (string) $body['tag_name'], 'vV' ),
			'url'     => (string) ( $body['html_url'] ?? '' ),
			'package' => $this->package_url( $body, $repo ),
			'notes'   => (string) ( $body['body'] ?? '' ),
		];

		set_site_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * Prefer a built zip asset over the source tarball.
	 *
	 * GitHub's auto-generated source zip unpacks into a directory named
	 * `repo-tag`, which WordPress would install alongside the existing plugin
	 * rather than over it. A release asset built with the right folder name
	 * avoids that.
	 *
	 * @param array<string, mixed> $body Release payload.
	 * @param string               $repo owner/repo.
	 */
	private function package_url( array $body, string $repo ): string {
		$assets = is_array( $body['assets'] ?? null ) ? $body['assets'] : [];

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = (string) ( $asset['name'] ?? '' );

			if ( str_ends_with( $name, '.zip' ) ) {
				return (string) ( $asset['browser_download_url'] ?? '' );
			}
		}

		return (string) ( $body['zipball_url'] ?? 'https://github.com/' . $repo . '/archive/refs/tags/' . (string) ( $body['tag_name'] ?? '' ) . '.zip' );
	}
}
