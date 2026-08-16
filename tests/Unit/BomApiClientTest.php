<?php
/**
 * @package PoorVida\TaxReports
 */

declare( strict_types=1 );

namespace PoorVida\TaxReports\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use PoorVida\TaxReports\Cost\BomApiClient;

/**
 * @covers \PoorVida\TaxReports\Cost\BomApiClient
 */
final class BomApiClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();

		Functions\when( 'add_query_arg' )->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains( $url, '?' ) ? '&' : '?';

				return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( $value );
			}
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	private function stub_options( string $bom_url, string $api_key ): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'bom_url' => $bom_url,
				'api_key' => $api_key,
			]
		);
	}

	/**
	 * @param array<string, mixed> $data Data to encode.
	 */
	private static function encode( array $data ): string {
		return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress runtime is loaded in these tests.
	}

	public function test_it_refuses_to_call_out_with_no_url_configured(): void {
		$this->stub_options( '', 'a-key' );

		$result = ( new BomApiClient() )->fetch();

		$this->assertFalse( $result['ok'] );
	}

	public function test_it_refuses_to_call_out_with_no_key_configured(): void {
		$this->stub_options( 'https://bom.example.com', '' );

		$result = ( new BomApiClient() )->fetch();

		$this->assertFalse( $result['ok'] );
	}

	public function test_it_sends_the_key_as_a_bearer_token(): void {
		$this->stub_options( 'https://bom.example.com', 'secret-key' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://bom.example.com/api/external/costs?includeInactive=1',
				Mockery::on( static fn ( array $args ): bool => 'Bearer secret-key' === ( $args['headers']['Authorization'] ?? null ) )
			)
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			self::encode(
				[
					'asOf'     => '2026-08-16T00:00:00Z',
					'currency' => 'USD',
					'options'  => [],
				]
			)
		);

		$result = ( new BomApiClient() )->fetch();

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Discontinued options carry inventory that doesn't disappear the day
	 * they're marked discontinued — omitting this param would silently make
	 * them permanently unmappable.
	 */
	public function test_it_always_asks_for_discontinued_options_too(): void {
		$this->stub_options( 'https://bom.example.com', 'a-key' );

		$captured_url = null;

		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) use ( &$captured_url ) {
				$captured_url = $url;

				return [ 'response' => [ 'code' => 200 ] ];
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( self::encode( [ 'options' => [] ] ) );

		( new BomApiClient() )->fetch();

		$this->assertStringContainsString( 'includeInactive=1', (string) $captured_url );
	}

	public function test_a_401_gives_a_specific_message_without_revealing_which_reason(): void {
		$this->stub_options( 'https://bom.example.com', 'bad-key' );

		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 401 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );

		$result = ( new BomApiClient() )->fetch();

		$this->assertFalse( $result['ok'] );
		$this->assertStringNotContainsStringIgnoringCase( 'bad-key', $result['error'] );
	}

	public function test_a_network_error_is_surfaced(): void {
		$this->stub_options( 'https://bom.example.com', 'a-key' );

		$error = Mockery::mock();
		$error->shouldReceive( 'get_error_message' )->andReturn( 'Connection timed out' );

		Functions\when( 'wp_remote_get' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = ( new BomApiClient() )->fetch();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Connection timed out', $result['error'] );
	}

	public function test_unparseable_json_is_a_failure_not_a_crash(): void {
		$this->stub_options( 'https://bom.example.com', 'a-key' );

		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'not json' );

		$result = ( new BomApiClient() )->fetch();

		$this->assertFalse( $result['ok'] );
	}

	public function test_a_successful_pull_returns_the_options(): void {
		$this->stub_options( 'https://bom.example.com', 'a-key' );

		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			self::encode(
				[
					'asOf'     => '2026-08-16T00:00:00Z',
					'currency' => 'USD',
					'options'  => [ [ 'mpn' => 'X' ] ],
				]
			)
		);

		$result = ( new BomApiClient() )->fetch();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '2026-08-16T00:00:00Z', $result['as_of'] );
		$this->assertCount( 1, $result['options'] );
	}
}
