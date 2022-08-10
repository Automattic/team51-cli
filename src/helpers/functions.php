<?php

namespace Team51\Helpers;

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
		'body'    => decode_json_content( $result ),
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
		echo "<error>JSON Exception: {$exception->getMessage()}</error>" . PHP_EOL;
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
		echo "<error>JSON Exception: {$exception->getMessage()}</error>" . PHP_EOL;
		return null;
	}
}

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
