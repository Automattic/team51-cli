<?php

define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );

require __DIR__ . '/src/helpers/config-loader.php';
require __DIR__ . '/vendor/autoload.php';

if ( defined( 'ASCII_WELCOME_ART' ) && ! empty( ASCII_WELCOME_ART ) ) {
	echo ASCII_WELCOME_ART . PHP_EOL;
}

use Symfony\Component\Console\Application;

$application = new Application();

$application->add( new Team51\Command\Create_Production_Site() );
$application->add( new Team51\Command\Create_Development_Site() );
$application->add( new Team51\Command\Create_Repository() );
$application->add( new Team51\Command\Add_Branch_Protection_Rules() );
$application->add( new Team51\Command\Jetpack_Enable_SSO() );

$application->run();
