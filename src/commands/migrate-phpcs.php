<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Migrate_Phpcs extends Command {
	protected static $defaultName = 'migrate-phpcs';

	protected function configure() {
		$this
		->setDescription( 'Removes Travis and adds a GH Action for PHPCS code inspections.' )
		->setHelp( 'Allows the removal of Travis checks and adds in the GH Action equivalent PHPCS inspections...' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$filesystem = new Filesystem();

		$api_helper = new API_Helper();

		$output->writeln( '<comment>Creating local repositories folder.</comment>' );
		$this->execute_command( 'mkdir -p repositories', TEAM51_CLI_ROOT_DIR );

		$output->writeln( '<comment>Retrieving all repositories from GitHub org.</comment>' );

		$repositories = $api_helper->call_github_api(
			sprintf( 'orgs/%s/repos', GITHUB_API_OWNER ),
			'',
			'GET'
		);

		if ( empty( $repositories ) ) {
			$output->writeln( '<error>Failed to retrieve repositories. Aborting!</error>' );
			exit;
		}

		$page = 1;

		while ( ! empty(
			$repositories = $api_helper->call_github_api(
				sprintf( 'orgs/%s/repos?per_page=100&page=%s', GITHUB_API_OWNER, $page ),
				'',
				'GET'
			)
		) ) {
			$page++;
			$migrated_repos = 0;
			foreach ( $repositories as $repository ) {

				$output->writeln( $repository->name );
				if ( 3 <= $migrated_repos ) {
					continue;
				}
				if ( 'deployhq-test' === $repository->name ) {
					continue;
				}

				$output->writeln( "<comment>Cloning {$repository->full_name}</comment>" );
				// Pull down the repo.
				$this->execute_command( "git clone {$repository->clone_url}", TEAM51_CLI_ROOT_DIR . '/repositories' );

				// skip this repo if travis file doesn't exist.
				if (! file_exists( TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}/.travis.yml" ) ) {
					$output->writeln( "<comment>{$repository->name} doesn't have travis file. skipping.</comment>" );
					continue;
				}

				// Create a new branch named migrate_phpcs from master.
				$this->execute_command( 'git checkout -b migrate_phpcs master', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git push -u origin migrate_phpcs', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

				// Remove branch protection.
				$delete_branch_protection_rules_request = $api_helper->call_github_api(
					sprintf( 'repos/%s/%s/branches/master/protection', GITHUB_API_OWNER, $repository->name ),
					'',
					'DELETE'
				);

				// delete unwanted travis files
				$output->writeln( '<comment>Deleting Travis files.</comment>' );
				$this->execute_command( 'rm -f -- .travis.yml', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'rm -f -- Makefile', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

				// create new GH Action files
				$output->writeln( "<comment>Copying .phpcs.xml and phpcs.yml files to {$repository->name}.</comment>" );
				$filesystem->copy( TEAM51_CLI_ROOT_DIR . '/scaffold/templates/.phpcs.xml', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}/.phpcs.xml" );
				$filesystem->copy( TEAM51_CLI_ROOT_DIR . '/scaffold/templates/github/workflows/phpcs.yml', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}/.github/workflows/phpcs.yml" );

				// commit, push and merge the changes
				$output->writeln( "<comment>Committing, pushing and merging the changes to {$repository->name}.</comment>" );
				$this->execute_command( "git add -A", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( "git commit -m 'Migrate PHPCS checks'", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git push', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git checkout master', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git merge migrate_phpcs', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git push origin master', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
				$this->execute_command( 'git push origin --delete migrate_phpcs', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

				// add new branch protection rule.
				$branch_protection_rules = array(
					'required_status_checks'        => array(
						'strict'   => true,
						'contexts' => array(
							'Run PHPCS inspection',
						),
					),
					'enforce_admins'                => null,
					'required_pull_request_reviews' => null,
					'restrictions'                  => null,
				);

				$branch_protection_rules = $api_helper->call_github_api( 'repos/' . GITHUB_API_OWNER . "/{$repository->name}/branches/master/protection", $branch_protection_rules, 'PUT' );

				if ( ! empty( $branch_protection_rules->required_status_checks->contexts ) ) {
					$output->writeln( "<comment>Added branch protection rules to {$repository->name}.</comment>" );
				} else {
					$output->writeln( "<info>Failed to add branch protection rules to {$repository->name}.</info>" );
				}

				// increment for number of repos migrated
				$migrated_repos++;
			}
		}

		// clean up local folder with repos
		$output->writeln( '<comment>Deleting local repositories folder.</comment>' );
		$this->execute_command( 'rm -R repositories', TEAM51_CLI_ROOT_DIR );

		$output->writeln( '<info>Total repositories migrated: ' . $migrated_repos . '</info>' );
	}

	protected function execute_command( $command, $working_directory = '.' ) {
		$process = new Process( $command );
		$process->setWorkingDirectory( $working_directory );
		$process->run();

		if ( ! $process->isSuccessful() ) {
			throw new ProcessFailedException( $process );
		}

		return $process->getOutput();
	}
}
