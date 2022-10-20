<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class PressableConnect extends Command {
	protected static $defaultName = 'pressable-sftp-user';

	protected function configure() {
		$this
		->setDescription( 'Retrieves SFTP Username for a Pressable site.' )
		->setHelp( 'In the future, this command will also be able to connect you directly via SSH or SFTP.' )
		->addArgument( 'site-id', InputArgument::REQUIRED, 'Site ID to check.' )
		->addOption( 'email', null, InputOption::VALUE_OPTIONAL, 'The email address to check. Defaults to site owner (usually concierge@wordpress.com', false );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();
		$site_id    = $input->getArgument( 'site-id' );
		$email      = $input->getOption( 'email' );

		$output->writeln( "Retrieving SFTP users for site ID $site_id" );
		$username = $api_helper->get_sftp_user( $site_id, $email );

		if ( $username ) {
			$output->writeln( "SFTP username: $username" );
		} else {
			$email = $email ? $email : 'site owner';
			$output->writeln( "No username found for $email on site ID $site_id." );
		}
	
	}
}
