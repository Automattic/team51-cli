#!/usr/bin/env php
<?php

namespace Team51\CLI;

/**
 * Output a message to the console.
 *
 * @param string $message
 * @return void
 */
function debug( $message = '' ) {
	if ( ! IS_QUIET ) {
		echo $message . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * CLI update routine.
 *
 * @return void
 */
function update() {
	global $argv;

	if ( IS_DEV ) {
		debug( "\033[44mRunning in developer mode. Skipping update check.\033[0m" );
	} else {
		debug( "\033[33mChecking for updates..\033[0m" );

		// Check current branch.
		exec( sprintf( 'git -C %s branch --show-current', __DIR__ ), $branch ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 'trunk' !== $branch[0] ) {
			debug( 'Not in `trunk`. Switching...' );
			exec( sprintf( 'git -C %s stash', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( sprintf( 'git -C %s checkout -f trunk', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		}

		// Reset branch.
		exec( sprintf( 'git -C %s fetch origin', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'git -C %s reset --hard origin/trunk', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	}

	// Composer update.
	exec( sprintf( 'composer install -o --working-dir %s', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( sprintf( 'composer dump-autoload -o --working-dir %s', __DIR__ ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

	// Remove first argument (the script name).
	array_shift( $argv );

	// Call itself after update with the same arguments.
	$argv[] = '--skip-update';

	if ( ! is_null( passthru( sprintf( 'team51 %s', implode( ' ', $argv ) ) ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru
		debug( "\033[31mError: Could not call self.\033[0m" );
	}
}

// Initialize environment.
$is_quiet    = false;
$is_dev      = false;
$skip_update = false;

foreach ( $argv as $arg ) {
	switch ( $arg ) {
		case '-q':
		case '--quiet':
			$is_quiet = true;
			break;
		case '--dev':
			$is_dev = true;
			break;
		case '--skip-update':
			$skip_update = true;
			break;
	}
}

// Support a .dev file to set the environment.
if ( file_exists( __DIR__ . '/.dev' ) ) {
	$is_dev = true;
}

define( 'IS_QUIET', $is_quiet );
define( 'IS_DEV', $is_dev );
define( 'SKIP_UPDATE', $skip_update );

if ( ! SKIP_UPDATE ) {
	update();
	exit;
}

require __DIR__ . '/load-application.php';
