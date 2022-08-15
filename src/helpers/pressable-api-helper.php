<?php

namespace Team51\Helpers;

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
	private const CACHED_TOKENS_FILE_PATH = __DIR__ . '/pressable_cached_tokens.json';

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
			$message = \property_exists( $result['body'], 'message' ) ? $result['body']->message : $result['body']->error;
			echo "❌ Pressable API error: {$result['headers']['http_code']} $message" . PHP_EOL;
			return null;
		}

		return $result['body'];
	}

	/**
	 * Returns the access token for the Pressable API.
	 *
	 * @return  string
	 */
	public static function get_access_token(): string {
		// Check for an existing access token.
		$access_token = self::get_cached_access_token();
		if ( ! \is_null( $access_token ) ) {
			echo "Re-using Pressable OAuth token cached locally." . PHP_EOL;
			return $access_token;
		}

		// If no access token exists, get a new one.
		echo "Obtaining new Pressable OAuth token." . PHP_EOL;
		$post_data = array(
			'client_id'     => PRESSABLE_API_APP_CLIENT_ID,
			'client_secret' => PRESSABLE_API_APP_CLIENT_SECRET,
		);

		if ( \defined( 'PRESSABLE_ACCOUNT_PASSWORD' ) && \defined( 'PRESSABLE_ACCOUNT_EMAIL' ) ) {
			$post_data['grant_type'] = 'password';
			$post_data['email']      = PRESSABLE_ACCOUNT_EMAIL;
			$post_data['password']   = PRESSABLE_ACCOUNT_PASSWORD;
		} elseif ( \defined( 'PRESSABLE_API_REFRESH_TOKEN' ) ) {
			$post_data['grant_type']    = 'refresh_token';
			$post_data['refresh_token'] = self::get_cached_refresh_token();
		} else {
			exit( '❌ Please configure your config.json to include Pressable email/password, or refresh and access tokens. Aborting!' . PHP_EOL );
		}

		$result = get_remote_content(
			PRESSABLE_API_TOKEN_ENDPOINT,
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
		if ( false === self::set_cached_tokens( $result['body'] ) ) {
			echo '❌ Failed to cache Pressable access tokens.' . PHP_EOL;
		}

		return $result['body']->access_token;
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
				echo 'Using PRESSABLE_API_REFRESH_TOKEN from config.json file.' . PHP_EOL;
				return PRESSABLE_API_REFRESH_TOKEN;
			}

			echo '❌ No PRESSABLE_API_REFRESH_TOKEN found. Please check your config.json file.' . PHP_EOL;
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
			'created_at'    => \time(),// Safeguard against the user's PHP time being misconfigured.
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
