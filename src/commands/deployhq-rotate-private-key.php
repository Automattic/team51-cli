<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Helper\DeployHQ_API_Helper;
use function Team51\Helper\get_deployhq_projects;

/**
 * CLI command for rotating private key in projects.
 */
final class DeployHQ_Rotate_Private_Key extends Command {
	use \Team51\Helper\Autocomplete;

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'deployhq:rotate-private-key';

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Rotates private key in DeployHQ projects.' )
			->setHelp( 'This command allows you to rotate the private key in DeployHQ projects.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$projects = get_deployhq_projects();

		foreach ( $projects as $project ) {
			$output->writeln( "{$project->permalink}: Starting key rotation." );

			if ( empty( $project->repository->url ) ) {
				$output->writeln( "{$project->permalink}: Skipped. No linked repo." );
				continue;
			}

			$data = array(
				'project' => array(
					'custom_private_key' => DEPLOYHQ_PRIVATE_KEY
				)
			);

			$response = DeployHQ_API_Helper::call_api( "projects/{$project->permalink}", 'PUT', $data );

			if ( empty( $response->public_key ) || strpos( $response->public_key, DEPLOYHQ_PUBLIC_KEY ) === false ) {
				$output->writeln( "{$project->permalink}: Failed to rotate private key." );
				continue;
			}

			$output->writeln( "{$project->permalink}: Done!" );
		}

		return Command::SUCCESS;
	}
}
