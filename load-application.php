<?php

define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );

require __DIR__ . '/src/helpers/config-loader.php';
require __DIR__ . '/vendor/autoload.php';

if ( defined( 'ASCII_WELCOME_ART' ) && ! empty( ASCII_WELCOME_ART ) ) {
	// Respect -q and --quiet.
	if ( ! in_array( '-q', $argv ) && ! in_array( '--quiet', $argv ) ) {
		echo ASCII_WELCOME_ART . PHP_EOL;
	}
}

use Symfony\Component\Console\Application;

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
$application->add( new Team51\Command\DevQueue_Triage_Digest() );
$application->add( new Team51\Command\Update_Repository_Secret() );
$application->add( new Team51\Command\Plugin_List() );
$application->add( new Team51\Command\Pressable_Generate_Token() );
$application->add( new Team51\Command\Pressable_Grant_Access() );
$application->add( new Team51\Command\Plugin_Search() );


$application->run();
