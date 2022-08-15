<?php

namespace Team51\Helpers;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region HTTP

/**
 * Performs the remote HTTPS request and returns the response.
 *
 * @param   string          $url        The fully-qualified request URL to send the request to.
 * @param   array           $headers    The headers to send with the request.
 * @param   string          $method     The HTTP method to use for the request.
 * @param   string|null     $content    The content to send with the request.
 *
 * @return  array|null
 */
function get_remote_content( string $url, array $headers = array(), string $method = 'GET', ?string $content = null ): ?array {
	$options = array(
		'http' => array(
			'header'        => $headers,
			'method'        => $method,
			'content'       => $content,
			'timeout'       => 60,
			'ignore_errors' => true,
		)
	);
	$context = \stream_context_create( $options );

	$result = @\file_get_contents( $url, false, $context );
	if ( false === $result ) {
		return null;
	}

	return array(
		'headers' => parse_http_headers( $http_response_header ),
		'body'    => decode_json_content( $result ?: '{}' ), // Empty responses like those returned by WPCOM errors trigger an exception when decoding.
	);
}

/**
 * Transforms the raw HTTP response headers into an associative array.
 *
 * @param   array   $http_response_header   The HTTP response headers.
 *
 * @link    https://www.php.net/manual/en/reserved.variables.httpresponseheader.php#117203
 *
 * @return  array
 */
function parse_http_headers( array $http_response_header ): array {
	$headers = array();

	foreach ( $http_response_header as $header ) {
		$header = \explode( ':', $header, 2 );
		if ( 2 === \count( $header ) ) {
			$headers[ \trim( $header[0] ) ] = \trim( $header[1] );
		} else {
			$headers[] = \trim( $header[0] );
			if ( \preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#", $header[0], $out ) ) {
				$headers['http_code'] = (int) $out[1];
			}
		}
	}

	return $headers;
}

// endregion

// region WRAPPERS

/**
 * Decodes a JSON object and displays an error on failure.
 *
 * @param   string  $json           The JSON object to decode.
 * @param   bool    $associative    Whether to return an associative array or an object. Default object.
 *
 * @return  object|array|null
 */
function decode_json_content( string $json, bool $associative = false ) {
	try {
		return \json_decode( $json, $associative, 512, JSON_THROW_ON_ERROR );
	} catch ( \JsonException $exception ) {
		echo "JSON Decoding Exception: {$exception->getMessage()}" . PHP_EOL;
		return null;
	}
}

/**
 * Encodes some given data into a JSON object.
 *
 * @param   mixed   $data   The data to encode.
 *
 * @return  string|null
 */
function encode_json_content( $data ): ?string {
	try {
		return \json_encode( $data, JSON_THROW_ON_ERROR );
	} catch ( \JsonException $exception ) {
		echo "JSON Encoding Exception: {$exception->getMessage()}" . PHP_EOL;
		return null;
	}
}

// endregion

// region POLYFILLS

/**
 * Ensures that a given value is between given min and max, inclusively.
 *
 * @param   int|float   $value  The value to check.
 * @param   int|float   $min    The minimum value.
 * @param   int|float   $max    The maximum value.
 *
 * @return  int|float
 */
function clamp( $value, $min, $max ) {
	return \min( \max( $value, $min ), $max );
}

/**
 * Returns whether two given strings are equal or not in a case-insensitive manner.
 *
 * @param   string  $string_1     The first string.
 * @param   string  $string_2     The second string.
 *
 * @return  bool
 */
function is_case_insensitive_match( string $string_1, string $string_2 ): bool {
	return 0 === \strcasecmp( $string_1, $string_2 );
}

/**
 * Creates a random password of given length from a fixed set of allowed characters.
 *
 * @param   int     $length             The length of password to generate.
 * @param   bool    $special_chars      Whether to include standard special characters.
 *
 * @link    https://developer.wordpress.org/reference/functions/wp_generate_password/
 *
 * @return  string
 * @throws  \Exception  Thrown if there is not enough entropy to generate a password.
 */
function generate_random_password( int $length = 24, bool $special_chars = true ): string {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ( $special_chars ) {
		$chars .= '!@#$%^&*()';
	}

	$password = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$password .= $chars[ \random_int( 0, \strlen( $chars ) - 1 ) ];
	}

	return $password;
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as an email.
 *
 * @param   InputInterface  $input          The console input.
 * @param   OutputInterface $output         The console output.
 * @param   callable|null   $no_input_func  The function to call if no input is given.
 * @param   string          $name           The name of the value to grab.
 *
 * @return  string
 */
function get_email_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'email' ): string {
	$email = $input->hasOption( $name ) ? $input->getOption( $name ) : $input->getArgument( $name );

	// If we don't have an email, prompt for one.
	if ( empty( $email ) && \is_callable( $no_input_func ) ) {
		$email = $no_input_func( $input, $output );
	}

	// If we still don't have an email, abort.
	if ( empty( $email ) ) {
		$output->writeln( '<error>No email was provided. Aborting!</error>' );
		exit;
	}

	// Check email for validity.
	if ( false === \filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		$output->writeln( '<error>The provided email is invalid. Aborting!</error>' );
		exit;
	}

	return $email;
}

/**
 * Grabs a value from the console input and validates it as a URL or a numeric string.
 *
 * @param   InputInterface  $input          The console input.
 * @param   OutputInterface $output         The console output.
 * @param   callable|null   $no_input_func  The function to call if no input is given.
 * @param   string          $name           The name of the value to grab.
 *
 * @return  string
 */
function get_site_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): string {
	$site_id_or_url = $input->hasOption( $name ) ? $input->getOption( $name ) : $input->getArgument( $name );

	// If we don't have a site, prompt for one.
	if ( empty( $site_id_or_url ) && \is_callable( $no_input_func ) ) {
		$site_id_or_url = $no_input_func( $input, $output );
	}

	// If we still don't have a site, abort.
	if ( empty( $site_id_or_url ) ) {
		$output->writeln( '<error>No site was provided. Aborting!</error>' );
		exit;
	}

	// Strip out everything but the hostname if we have a URL.
	if ( false !== \strpos( $site_id_or_url, 'http' ) ) {
		$site_id_or_url = \parse_url( $site_id_or_url, PHP_URL_HOST );
		if ( false === $site_id_or_url ) {
			$output->writeln( '<error>Invalid URL provided. Aborting!</error>' );
			exit;
		}
	}

	return $site_id_or_url;
}

// endregion
