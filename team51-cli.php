#!/usr/bin/env php
<?php

namespace Team51\CLI;

/**
 * Output a message to the console.
 *
 * @param string $message
 * @param bool   $quiet
 * @return void
 */
function debug( $message = '', $quiet = IS_QUIET ) {
	if ( ! $quiet ) {
		echo $message . PHP_EOL;
	}
}

/**
 * Print ASCII welcome art.
 *
 * @return void
*/
function print_ascii_art() {
	$ascii_art = file_get_contents( __DIR__ . '/.ascii' );
	debug( $ascii_art );
}

/**
 * Run command.
 *
 * @param string $command
 * @return array
 */
function run_command( $command ) {
	$output      = null;
	$result_code = null;

	// Execute the command and redirect STDERR to STDOUT.
	exec( "{$command} 2>&1", $output, $result_code );

	if ( 0 !== $result_code ) {
		debug( sprintf( 'Error running command: %s', $command ), false );

		// Print the output.
		foreach ( $output as $line ) {
			debug( $line, false );
		}
	}

	return array(
		'output'      => $output,
		'result_code' => $result_code,
	);
}

/**
 * CLI update routine.
 *
 * @return void
 */
function update() {

	// Check current branch.
	$command = run_command( sprintf( 'git -C %s branch --show-current', __DIR__ ) );

	if ( 'trunk' !== $command['output'][0] ) {
		debug( 'Not in `trunk`. Switching...' );
		run_command( sprintf( 'git -C %s stash', __DIR__ ) );
		run_command( sprintf( 'git -C %s checkout -f trunk', __DIR__ ) );
	}

	// Reset branch.
	run_command( sprintf( 'git -C %s fetch origin', __DIR__ ) );
	run_command( sprintf( 'git -C %s reset --hard origin/trunk', __DIR__ ) );

	// Update Composer.
	run_command( sprintf( 'composer install -o --working-dir %s', __DIR__ ) );
	run_command( sprintf( 'composer dump-autoload -o --working-dir %s', __DIR__ ) );
}

// Initialize environment.
$is_quiet = false;
$is_dev   = false;

foreach ( $argv as $arg ) {
	switch ( $arg ) {
		case '-q':
		case '--quiet':
			$is_quiet = true;
			break;
		case '--dev':
			$is_dev = true;
			break;
	}
}

// Support a .dev file to set the environment.
if ( file_exists( __DIR__ . '/.dev' ) ) {
	$is_dev = true;
}

define( 'IS_QUIET', $is_quiet );
define( 'IS_DEV', $is_dev );

print_ascii_art();

if ( IS_DEV ) {
	debug( "\033[44mRunning in developer mode. Skipping update check.\033[0m" );
} else {
	debug( "\033[33mChecking for updates..\033[0m" );
	update();
}

require __DIR__ . '/load-application.php';
