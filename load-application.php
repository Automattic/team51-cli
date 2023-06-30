<?php

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );
if ( getenv( 'TEAM51_CONTRACTOR' ) ) { // Add the contractor flag automatically if set through the environment.
	$argv[]            = '-c';
	$_SERVER['argv'][] = '-c';
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers/config-loader.php';

$application = new Application();

$application->addCommands(
	array(
		new Team51\Command\Create_Production_Site(),
		new Team51\Command\Create_Development_Site(),
		new Team51\Command\Create_Repository(),
		new Team51\Command\Add_Branch_Protection_Rules(),
		new Team51\Command\Delete_Branch_Protection_Rules(),
		new Team51\Command\Jetpack_Enable_SSO(),
		new Team51\Command\Remove_User(),
		new Team51\Command\Update_Repository_Secret(),
		new Team51\Command\Plugin_List(),
		new Team51\Command\Plugin_Search(),
		new Team51\Command\Pressable_Call_Api(),
		new Team51\Command\Pressable_Generate_OAuth_Token(),
		new Team51\Command\Pressable_Site_Add_Domain(),
		new Team51\Command\Pressable_Site_Create_Collaborator(),
		new Team51\Command\Pressable_Site_Open_Shell(),
		new Team51\Command\Pressable_Site_PHP_Errors(),
		new Team51\Command\Pressable_Site_Rotate_Passwords(),
		new Team51\Command\Pressable_Site_Rotate_SFTP_User_Password(),
		new Team51\Command\Pressable_Site_Rotate_WP_User_Password(),
		new Team51\Command\Pressable_Site_Run_WP_CLI_Command(),
		new Team51\Command\Pressable_Site_Upload_Icon(),
		new Team51\Command\Jetpack_Modules(),
		new Team51\Command\Jetpack_Module(),
		new Team51\Command\Jetpack_Sites_With(),
		new Team51\Command\Triage_GraphQL(),
		new Team51\Command\Dump_Commands(),
		new Team51\Command\Site_List(),
		new Team51\Command\Plugin_Summary(),
		new Team51\Command\Get_Site_Stats(),
		new Team51\Command\Get_WooCommerce_Stats(),
		new Team51\Command\DeployHQ_Rotate_Private_Key(),
		new Team51\Command\WPCOM_Get_Stickers(),
		new Team51\Command\WPCOM_Add_Sticker(),
		new Team51\Command\WPCOM_Remove_Sticker(),
		new Team51\Command\Create_Production_Site_WPCOM(),
	)
);

foreach ( $application->all() as $command ) {
	$command->addOption( '--contractor', '-c', InputOption::VALUE_NONE, 'Use the contractor config file.' );
	$command->addOption( '--dev', null, InputOption::VALUE_NONE, 'Run the CLI tool in developer mode.' );
}

$application->run();
