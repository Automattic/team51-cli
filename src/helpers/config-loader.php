<?php

namespace Team51\Helper;

// Remove any legacy config files from the hard drive.
$legacy_config_files = array( 'config.json', 'config.example.json' );
foreach ( $legacy_config_files as $legacy_config_file ) {
	if ( \file_exists( TEAM51_CLI_ROOT_DIR . "/$legacy_config_file" ) ) {
		\unlink( TEAM51_CLI_ROOT_DIR . "/$legacy_config_file" );
	}
}

// Generate the config file using the 1Password data or contractor specific credentials.
$config = array();

if ( ! is_contractor_mode() ) {
	$config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.json';
	if ( \file_exists( $config_file ) ) {
		\unlink( $config_file );
	}

	$template_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.tpl.json';

	$result = \exec( \sprintf( 'op inject -i %1$s -o %2$s', $template_file, $config_file ) );
	if ( empty( $result ) ) {
		echo "\033[31mThere was an error generating the config file.\033[0m If the line above contains 'command not found', you likely need to upgrade to 1Password 8 and install the accompanying CLI tool." . PHP_EOL;
		echo "\033[36mPlease refer to the README for instructions on doing that and for the solutions to other common errors.\033[0m" . PHP_EOL;
		exit( 1 );
	}

	// Parse the config file.
	$config = decode_json_content( \file_get_contents( $config_file ), true );

	// Remove the config file (we don't need it anymore).
	\unlink( $config_file );
} else {
	$contractor_config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config__contractors.json';
	if ( \file_exists( $contractor_config_file ) ) {
		$config = decode_json_content( \file_get_contents( $contractor_config_file ), true ) ?: array();
	}
}

// Parse overwrite config file.
$overwrite_config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.overwrite.json';
if ( \file_exists( $overwrite_config_file ) ) {
	$overwrite_config = decode_json_content( \file_get_contents( $overwrite_config_file ), true ) ?: array();
	$config           = \array_replace_recursive( $config, $overwrite_config );
}

if ( empty( $config ) ) {
	exit( 'Config file could not be read or it is empty. Please make sure it is properly formatted. Aborting!' . PHP_EOL );
}

// Register secrets as constants.
foreach ( $config as $section => $secrets ) {
	foreach ( $secrets as $name => $secret ) {
		$constant_name = \strtoupper( $name );
		if ( 'general' !== $section ) {
			$constant_name = \strtoupper( $section ) . '_' . $constant_name;
		}

		\define( $constant_name, $secret );
	}
}
