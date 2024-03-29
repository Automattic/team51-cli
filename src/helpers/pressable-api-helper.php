<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs the calls to the Pressable API and parses the responses.
 */
final class Pressable_API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the Pressable API.
	 *
	 * @link    https://my.pressable.com/documentation/api/v1#introduction
	 */
	private const BASE_URL = 'https://my.pressable.com/v1/';

	/**
	 * The full path to the access token file.
	 */
	private const CACHED_TOKENS_FILE_PATH = TEAM51_CLI_ROOT_DIR . '/secrets/pressable_cached_tokens.json';

	/**
	 * The time that the access token is valid for. According to the documentation, that is 1 hour.
	 */
	private const ACCESS_TOKEN_VALIDITY = '59 minutes'; // Force refresh every 59 minutes to avoid edge cases at the 1-hour mark.

	// endregion

	// region METHODS

	/**
	 * Calls a given endpoint on the Pressable API and returns the response.
	 *
	 * @param   string  $endpoint   The endpoint to call.
	 * @param   string  $method     The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   array   $params     The parameters to send with the request.
	 *
	 * @return  object|null
	 */
	public static function call_api( string $endpoint, string $method = 'GET', array $params = array() ): ?object {
		$result = get_remote_content(
			self::get_request_url( $endpoint ),
			array(
				'Accept: application/json',
				'Content-Type: application/json',
				'Authorization: Bearer ' . self::get_access_token(),
				'User-Agent: PHP',
			),
			$method,
			encode_json_content( $params )
		);

		if ( 401 === $result['headers']['http_code'] ) {
			exit( '❌ Pressable authentication failed! Your credentials are probably out of date. Please update them before running this again or your Pressable account may be locked.' . PHP_EOL );
		}
		if ( 0 !== \strpos( $result['headers']['http_code'], '2' ) ) {
			$message = '';
			if ( \is_null( $result['body'] ) ) {
				$message = '{no response body 😭}';
			} else {
				if ( \property_exists( $result['body'], 'message' ) ) {
					$message = $result['body']->message;
				} elseif ( \property_exists( $result['body'], 'error' ) ) {
					$message = $result['body']->error;
				}

				if ( \property_exists( $result['body'], 'errors' ) && ! empty( $result['body']->errors ) ) {
					$message .= ' ' . \implode( ', ', (array) $result['body']->errors );
				}
			}

			console_writeln(
				"❌ Pressable API error ($endpoint): {$result['headers']['http_code']} $message",
				404 === $result['headers']['http_code'] ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_QUIET
			);
			return null;
		}

		return $result['body'];
	}

	/**
	 * Calls the Pressable auth endpoint to get new tokens.
	 *
	 * @param   string  $client_id      The API application client ID.
	 * @param   string  $client_secret  The API application client secret.
	 *
	 * @return  object
	 */
	public static function call_auth_api( string $client_id, string $client_secret ): object {
		console_writeln( 'Obtaining new Pressable OAuth token.', OutputInterface::VERBOSITY_VERBOSE );
		$post_data = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);

		if ( \defined( 'PRESSABLE_ACCOUNT_PASSWORD' ) && \defined( 'PRESSABLE_ACCOUNT_EMAIL' ) ) {
			$post_data['grant_type'] = 'password';
			$post_data['email']      = PRESSABLE_ACCOUNT_EMAIL;
			$post_data['password']   = PRESSABLE_ACCOUNT_PASSWORD;
		} elseif ( \defined( 'PRESSABLE_API_REFRESH_TOKEN' ) ) {
			$post_data['grant_type']    = 'refresh_token';
			$post_data['refresh_token'] = self::get_cached_refresh_token();
		} else {
			exit( '❌ Missing both Pressable credentials and a refresh token. Aborting!' . PHP_EOL );
		}

		$result = get_remote_content(
			'https://my.pressable.com/auth/token/',
			array(
				'Content-Type: application/x-www-form-urlencoded',
				'User-Agent: PHP',
			),
			'POST',
			\http_build_query( $post_data )
		);
		if ( empty( $result['body']->access_token ) ) {
			exit( '❌ Pressable API token could not be retrieved. Aborting!' . PHP_EOL );
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

	/**
	 * Returns the access token for the Pressable API.
	 *
	 * @return  string
	 */
	private static function get_access_token(): string {
		// Check for an existing access token.
		$access_token = self::get_cached_access_token();
		if ( ! \is_null( $access_token ) ) {
			console_writeln( 'Re-using Pressable OAuth token cached locally.', OutputInterface::VERBOSITY_DEBUG );

			return $access_token;
		}

		// If no access token exists, get a new one.
		$api_tokens = self::call_auth_api( PRESSABLE_API_APP_CLIENT_ID, PRESSABLE_API_APP_CLIENT_SECRET );
		if ( false === self::set_cached_tokens( $api_tokens ) ) {
			console_writeln( '❌ Failed to cache Pressable access tokens.' );
		}

		return $api_tokens->access_token;
	}

	/**
	 * Returns the access token from the cached tokens file, if exists and still valid.
	 *
	 * @return  string|null
	 */
	private static function get_cached_access_token(): ?string {
		// Load tokens file contents.
		if ( ! \file_exists( self::CACHED_TOKENS_FILE_PATH ) ) {
			return null;
		}

		$data = decode_json_content( \file_get_contents( self::CACHED_TOKENS_FILE_PATH ) );
		if ( \is_null( $data ) ) {
			return null;
		}

		// Check temporal validity.
		if ( (int) $data->created_at < \strtotime( 'now -' . self::ACCESS_TOKEN_VALIDITY ) ) {
			return null;
		}

		return $data->access_token;
	}

	/**
	 * Returns the refresh token from the cached tokens file, if exists and still valid.
	 *
	 * @return  string|null
	 */
	private static function get_cached_refresh_token(): ?string {
		if ( ! \file_exists( self::CACHED_TOKENS_FILE_PATH ) ) {
			if ( \defined( 'PRESSABLE_API_REFRESH_TOKEN' ) ) {
				console_writeln( 'Using PRESSABLE_API_REFRESH_TOKEN from config.json file.', OutputInterface::VERBOSITY_DEBUG );
				return PRESSABLE_API_REFRESH_TOKEN;
			}

			console_writeln( '❌ No PRESSABLE_API_REFRESH_TOKEN found. Please check your config.json file.' );
			return null;
		}

		$data = decode_json_content( \file_get_contents( self::CACHED_TOKENS_FILE_PATH ) );
		if ( \is_null( $data ) ) {
			return null;
		}

		return $data->refresh_token;
	}

	/**
	 * Saves the access token to the access token file.
	 *
	 * @param   object  $token  The access token data to save.
	 *
	 * @return  bool    True if the access token was saved successfully, false otherwise.
	 */
	private static function set_cached_tokens( object $token ): bool {
		// No point in saving more data than we need.
		$data = array(
			'access_token'  => $token->access_token,
			'refresh_token' => $token->refresh_token,
			'created_at'    => \time(), // Safeguard against the user's PHP time being misconfigured.
		);

		// Save the data to the file as a JSON string.
		$data = encode_json_content( $data );
		if ( \is_null( $data ) ) {
			return false;
		}

		$result = \file_put_contents( self::CACHED_TOKENS_FILE_PATH, $data );
		return false !== $result;
	}

	// endregion
}
