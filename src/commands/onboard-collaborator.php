<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Onboard_Collaborator extends Command {
	protected static $defaultName = 'onboard-collaborator';
	private $api_helper;
	private $output;

	protected function configure() {
		$this
		->setDescription( 'Adds collaborator to all Github repos and Pressable sites. Collaborator can be either a11n or contractor' )
		->setHelp( 'This command allows you to bulk add a collabortor to all Pressable sites and Github repos.' )
		->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'Type of collaborator. Either a11n or contractor.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, "Collaborator's email." )
		->addOption( 'github', null, InputOption::VALUE_REQUIRED, "Collaborator's Github username." )
		->addOption( 'github_team', null, InputOption::VALUE_NONE, 'Optional Github team for this collaborator.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		$type = $input->getOption( 'type' );
		if ( empty( $type ) ) {
			$type = trim( readline( 'Please provide the type of collaborator. Enter "a11n" or "contractor": ' ) );
			if ( empty( $type ) ) {
				$output->writeln( '<error>Missing collaborator type (eg: --type=contractor).</error>' );
				exit;
			}
		}

		$email = $input->getOption( 'email' );
		if ( empty( $email ) ) {
			$email = trim( readline( 'Please provide the email of the collaborator: ' ) );
			if ( empty( $email ) ) {
				$output->writeln( "<error>Missing collaborator's email (eg: --email=user@domain.com).</error>" );
				exit;
			}
		}

		$github_user = $input->getOption( 'github' );
		if ( empty( $github_user ) ) {
			$github_user = trim( readline( 'Please provide the Github username of the collaborator: ' ) );
			if ( empty( $github_user ) ) {
				$github_user->writeln( "<error>Missing collaborator's Github username (eg: --github=their_username).</error>" );
				exit;
			}
		}

		$github_team = $input->getOption( 'github_team' );

		// Start process
		$this->onboard_github( $output, $github_user, $github_team );


		$output->writeln( '<info>All done!<info>' );
	}


	private function onboard_github( $output, $gh_username ) {
		$output->writeln( '<comment>Adding collaborator to a8cteam51 Github repos...</comment>' );
		$repo_names = exec( 'gh repo list a8cteam51 --no-archived --limit 1000 --json name' );
		$repo_names = json_decode( $repo_names );

		foreach( $repo_names as $repo_name ) {
			$repo_name = $repo_name->name;
			$output->writeln( '<comment>'.$repo_name.'</comment>' );
			$command = "gh api repos/a8cteam51/$repo_name/collaborators/$gh_username -X PUT -f permission='push'";
			passthru( $command );
		}
		$output->writeln( '<comment>All set for our Github repos.</comment>' );
	}
}
