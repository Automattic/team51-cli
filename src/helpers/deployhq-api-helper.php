<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs the calls to the DeployHQ API and parses the responses.
 */
final class DeployHQ_API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the DeployHQ API.
	 *
	 * @link    https://www.deployhq.com/support/api
	 */
	private const BASE_URL = 'https://<account>.deployhq.com/';

	// endregion

	// region METHODS

	/**
	 * Calls a given endpoint on the DeployHQ API and returns the response.
	 *
	 * @param   string  $endpoint   The endpoint to call.
	 * @param   string  $method     The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   array   $params     The parameters to send with the request.
	 *
	 * @return  object|object[]|null
	 */
	public static function call_api( string $endpoint, string $method = 'GET', array $params = array() ) {
		$result = get_remote_content(
			self::get_request_url( $endpoint ),
			array(
				'Accept: application/json',
				'Content-type: application/json',
				'Authorization: Basic ' . \base64_encode( DEPLOYHQ_USERNAME . ':' . DEPLOYHQ_API_KEY ),
				'User-Agent: PHP',
			),
			$method,
			encode_json_content( $params )
		);

		if ( 0 !== \strpos( $result['headers']['http_code'], '2' ) ) {
			$message = encode_json_content( $result['body'] ) ?? 'Badly formatted error';
			console_writeln(
				"❌ DeployHQ API error: {$result['headers']['http_code']} $message",
				404 === $result['headers']['http_code'] ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
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
	 *
	 * @return  string
	 */
	private static function get_request_url( string $endpoint ): string {
		return \str_replace( '<account>', DEPLOYHQ_ACCOUNT, self::BASE_URL ) . \ltrim( $endpoint, '/' );
	}

	// endregion
}
