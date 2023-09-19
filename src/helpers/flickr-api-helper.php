<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs the calls to the Flickr API and parses the responses.
 */
final class Flickr_API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the Flickr API.
	 *
	 * @link    https://www.flickr.com/services/api/request.rest.html
	 */
	private const BASE_URL = 'https://api.flickr.com/services/rest/';

	// endregion

	// region METHODS

	/**
	 * Calls a given endpoint on the Flickr API and returns the response.
	 *
	 * @param   string  $endpoint   The endpoint to call.
	 * @param   string  $method     The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   array   $arguments  The arguments to send with the request.
	 * @param   array   $params     The parameters to send with the request.
	 *
	 * @return  object|object[]|null
	 */
	public static function call_api( string $endpoint, array $arguments, string $method = 'GET', array $params = array() ) {
		$result = get_remote_content(
			self::get_request_url( $endpoint, $arguments ),
			array(),
			$method,
			empty( $params ) ? null : encode_json_content( $params )
		);

		if ( 0 !== \strpos( $result['headers']['http_code'], '2' ) ) {
			console_writeln(
				"❌ Flickr API error ($endpoint)",
				404 === $result['headers']['http_code'] ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
			);
			return null;
		}
		if ( 'ok' !== $result['body']->stat ) {
			console_writeln(
				"❌ Flickr API error ($endpoint)",
				OutputInterface::VERBOSITY_QUIET
			);
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
	 * @param   array   $arguments  The arguments to send with the request.
	 *
	 * @return  string
	 */
	private static function get_request_url( string $endpoint, array $arguments ): string {
		return self::BASE_URL . '?' . \http_build_query(
			array(
				'method'         => $endpoint,
				'api_key'        => FLICKR_API_KEY,
				'format'         => 'json',
				'nojsoncallback' => 1,
			) + $arguments
		);
	}

	// endregion
}
