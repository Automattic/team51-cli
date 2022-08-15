<?php

namespace Team51\Helpers;

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
			echo "❌ WordPress.com API error: {$result['headers']['http_code']} " . encode_json_content( $result['body'] ) . PHP_EOL;
			return null;
		}

		return $result['body'];
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

	// endregion
}
