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

class Fix_Phpcs extends Command {
	protected static $defaultName = 'fix-phpcs';

	protected function configure() {
		$this
		->setDescription( 'Look for exclusions from previous Makefile' )
		->setHelp( '...' );
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
		$migrated_repos = 0;

		while ( ! empty(
			$repositories = $api_helper->call_github_api(
				sprintf( 'orgs/%s/repos?per_page=100&page=%s', GITHUB_API_OWNER, $page ),
				'',
				'GET'
			)
		) ) {
			$page++;
			foreach ( $repositories as $repository ) {

				// if ( 1 <= $migrated_repos ) {
				// 	continue;
				// }

				//if ( 'coolhunting' !== $repository->name ) {
				//	continue;
				//}

				// only check the ones we migrated
				$migrated_repos = array( 'gazzaley', 'elevationtribe', 'steambirds', 'movabletype-importer-fixers', 'tonyhinchcliffe', 'awakentheworld', 'the-portal', 'give-to-cure', 'alex-young', 'aptn', 'tinawells', 'afrodet', 'marvistafc', 'inclusive-america', 'highroadkitchens', 'barts-bagels', 'team51-donations', 'soundmind-collective', 'tumblrdotcom', 'a8cdesign', 'covid-foundation', 'coi-restaurant', 'zombiejournalism', 'thebloggess', 'saastr', 'danielpattersonorg', 'om-mailchimp-templates', 'hellajuneteenth', 'omakase-blog', 'lowercarboncapital2020', 'progressiveshopper', 'alta-adams', 'sheenaiyengar', 'therapy-for-black-girls-directory', 'hearthackersclub', 'tspa', 'black-capital', 'tim-ferriss-landing-pages', 'mst3k-org', 'chorusfm', 'dlcid', 'tiago-test-repo' );
				if (! in_array( $repository->name, $migrated_repos ) ) {
					continue;
				}

				$output->writeln( $repository->name );

				$output->writeln( "<comment>Cloning {$repository->full_name}</comment>" );
				// Pull down the repo.
				$this->execute_command( "git clone {$repository->clone_url}", TEAM51_CLI_ROOT_DIR . '/repositories' );

				// look for old makefile
				$output->writeln( "<comment>Checking for old Makefile for {$repository->name}.</comment>" );
				$this->execute_command( 'git checkout $(git rev-list -n 1 HEAD -- "Makefile")~1 -- "Makefile"', TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

				if ( file_exists( TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}/Makefile" ) ) {
					$makefile = file_get_contents( TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}/Makefile" );
					$output->writeln( "<comment>Found Makefile.</comment>" );
				}

				if ( false !== strpos( $makefile, '--ignore' ) ) {
				  $output->writeln( "<info>Found --ignore in {$repository->name}.</info>" );
				}

				$this->execute_command( "rm -R {$repository->name}", TEAM51_CLI_ROOT_DIR . '/repositories' );

				// increment for number of repos processed
				$migrated_repos++;
			}
		}

		// clean up local folder with repos
		$output->writeln( '<comment>Deleting local repositories folder.</comment>' );
		$this->execute_command( 'rm -R repositories', TEAM51_CLI_ROOT_DIR );

		$output->writeln( '<info>Total repositories processed: ' . $migrated_repos . '</info>' );
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
