<?php

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use function Team51\Helper\is_quiet_mode;

define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );
if ( getenv( 'TEAM51_CONTRACTOR' ) ) { // Add the contractor flag automatically if set through the environment.
	$argv[]            = '-c';
	$_SERVER['argv'][] = '-c';
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers/config-loader.php';

$application = new Application();

$application->add( new Team51\Command\Create_Production_Site() );
$application->add( new Team51\Command\Create_Development_Site() );
$application->add( new Team51\Command\Create_Repository() );
$application->add( new Team51\Command\Add_Branch_Protection_Rules() );
$application->add( new Team51\Command\Delete_Branch_Protection_Rules() );
$application->add( new Team51\Command\Jetpack_Enable_SSO() );
$application->add( new Team51\Command\Front_Create_Export() );
$application->add( new Team51\Command\Front_Get_Export() );
$application->add( new Team51\Command\Remove_User() );
$application->add( new Team51\Command\Update_Repository_Secret() );
$application->add( new Team51\Command\Plugin_List() );
$application->add( new Team51\Command\Plugin_Search() );
$application->add( new Team51\Command\Pressable_Call_Api() );
$application->add( new Team51\Command\Pressable_Generate_OAuth_Token() );
$application->add( new Team51\Command\Pressable_Site_Add_Domain() );
$application->add( new Team51\Command\Pressable_Site_Create_Collaborator() );
$application->add( new Team51\Command\Pressable_Site_Open_Shell() );
$application->add( new Team51\Command\Pressable_Site_PHP_Errors() );
$application->add( new Team51\Command\Pressable_Site_Rotate_Passwords() );
$application->add( new Team51\Command\Pressable_Site_Rotate_SFTP_User_Password() );
$application->add( new Team51\Command\Pressable_Site_Rotate_WP_User_Password() );
$application->add( new Team51\Command\Pressable_Site_Run_WP_CLI_Command() );
$application->add( new Team51\Command\Pressable_Site_Upload_Icon() );
$application->add( new Team51\Command\Jetpack_Modules() );
$application->add( new Team51\Command\Jetpack_Module() );
$application->add( new Team51\Command\Jetpack_Sites_With() );
$application->add( new Team51\Command\Triage_GraphQL() );
$application->add( new Team51\Command\Dump_Commands() );
$application->add( new Team51\Command\Site_List() );
$application->add( new Team51\Command\DeployHQ_Rotate_Private_Key() );

foreach ( $application->all() as $command ) {
	$command->addOption( '--contractor', '-c', InputOption::VALUE_NONE, 'Use the contractor config file.' );
	$command->addOption( '--dev', null, InputOption::VALUE_NONE, 'Run the CLI tool in developer mode.' );
}

$application->run();
