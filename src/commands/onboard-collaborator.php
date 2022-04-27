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

	const ACCESS_1 = 'triage';
	const ACCESS_2 = 'deploy';
	const ACCESS_3 = 'admin';


	protected function configure() {
		$this
		->setDescription( 'Adds collaborator to Github Team and Pressable sites. Collaborator can be either a11n or contractor' )
		->setHelp( 'This command allows you to bulk add a collaborator to all Pressable sites and a Github team.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, "Collaborator's email." )
		->addOption( 'github_username', null, InputOption::VALUE_REQUIRED, "Collaborator's Github username." )
		->addOption( 'github_team', null, InputOption::VALUE_REQUIRED, sprintf('Github team can be: %s, %s, or %s. Following this order, level 1 has less privileges than level 3', self::ACCESS_1, self::ACCESS_2, self::ACCESS_3) );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		// $email = $input->getOption( 'email' );
		// if ( empty( $email ) ) {
		// 	$email = trim( readline( "Please provide the collaborator's email: " ) );
		// 	if ( empty( $email ) ) {
		// 		$output->writeln( "<error>Missing collaborator's email (eg: --email=user@domain.com).</error>" );
		// 		exit;
		// 	}
		// }

		$github_user = $input->getOption( 'github_username' );
		if ( empty( $github_user ) ) {
			$github_user = trim( readline( "Please provide the collaborator's Github username : " ) );
			if ( empty( $github_user ) ) {
				$github_user->writeln( "<error>Missing collaborator's Github username (eg: --github=their_username).</error>" );
				exit;
			}
		}

		$github_team = $input->getOption( 'github_team' );
		if ( empty( $github_team ) || ! in_array($github_team, [self::ACCESS_1, self::ACCESS_2, self::ACCESS_3]) ) {
			$github_team = trim( readline( sprintf("Please provide the collaborator's Github team. Values can be: %s, %s, or %s: ", self::ACCESS_1, self::ACCESS_2, self::ACCESS_3) ) );
			if ( empty( $github_team ) || ! in_array($github_team, [self::ACCESS_1, self::ACCESS_2, self::ACCESS_3]) ) {
				$output->writeln( '<error>Missing collaborator github_team (eg: --github_team='.self::ACCESS_1.').</error>' );
				exit;
			}
		}

		// Start process
		$this->onboard_github( $github_user, $github_team );

		// TODO: onboard_pressable()


		$output->writeln( '<info>All done!<info>' );
	}


	private function onboard_github( $gh_username, $gh_team ) {
		$this->output->writeln( "<comment>Granting access to our a8cteam51 organization...</comment>" );

		// Associate Github username with Github Team
		$team_put = $this->api_helper->call_github_api(
			sprintf( 'orgs/%s/teams/%s/memberships/%s', GITHUB_API_OWNER, $gh_team, $gh_username ),
			array(
				'role' => 'member'
			),
			'PUT'
		);

		if ( ! empty( $team_put->message ) ) {
			$this->output->writeln( "<error>Something went wrong. Github says: {$team_put->message}</error>" );
		} else {
			$this->output->writeln( "<info>The user '{$gh_username}' has been added to Team '{$gh_team}'!</info>" );
		}
	}
}
