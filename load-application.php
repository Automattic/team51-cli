<?php

define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );
if ( getenv( 'TEAM51_CONTRACTOR' ) ) { // Add the contractor flag automatically if set through the environment.
	$argv[] = '-c';
	$_SERVER['argv'][] = '-c';
}

// Remove --skip-update and --dev from $_SERVER['argv'].
$_SERVER['argv'] = array_filter( $_SERVER['argv'], function( $arg ) {
	return ! in_array( $arg, array( '--skip-update', '--dev' ), true );
} );

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers/config-loader.php';

if ( defined( 'ASCII_WELCOME_ART' ) && ! empty( ASCII_WELCOME_ART ) ) {
	// Respect -q and --quiet.
	if ( ! in_array( '-q', $argv ) && ! in_array( '--quiet', $argv ) ) {
		echo ASCII_WELCOME_ART . PHP_EOL;
	}
}

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

$application = new Application();

$application->add( new Team51\Command\Create_Production_Site() );
$application->add( new Team51\Command\Create_Development_Site() );
$application->add( new Team51\Command\Create_Repository() );
$application->add( new Team51\Command\Add_Branch_Protection_Rules() );
$application->add( new Team51\Command\Delete_Branch_Protection_Rules() );
$application->add( new Team51\Command\Jetpack_Enable_SSO() );
$application->add( new Team51\Command\Front_Create_Export() );
$application->add( new Team51\Command\Front_Get_Export() );
$application->add( new Team51\Command\Get_PHP_Errors() );
$application->add( new Team51\Command\Remove_User() );
$application->add( new Team51\Command\Update_Repository_Secret() );
$application->add( new Team51\Command\Plugin_List() );
$application->add( new Team51\Command\Pressable_Generate_Token() );
$application->add( new Team51\Command\Pressable_Grant_Access() );
$application->add( new Team51\Command\Pressable_Call_Api() );
$application->add( new Team51\Command\Jetpack_Modules() );
$application->add( new Team51\Command\Jetpack_Module() );
$application->add( new Team51\Command\Jetpack_Sites_With() );
$application->add( new Team51\Command\Triage_GraphQL() );
$application->add( new Team51\Command\Dump_Commands() );
$application->add( new Team51\Command\PressableConnect() );

$application->add( new Team51\Command\Site_List() );

foreach ( $application->all() as $command ) {
	$command->addOption( '--contractor', '-c', InputOption::VALUE_NONE, 'Use the contractor config file.' );
}

$application->run();
