<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Team51\Helper\DRY_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class Github_Team_Add_Repos extends Command {
	protected static $defaultName = 'github-team-add-repos';
	private $api_helper;
	private $output;

	protected function configure() {
		$this
		->setDescription( 'Add all Repositories to all GitHub Teams in the organization, with the respective repo permission.' )
		->setHelp( 'Add all repos to our Github teams. Most-likely to be run only once, for the creation of the teams' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->dry_helper = new DRY_Helper();
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
		$this->dry_helper->populate_team_with_repos( $repo_names, $this->dry_helper->GH_ACCESS_1['team_slug'], $this->dry_helper->GH_ACCESS_1['team_permission'] );
		$this->dry_helper->populate_team_with_repos( $repo_names, $this->dry_helper->GH_ACCESS_2['team_slug'], $this->dry_helper->GH_ACCESS_2['team_permission'] );
		$this->dry_helper->populate_team_with_repos( $repo_names, $this->dry_helper->GH_ACCESS_3['team_slug'], $this->dry_helper->GH_ACCESS_3['team_permission'] );

		$output->writeln( "<comment>All done.</comment>" );
	}
}
