<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class Github_Repos_To_Teams extends Command {
	protected static $defaultName = 'github-repos-to-teams';
	private $api_helper;
	private $output;

	private $ACCESS_1 = array(
		'team_slug' => 'triage',
		'team_permission' => 'triage',
	);
	private $ACCESS_2 = array(
		'team_slug' => 'deploy',
		'team_permission' => 'push',
	);
	private $ACCESS_3 = array(
		'team_slug' => 'admin',
		'team_permission' => 'admin',
	);

	protected function configure() {
		$this
		->setDescription( 'Add all Repositories to all GitHub Teams in the organization, with the respective repo permission.' )
		->setHelp( 'Add all repos to our Github teams. Most-likely to be run only once, for the creation of the teams' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		$output->writeln( "<comment>Pulling all repositories from our Github organization.</comment>" );
		$repos       = array();
		$repos_page  = 1;
		$more_repos  = true;
		
		while( $more_repos ) {
			$this->output->writeln( "<comment>...</comment>" );
			$tmp_repos = $this->api_helper->call_github_api(
				sprintf( 'orgs/%s/repos?type=private&per_page=100&page=%s', GITHUB_API_OWNER, $repos_page ),
				'',
				'GET'
			);

			if ( empty($tmp_repos) ) {
				$more_repos = false;
				break;
			}

			$repos = array_merge($repos, $tmp_repos);
			$repos_page++;
		}

		$repo_names = array_column( $repos, 'name' );
		$total_repos = count($repo_names);
		$output->writeln( "<comment>{$total_repos} repositories will be added to each of our Github Teams.</comment>" );

		// Populate Teams with Repositories
		$this->populate_team_with_repos( $repo_names, $this->ACCESS_1['team_slug'], $this->ACCESS_1['team_permission'] );
		$this->populate_team_with_repos( $repo_names, $this->ACCESS_2['team_slug'], $this->ACCESS_2['team_permission'] );
		$this->populate_team_with_repos( $repo_names, $this->ACCESS_3['team_slug'], $this->ACCESS_3['team_permission'] );

		$output->writeln( "<comment>All done.</comment>" );
	}

	private function populate_team_with_repos($repo_names, $team_slug, $team_permission) {
		$this->output->writeln( "<comment>Adding repos to team '{$team_slug}'. This might take a while...</comment>" );
		foreach( $repo_names as $repo_name ) {
			$github_response = $this->api_helper->call_github_api(
				sprintf( 'orgs/%s/teams/%s/repos/%s/%s', GITHUB_API_OWNER, $team_slug, GITHUB_API_OWNER, $repo_name ),
				array(
					'permission' => $team_permission
				),
				'PUT'
			);
			if ( ! empty($github_response->message) ) {
				$this->output->writeln( "<error>Something went wrong when adding the repo '{$repo_name}' to the team '{$team_slug}'. Message: {$github_response->message}</error>" );
			}
		}
	}
}
