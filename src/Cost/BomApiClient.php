<?php
/**
 * HTTP client for BOM's cost export.
 *
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Cost;

use PoorVida\TaxReports\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to `GET /api/external/costs` on BOM.
 *
 * Contract and live behaviour are documented in docs/BOM-API-STATUS.md
 * (kept out of this public repo). BOM deliberately returns the same 401 body
 * for every auth failure — wrong key, malformed header, revoked key — so a
 * leaked key being probed can't learn whether it was ever valid. This client
 * does not try to tell those apart either.
 */
final class BomApiClient {

	private const PATH    = '/api/external/costs';
	private const TIMEOUT = 15;

	/**
	 * Pull the current cost list.
	 *
	 * @return array{ok:true, as_of:string, currency:string, options:list<array<string, mixed>>}|array{ok:false, error:string}
	 */
	public function fetch(): array {
		$base = Options::bom_url();
		$key  = Options::api_key();

		if ( '' === $base ) {
			return $this->failure( __( 'BOM URL is not configured. Set it on the Tax Reports Settings screen.', 'pv-tax-reports' ) );
		}

		if ( '' === $key ) {
			return $this->failure( __( 'BOM API key is not configured. Set it on the Tax Reports Settings screen.', 'pv-tax-reports' ) );
		}

		/*
		 * Discontinued recipes are excluded by default, but their inventory
		 * doesn't vanish the day they're marked discontinued — it still needs
		 * a cost until it sells out. Always pulling inactive options too is
		 * what makes them mappable at all; each carries active:false so the
		 * UI can flag it.
		 */
		$url = add_query_arg( 'includeInactive', '1', $base . self::PATH );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return $this->failure( __( 'BOM rejected the API key. Issue a new one from BOM → Settings → API keys and revoke the old one.', 'pv-tax-reports' ) );
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return $this->failure( sprintf( __( 'BOM returned an unexpected response (HTTP %d).', 'pv-tax-reports' ), $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['options'] ) || ! is_array( $body['options'] ) ) {
			return $this->failure( __( "BOM's response could not be parsed.", 'pv-tax-reports' ) );
		}

		return [
			'ok'       => true,
			'as_of'    => is_string( $body['asOf'] ?? null ) ? $body['asOf'] : '',
			'currency' => is_string( $body['currency'] ?? null ) ? $body['currency'] : 'USD',
			'options'  => array_values( array_filter( $body['options'], 'is_array' ) ),
		];
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $message Error message.
	 *
	 * @return array{ok:false, error:string}
	 */
	private function failure( string $message ): array {
		return [
			'ok'    => false,
			'error' => $message,
		];
	}
}
