<?php

namespace Team51\Helper;

use Amp\Dns\Record;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\call;
use function Amp\Dns\resolve;
use function Amp\Promise\all;
use function Amp\Promise\wait;

/**
 * Performs the calls to the WordPress.com API and parses the responses.
 */
final class WPCOM_API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the WordPress.com API.
	 *
	 * @link    https://developer.wordpress.com/docs/api/getting-started/
	 */
	private const BASE_URL = 'https://public-api.wordpress.com/';

	// endregion

	// region METHODS

	/**
	 * Calls a given endpoint on the WordPress.com API and returns the response.
	 *
	 * @param   string  $endpoint   The endpoint to call.
	 * @param   string  $method     The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   array   $params     The parameters to send with the request.
	 *
	 * @link    https://developer.wordpress.com/docs/api/
	 *
	 * @return  object|null
	 */
	public static function call_api( string $endpoint, string $method = 'GET', array $params = array() ): ?object {
		$result = get_remote_content(
			self::get_request_url( $endpoint ),
			array(
				'Accept: application/json',
				'Content-Type: application/json',
				'Authorization: Bearer ' . WPCOM_API_ACCOUNT_TOKEN,
				'User-Agent: PHP',
			),
			$method,
			encode_json_content( $params )
		);

		if ( 0 !== \strpos( $result['headers']['http_code'], '2' ) ) {
			$response_body = print_r( $result['body'], true );
			console_writeln(
				"❌ WordPress.com API error ($endpoint): {$result['headers']['http_code']} {$response_body}",
				\in_array( $result['headers']['http_code'], array( 403, 404 ), true ) ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
			);
			return null;
		}

		return $result['body'];
	}

	/**
	 * Calls a list of given endpoints on the WordPress.com API concurrently and returns the responses synchronously.
	 *
	 * @param   string[]    $endpoints  The endpoints to call.
	 * @param   string      $method     The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   array[]     $params     The parameters to send with each request.
	 * @param   bool        $quiet      Whether to suppress the output of errors.
	 *
	 * @link    https://amphp.org/http-client/concurrent
	 *
	 * @return  array
	 * @throws  \Throwable  If an error occurs in a request promise.
	 */
	public static function call_api_concurrent( array $endpoints, string $method = 'GET', array $params = array(), bool $quiet = true ): array {
		$http_client = HttpClientBuilder::buildDefault();
		$promises    = array();

		foreach ( $endpoints as $index => $endpoint ) {
			$endpoint = self::get_request_url( $endpoint );
			$body     = $params[ $index ] ?? null;

			$promises[ $index ] = call(
				static function() use ( $body, $method, $http_client, $endpoint, $quiet ) {
					$request = new Request( $endpoint, $method );
					$request->setInactivityTimeout( 60000 );
					$request->setTransferTimeout( 60000 );

					$request->setHeaders(
						array(
							'Accept'        => 'application/json',
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . WPCOM_API_ACCOUNT_TOKEN,
							'User-Agent'    => 'PHP',
						)
					);
					if ( ! \is_null( $body ) && \in_array( $method, array( 'POST', 'PUT' ), true ) ) {
						$request->setBody( encode_json_content( $body ) );
					}

					$response = yield $http_client->request( $request );
					$body     = yield $response->getBody()->buffer();

					$status = $response->getStatus();
					if ( 0 !== \strpos( (string) $status, '2' ) ) {
						console_writeln(
							"❌ WordPress.com API error ($endpoint): $status $body",
							$quiet ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
						);
						return null;
					}

					$body = $body ?: '{}'; // On non-2xx status codes, the WPCOM body is empty and that will trigger a needless exception.
					return decode_json_content( $body );
				}
			);
		}

		return wait( all( $promises ) );
	}

	/**
	 * Calls a given endpoint on a site connected to WordPress.com via Jetpack and returns the response.
	 *
	 * @param   string      $site_id_or_url     The site URL or WordPress.com site ID.
	 * @param   string      $path               The WP REST API path to call.
	 * @param   mixed|null  $body               The body to send with the request.
	 * @param   bool|null   $json               Whether to send the body as JSON.
	 *
	 * @return  object|null
	 */
	public static function call_site_api( string $site_id_or_url, string $path, $body = null, ?bool $json = null ): ?object {
		$site_id = self::ensure_site_id( $site_id_or_url );
		if ( \is_null( $site_id ) ) {
			console_writeln( "❌ WordPress.com API error: Invalid site ID or URL ($site_id_or_url)", OutputInterface::VERBOSITY_QUIET );
			return null;
		}

		$params = array( 'path' => $path );
		if ( ! \is_null( $body ) ) {
			$params['json'] = $json ?? true; // Unless explicitly set, send the body as JSON.
			$params['body'] = $params['json'] ? encode_json_content( $body ) : $body;
		}

		return self::call_api( "jetpack-blogs/$site_id/rest-api", 'POST', $params );
	}

	// endregion

	// region HELPERS

	/**
	 * Prepares the fully qualified request URL for the given endpoint.
	 *
	 * @param   string  $endpoint   The endpoint to call.
	 *
	 * @return  string
	 */
	private static function get_request_url( string $endpoint ): string {
		$endpoint = \trim( $endpoint, '/' );
		if ( 0 !== \strpos( $endpoint, 'rest/v1.1' ) ) {
			$endpoint = 'rest/v1.1/' . $endpoint;
		}

		return self::BASE_URL . $endpoint;
	}

	/**
	 * Given a WordPress.com site ID or URL, validates its existence and returns the site ID.
	 *
	 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
	 *
	 * @return  string|null
	 */
	private static function ensure_site_id( string $site_id_or_url ): ?string {
		$wpcom_site = get_wpcom_site( $site_id_or_url );
		if ( \is_null( $wpcom_site ) ) {
			return null;
		}

		return $wpcom_site->ID;
	}

	// endregion
}
