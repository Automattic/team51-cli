#!/usr/bin/env php
<?php

namespace Team51\CLI;

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Output a message to the console.
 *
 * @param string $message
 * @return void
 */
function debug( $message = '' ) {
	global $output;

	if ( ! IS_QUIET ) {
		$output->writeln( $message );
	}
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

// Initialize output.
$output = new ConsoleOutput();
$output->setFormatter( new OutputFormatter( true ) );

debug( '<fg=yellow;options=bold>Checking for updates...</>' );

// Are you a developer?
if ( IS_DEV ) {
	debug( '<fg=cyan>Running in developer mode.</>' );
}

// Reset trunk.
exec( sprintf( 'git -C %s fetch origin', __DIR__ ) );
exec( sprintf( 'git -C %s reset --hard origin/trunk', __DIR__ ) );

// Check current branch.
exec( sprintf( 'git -C %s branch --show-current', __DIR__ ), $branch );

if ( 'trunk' !== $branch[0] ) {
	if ( IS_DEV ) {
		debug( '<fg=cyan>Not switching to trunk because in developer mode.</>' );
	} else {
		exec( sprintf( "git -C %s stash list | wc -l",  __DIR__ ), $stashes );
		exec( sprintf( "git -C %s stash",  __DIR__ ) );
		exec( sprintf( "git -C %s stash list | wc -l",  __DIR__ ), $stashes );

		if ( $stashes[1] > $stashes[0] ) {
			debug( 'Stashed local changes.' );
		}

		debug( 'Switching to trunk...' );
		exec( sprintf( 'git -C %s checkout trunk', __DIR__ ) );
	}
}

// Composer update.
exec( sprintf( "composer install -o --working-dir %s", __DIR__ ) );
exec( sprintf( "composer dump-autoload -o --working-dir %s", __DIR__ ) );

require __DIR__ . '/load-application.php';
