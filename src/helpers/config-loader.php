<?php

namespace Team51\Helpers;

// Remove any legacy config files from the hard drive.
$legacy_config_files = array( 'config.json', 'config.example.json' );
foreach ( $legacy_config_files as $legacy_config_file ) {
	if ( \file_exists( TEAM51_CLI_ROOT_DIR . "/$legacy_config_file" ) ) {
		\unlink( TEAM51_CLI_ROOT_DIR . "/$legacy_config_file" );
	}
}

// Generate the config file using the 1Password data.
$config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.json';
if ( \file_exists( $config_file ) ) {
	\unlink( $config_file );
}

$template_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.tpl.json';
if ( \in_array( '-c', $argv, true ) || \in_array( '--contractor', $argv, true ) ) {
	$template_file = TEAM51_CLI_ROOT_DIR . '/secrets/config__contractors.tpl.json';
}

$result = \shell_exec( \sprintf( 'op inject -i %1$s -o %2$s', $template_file, $config_file ) );
if ( empty( $result ) ) {
	echo "\033[31mThere was an error generating the config file.\033[0m If the line above contains \'command not found\', you likely need to upgrade to 1Password 8 and install the accompanying CLI tool." . PHP_EOL;
	echo "\033[36mPlease refer to the README for instructions on doing that and the solution to other common errors.\033[0m" . PHP_EOL;
	exit( 1 );
}

// Parse the config file.
$config = decode_json_content( \file_get_contents( $config_file ), true );
if ( empty( $config ) ) {
	exit( 'Config file could not be read or it is empty. Please make sure it is properly formatted. Aborting!' . PHP_EOL );
}

// Parse overwrite config file.
$overwrite = array();
if ( \file_exists( TEAM51_CLI_ROOT_DIR . '/secrets/config.overwrite.json' ) ) {
	$overwrite = decode_json_content( \file_get_contents( TEAM51_CLI_ROOT_DIR . '/secrets/config.overwrite.json' ), true ) ?? array();
}

foreach ( $config as $section => $secrets ) {
	foreach ( $secrets as $name => $secret ) {
		$constant_name = \strtoupper( $name );
		if ( 'general' !== $section ) {
			$constant_name = \strtoupper( $section ) . '_' . $constant_name;
		}

		\define( $constant_name, $overwrite[ $section ][ $name ] ?? $secret );
	}
}

// Remove the config file (we don't need it anymore).
\unlink( $config_file );
