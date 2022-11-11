<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

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
			console_writeln(
				"❌ WordPress.com API error ($endpoint): {$result['headers']['http_code']}",
				\in_array( $result['headers']['http_code'], array( 403, 404 ), true ) ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
			);
			return null;
		}

		return $result['body'];
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
