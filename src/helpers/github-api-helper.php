<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs the calls to the GitHub API and parses the responses.
 */
final class GitHub_API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the GitHub API.
	 *
	 * @link    https://docs.github.com/en/rest/quickstart
	 */
	private const BASE_URL = 'https://api.github.com/';

	// endregion

	// region METHODS

	/**
	 * Calls a given endpoint on the GitHub API and returns the response.
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
				'Accept: application/vnd.github+json',
				'Content-Type: application/json',
				'Authorization: Bearer ' . GITHUB_API_TOKEN,
				'X-GitHub-Api-Version: 2022-11-28',
				'User-Agent: PHP',
			),
			$method,
			encode_json_content( $params )
		);

		if ( 0 !== \strpos( $result['headers']['http_code'], '2' ) ) {
			console_writeln(
				"❌ GitHub API error ($endpoint)",
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
		return self::BASE_URL . \ltrim( $endpoint, '/' );
	}

	// endregion
}
