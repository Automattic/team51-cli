<?php

namespace Team51\Helper;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
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
		),
	);
	$context = \stream_context_create( $options );

	$result = @\file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
			if ( \preg_match( '#HTTP/[0-9\.]+\s+([0-9]+)#', $header[0], $out ) ) {
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
		console_writeln( "JSON Decoding Exception: {$exception->getMessage()}" );
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
		console_writeln( "JSON Encoding Exception: {$exception->getMessage()}" );
		return null;
	}
}

/**
 * Runs a command and returns the exit code.
 *
 * @param   Application         $application        The application instance.
 * @param   string              $command_name       The name of the command to run.
 * @param   array               $command_input      The input to pass to the command.
 * @param   OutputInterface     $output             The output to use for the command.
 * @param   bool                $interactive        Whether to run the command in interactive mode.
 *
 * @return int  The command exit code.
 * @throws ExceptionInterface   If the command does not exist or if the input is invalid.
 */
function run_app_command( Application $application, string $command_name, array $command_input, OutputInterface $output, bool $interactive = false ): int {
	$command = $application->find( $command_name );

	$input = new ArrayInput( $command_input );
	$input->setInteractive( $interactive );

	return $command->run( $input, $output );
}

// endregion

// region POLYFILLS

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
 * Returns true if the tool is being run in contractor mode.
 *
 * @return  bool
 */
function is_contractor_mode(): bool {
	return \in_array( '-c', $_SERVER['argv'], true )
		|| \in_array( '--contractor', $_SERVER['argv'], true );
}

/**
 * Returns true if the tool is being run in quiet mode.
 *
 * @return  bool
 */
function is_quiet_mode(): bool {
	return \in_array( '-q', $_SERVER['argv'], true )
        || \in_array( '--quiet', $_SERVER['argv'], true );
}

/**
 * Defines a constant equal to the console's verbosity level, if not already defined.
 *
 * @param   int $verbosity  The verbosity level.
 *
 * @return  void
 */
function maybe_define_console_verbosity( int $verbosity ): void {
	\defined( 'TEAM51_CLI_VERBOSITY' ) || \define( 'TEAM51_CLI_VERBOSITY', $verbosity );
}

/**
 * Displays a message to the console if the console verbosity level is at least as high as the message's level.
 *
 * @param   string  $message    The message to display.
 * @param   int     $verbosity  The verbosity level of the message.
 *
 * @return  void
 */
function console_writeln( string $message, int $verbosity = 0 ): void {
	$console_verbosity = \defined( 'TEAM51_CLI_VERBOSITY' ) ? TEAM51_CLI_VERBOSITY : 0;
	if ( $verbosity <= $console_verbosity ) {
		echo $message . PHP_EOL;
	}
}

/**
 * Grabs a value from the console input and validates it against a list of allowed values.
 *
 * @param   InputInterface  $input      The input instance.
 * @param   OutputInterface $output     The output instance.
 * @param   string          $name       The name of the option.
 * @param   array           $valid      The valid values for the option.
 * @param   mixed|null      $default    The default value for the option.
 *
 * @return  string|array|null
 */
function get_enum_input( InputInterface $input, OutputInterface $output, string $name, array $valid, $default = null ) {
	$option = $input->hasOption( $name ) ? $input->getOption( $name ) : $input->getArgument( $name );

	if ( $option !== $default ) {
		foreach ( (array) $option as $value ) {
			if ( ! \in_array( $value, $valid, true ) ) {
				$output->writeln( "<error>Invalid value for input '$name': $value</error>" );
				exit( 1 );
			}
		}
	}

	return $option;
}

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
		exit( 1 );
	}

	// Check email for validity.
	if ( false === \filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		$output->writeln( '<error>The provided email is invalid. Aborting!</error>' );
		exit( 1 );
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
		exit( 1 );
	}

	// Strip out everything but the hostname if we have a URL.
	if ( false !== \strpos( $site_id_or_url, 'http' ) ) {
		$site_id_or_url = \parse_url( $site_id_or_url, PHP_URL_HOST );
		if ( false === $site_id_or_url ) {
			$output->writeln( '<error>Invalid URL provided. Aborting!</error>' );
			exit( 1 );
		}
	}

	return $site_id_or_url;
}

/**
 * Grabs a value from the console input and validates it as a numeric string or an email.
 *
 * @param   InputInterface  $input          The console input.
 * @param   OutputInterface $output         The console output.
 * @param   callable|null   $no_input_func  The function to call if no input is given.
 * @param   string          $name           The name of the value to grab.
 * @param   bool            $validate       Whether to validate the input as an email or number.
 *
 * @return  string
 */
function get_user_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'user', bool $validate = true ): string {
	$user = $input->hasOption( $name ) ? $input->getOption( $name ) : $input->getArgument( $name );

	// If we don't have a user, prompt for one.
	if ( empty( $user ) && \is_callable( $no_input_func ) ) {
		$user = $no_input_func( $input, $output );
	}

	// If we still don't have a user, abort.
	if ( empty( $user ) ) {
		$output->writeln( '<error>No user was provided. Aborting!</error>' );
		exit( 1 );
	}

	// Check user for validity.
	if ( true === $validate && ! \is_numeric( $user ) && false === \filter_var( $user, FILTER_VALIDATE_EMAIL ) ) {
		$output->writeln( '<error>The provided user is invalid. Aborting!</error>' );
		exit( 1 );
	}

	return $user;
}

// endregion
