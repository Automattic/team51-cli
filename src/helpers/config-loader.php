<?php

namespace Team51\Helpers;

// Generate the config file using the 1Password data.
$config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.json';
if ( \file_exists( $config_file ) ) {
	\unlink( $config_file );
}

$template_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.tpl.json';
\exec( \sprintf( 'op inject -i %1$s -o %2$s', $template_file, $config_file ) );

// Parse the config file.
$config = decode_json_content( \file_get_contents( $config_file ), true );
if ( empty( $config ) ) {
	exit( 'Config file could not be read or it is empty. Please make sure it is properly formatted. Aborting!' . PHP_EOL );
}

foreach ( $config as $section => $secrets ) {
	foreach ( $secrets as $name => $secret ) {
		$constant_name = \strtoupper( $name );
		if ( 'general' !== $section ) {
			$constant_name = \strtoupper( $section ) . '_' . $constant_name;
		}

		\define( $constant_name, $secret );
	}
}

// Remove the config file (we don't need it anymore).
\unlink( $config_file );
