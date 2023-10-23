<?php

namespace Team51\Command;

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Team51\Helper\DeployHQ_API_Helper;
use Team51\Helper\GitHub_API_Helper;
use Team51\Helper\Pressable_Connection_Helper;
use Team51\Helper\WPCOM_API_Helper;
use Team51\Helper\WPCOM_Connection_Helper;
use function Team51\Helper\get_pressable_site_by_id;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_wpcom_site;
use function Team51\Helper\run_wpcom_site_wp_cli_command;

class Create_Development_Site_WPCOM extends Command {
	protected static $defaultName = 'wpcom:create-development-site';

	protected function configure() {
		$this
		->setDescription( 'Creates a new development site (on WPCOM).' )
		->setHelp( 'This command allows you to create a new development site.' )
		->addOption( 'site-id', null, InputOption::VALUE_REQUIRED, "The site ID of the production WPCOM site you'd like to clone." )
		->addOption( 'temporary-clone', null, InputOption::VALUE_NONE, 'Creates a temporary clone of the production site for short-term development work. The site created is meant to be deleted after use.' )
		->addOption( 'skip-safety-net', null, InputOption::VALUE_NONE, 'Skips adding the Safety Net plugin to the development clone.' )
		->addOption( 'branch', null, InputOption::VALUE_REQUIRED, "The GitHub branch you would like to the development site to use. Defaults to 'develop'." );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		$production_site_id = $input->getOption( 'site-id' );
		$staging_site = null;

		if ( empty( $input->getOption( 'site-id' ) ) ) {
			$output->writeln( '<error>Site ID is required for development site creation.</error>' );
			exit;
		}

		$output->writeln( "<comment>Verifying existance of #$production_site_id.</comment>" );

		// Get site information
		$site = get_wpcom_site( $input->getOption( 'site-id' ) );

		if ( empty( $site ) ) {
			$output->writeln( '<error>Something has gone wrong while looking up the WPCOM production site. Aborting!</error>' );
			exit;
		}

		$site_name_data = parse_url( $site->URL );
		$site_name_data = explode( '.', $site_name_data['host'] );
		$project_name   = $site_name_data[0];

		$progress_bar = new ProgressBar( $output );

		$output->writeln( "<info>#$production_site_id. exist as $site->URL</info>" );

		$output->writeln( "<comment>Checking if a staging site already exists.</comment>" );

		$existing_staging_sites = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/staging-site', [], 'GET', true );

		if ( $existing_staging_sites ) {
			$existing_staging_site = $existing_staging_sites[0];
			$output->writeln( "<error>Staging site already exists $existing_staging_site->url.</error>" );
			// Maybe ask if they want to delete it and create a new one?
			$helper = $this->getHelper('question');
			$question = new ChoiceQuestion(
				'Next action?',
				['Delete' => 'Delete and create new', 'Connect' => 'Use existing to connect to DeployHQ'],
				'Delete'
			);

			$next_step = $helper->ask($input, $output, $question);

			if ( 'Delete' === $next_step ) {

				$output->writeln( "<comment>Deleting staging site $existing_staging_site->url.</comment>" );

				$delete_status_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/staging-site/' . $existing_staging_site->id, [], 'DELETE', true );

				if ( isset( $delete_status_response->errors ) ) {
					$site_delete_errors = implode( '', $delete_status_response->errors );
					$output->writeln( "<error>WPCOM error while deleting the staging site: $site_delete_errors - Aborting!</error>" );
					exit;
				}

				$i               = 0;
				$transfer_status = 'started';

				$progress_bar->start();

				do {
					$progress_bar->advance();
					sleep( 2 );

					if ( 0 === $i % 5 ) {
						$transfer_status_response = WPCOM_API_Helper::call_site_wpcom_api( $existing_staging_site->id, '/automated-transfers/status/' );

						if ( null === $transfer_status_response ) {
							$transfer_status = null;
						} else {
							$transfer_status          = $transfer_status_response->status;
						}

						$output->writeln( "\n<info>Creation status: {$transfer_status_response->status}</info>" );
					}

					++$i;

				} while ( null !== $transfer_status );

				$progress_bar->finish();

				$output->writeln( "\n<info>Staging site deleted.</info>" );

			}

			if ( 'Connect' === $next_step ) {
				$staging_site = $existing_staging_site;
				$output->writeln( '<comment>Connecting existing staging site to DeployHQ.</comment>' );
			}

		}

		if ( null === $staging_site ) {
			$output->writeln( "<info>Staging site does not exist.</info>" );
			$output->writeln( '<comment>Checking whether the current site has sufficient space for a staging site.</comment>' );

			$site_has_sufficient_space_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/staging-site/validate-quota', [], 'POST', true );

			if ( true !== $site_has_sufficient_space_response ) {
				$output->writeln( "<info>#$production_site_id has no sufficient space for a staging site.</info>" );
				exit;
			}

			$output->writeln( "<info>#$production_site_id has sufficient space for a new staging site.</info>" );

			$output->writeln( "<comment>Creating a new staging site for #$production_site_id.</comment>" );

			$staging_site = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/staging-site', [], 'POST', true );

			if ( isset( $staging_site->errors ) ) {
				$site_creation_errors = implode( '', $staging_site->errors );
				$output->writeln( "<error>WPCOM error while creating new staging site: $site_creation_errors - Aborting!</error>" );
				exit;
			}

			$i               = 0;
			$transfer_status = 'started';

			$progress_bar->start();

			do {
				$progress_bar->advance();
				sleep( 2 );

				if ( 0 === $i % 5 ) {
					$transfer_status_response = WPCOM_API_Helper::call_site_wpcom_api( $staging_site->id, '/automated-transfers/status/' );
					$transfer_status          = $transfer_status_response->status;
					$output->writeln( "\n<info>Creation status: {$transfer_status_response->status}</info>" );
				}

				++$i;

			} while ( 'complete' !== $transfer_status );

			$progress_bar->finish();

		}

		$output->writeln( "\n<info>Created a new staging site $staging_site->url.</info>" );

//		$output->writeln( '<comment>Creating 1Password login entry for the concierge user.</comment>' );
//		/* @noinspection PhpUnhandledExceptionInspection */
//		run_app_command(
//			$this->getApplication(),
//			Pressable_Site_Rotate_WP_User_Password::getDefaultName(),
//			array( 'site' => $pressable_site->data->id ),
//			$output
//		);

		// Check if site has the SFTP/SSH user
		$ssh_users_response = WPCOM_API_Helper::call_site_wpcom_api( $staging_site->id, '/hosting/ssh-users', array(), 'GET', true );

		if ( empty( $ssh_users_response->users ) ) {
			$output->writeln( '<comment>Creating the SFTP/SSH user.</comment>' );

			$create_ssh_user_response = WPCOM_API_Helper::call_site_wpcom_api( $staging_site->id, '/hosting/ssh-user', array(), 'POST', true );

			if ( ! isset( $create_ssh_user_response->username ) ) {
				$output->writeln( '<error>There was an issue when creating the SFTP/SSH user. Safety Net not installed.</error>' );
				exit;
			}

			$output->writeln( "<info>Created the SFTP/SSH user.</info>\n" );

			$ssh_user['username'] = $create_ssh_user_response->username;

		} else {
			$ssh_user['username'] = $ssh_users_response->users[0];
		}

		$output->writeln( '<comment>Enabling SSH.</comment>' );
		$enable_ssh_response = WPCOM_API_Helper::call_site_wpcom_api( $staging_site->id, '/hosting/ssh-access', array( 'setting' => 'ssh' ), 'POST', true );

		if ( 'ssh' !== $enable_ssh_response->setting ) {
			$output->writeln( '<error>Failed to enable SSH.</error>' );
			exit;
		}

		$enable_ssh_key = WPCOM_API_Helper::call_site_wpcom_api( $staging_site->id, '/hosting/ssh-keys', array( 'name' => 'default' ), 'POST', true );

		if ( ! $enable_ssh_key ) {
			$output->writeln( '<comment>There was an issue with attaching the SSH Key or it\'s already added.</comment>' );
		}

		$output->writeln( '<info>SSH Key attached.</info>' );
		$output->writeln( '<info>SSH enabled.</info>' );


		//		run_pressable_site_wp_cli_command(
//			$this->getApplication(),
//			$pressable_site->data->id,
//			'config set WP_ENVIRONMENT_TYPE staging --type=constant',
//			$output
//		);

		if ( ! empty( $input->getOption( 'skip-safety-net' ) ) ) {
			$output->writeln( '<comment>Skipping Safety Net installation.</comment>' );
		} else {
			run_wpcom_site_wp_cli_command(
				$this->getApplication(),
				$staging_site->id,
				'plugin install https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip',
				$output
			);

			$ssh_connection = WPCOM_Connection_Helper::get_ssh_connection( $staging_site->id );

			if ( ! is_null( $ssh_connection ) ) {
				$ssh_connection->exec( 'mv -f htdocs/wp-content/plugins/safety-net htdocs/wp-content/mu-plugins/safety-net' );
				$ssh_connection->exec( 'ls htdocs/wp-content/mu-plugins', function ( $result ) use ( $staging_site, $output ) {
					if ( false === strpos( $result, 'safety-net' ) ) {
						$output->writeln( "<error>Failed to install Safety Net on {$staging_site->id}.</error>" );
					}
					if ( false === strpos( $result, 'load-safety-net.php' ) ) {
						$output->writeln( "<comment>Copying Safety Net loader to mu-plugins folder...</comment>" );

						$sftp   = WPCOM_Connection_Helper::get_sftp_connection( $staging_site->id );
						$result = $sftp->put( '/htdocs/wp-content/mu-plugins/load-safety-net.php', file_get_contents(__DIR__ . '/../../scaffold/load-safety-net.php' ) );
						if ( ! $result ) {
							$output->writeln( "<error>Failed to copy safety-net-loader.php to {$staging_site->id}.</error>" );
						}
					}
				} );
			}
		}

		$server_config = array(
			'name'        => ! empty( $input->getOption( 'temporary-clone' ) ) ? 'Development-' . $staging_site->id : 'Development',
			'environment' => 'development',
			'branch'      => ! empty( $input->getOption( 'branch' ) ) ? $input->getOption( 'branch' ) : 'develop',
			'sftp_username' => $ssh_user['username'],
			'server_path'   => 'wp-content',
		);

		$output->writeln( '<comment>Retrieving project info from DeployHQ.</comment>' );

		$project_info = DeployHQ_API_Helper::call_api( "projects/{$project_name}" );

		if ( empty( $project_info ) || empty( $project_info->permalink ) ) {
			$output->writeln( '<error>Failed to retrieve project info from DeployHQ. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( '<info>Retrieved project info from DeployHQ.</info>' );
		}

		$output->writeln( '<comment>Retrieving GitHub repo from the DeployHQ project.</comment>' );

		if ( empty( $project_info ) || empty( $project_info->repository->url ) ) {
			$output->writeln( '<error>Failed to retrieve GitHub repo from the DeployHQ project. Aborting!</error>' );
			exit;
		} else {
			$repository_url = $project_info->repository->url;
			$output->writeln( '<info>Successfully retrieved GitHub repo from the DeployHQ project.</info>' );
		}

		// Verify 'develop' branch exists in the GitHub repo, otherwise adding the new development server to DeployHQ will fail.
		$output->writeln( "<comment>Verifying 'develop' branch exists in GitHub repo.</comment>" );

		$develop_branch = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s/git/ref/heads/develop', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ) );

		if ( empty( $develop_branch->ref ) ) {
			$output->writeln( "<comment>No 'develop' branch present. Creating one now.</comment>" );

			// Grab SHA for trunk branch so we can use it to create the develop branch from that point.
			$trunk_branch = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s/git/ref/heads/trunk', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ) );

			if ( empty( $trunk_branch->object->sha ) ) {
				$output->writeln( "<error>Failed to retrieve 'trunk' branch SHA. Aborting!</error>" );
				exit;
			}

			$develop_branch = GitHub_API_Helper::call_api(
				sprintf( 'repos/%s/%s/git/refs', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ),
				'POST',
				array(
					'ref' => 'refs/heads/develop',
					'sha' => $trunk_branch->object->sha,

				),
			);

			if ( empty( $develop_branch->ref ) ) {
				$output->writeln( "<error>Failed to create 'develop' branch. Aborting!</error>" );
				exit;
			} else {
				$output->writeln( "<info>Successfully created 'develop' branch!</info>" );
			}
		} else {
			$output->writeln( "<info>Verified 'develop' branch exists.</info>" );
		}

		$output->writeln( '<comment>Connecting DeployHQ project to GitHub repository.</comment>' );

		$deploy_hq_add_repository_request = DeployHQ_API_Helper::call_api(
			"projects/{$project_info->permalink}/repository",
			'POST',
			array(
				'repository' => array(
					'scm_type' => 'git',
					'url'      => $repository_url,
					'branch'   => 'develop',
					'username' => null,
					'port'     => null,
				),
			)
		);

		if ( empty( $deploy_hq_add_repository_request ) || empty( $deploy_hq_add_repository_request->url ) ) {
			$output->writeln( '<error>Failed to add GitHub repository to DeployHQ. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( '<info>Successfully added and configured GitHub repository in DeployHQ.</info>' );
		}

		$output->writeln( "<comment>Creating new DeployHQ {$server_config['environment']} server for project $project_name.</comment>" );

		$progress_bar = new ProgressBar( $output );
		$progress_bar->start();
		while ( empty( $server_info ) || empty( $server_info->host_key ) ) {
			$server_info = DeployHQ_API_Helper::call_api(
				"projects/{$project_info->permalink}/servers",
				'POST',
				array(
					'server' => array(
						'name'               => $server_config['name'],
						'protocol_type'      => 'ssh',
						'use_ssh_keys'       => true,
						'server_path'        => $server_config['server_path'],
						'email_notify_on'    => 'never',
						'root_path'          => '',
						'auto_deploy'        => true,
						'notification_email' => '',
						'branch'             => $server_config['branch'],
						'environment'        => $server_config['environment'],
						'hostname'           => 'sftp.wp.com',
						'username'           => $server_config['sftp_username'],
						'port'               => 22,
					),
				)
			);

			if ( empty( $server_info->host_key ) && ! empty( $server_info->branch ) ) {
				$output->writeln( '' );
				$output->writeln( "<error>Branch {$server_config['branch']} doesn't exist in GitHub! Please create it and try again. Aborting!</error>" );
				exit;
			}

			$progress_bar->advance();
			sleep( 1 );
		}

		$progress_bar->finish();
		$output->writeln( '' );

		if ( empty( $server_info ) || empty( $server_info->host_key ) ) {
			$output->writeln( '<error>Failed to create new server in DeployHQ. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Created new server in DeployHQ.</info>\n" );
		}

		$output->writeln( '<comment>Verifying we received a webhook URL for automatic deploys when we created the new DeployHQ project.</comment>' );
		if ( empty( $project_info ) || empty( $project_info->auto_deploy_url ) ) {
			$output->writeln( '<error>Failed to retrieve webhook URL from new DeployHQ project. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Successfully retrieved webhook URL from new DeployHQ project.</info>\n" );
		}

		$output->writeln( "\n<info>Deploy HQ is now set up and ready to start receiving and deploying commits to the new staging site $staging_site->url.</info>\n" );

		$output->writeln( '' );

		$site_name_for_slack = $input->getOption( 'site-id' );

//		$api_helper->log_to_slack(
//			sprintf(
//				'INFO: Pressable / DeployHQ: %s run for %s',
//				'create-development-site',
//				$site_name_for_slack
//			)
//		);

		exit;
	}

	// region HELPERS

	/**
	 * Periodically checks the status of a Pressable site until it's no longer in the given state.
	 *
	 * @param   string              $site_id    The site ID.
	 * @param   string              $state      The state to wait on. Can be 'deploying' or 'cloning'.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  void
	 */
	private function wait_on_site_state( string $site_id, string $state, OutputInterface $output ): void {
		$output->writeln( "<comment>Waiting for Pressable site to exit $state state.</comment>" );

		$progress_bar = new ProgressBar( $output );
		$progress_bar->start();

		do {
			$pressable_site = get_pressable_site_by_id( $site_id );
			if ( empty( $pressable_site ) ) {
				$output->writeln( '<error>Something has gone wrong while checking on the Pressable site. Aborting!</error>' );
				exit( 1 );
			}

			$progress_bar->advance();
			sleep( 'deploying' === $state ? 1 : 10 );
		} while ( $state === $pressable_site->state );

		$progress_bar->finish();
		$output->writeln( '' ); // Empty line for UX purposes.
	}

	/**
	 * Periodically checks the status of the SSH connection to a Pressable site until it's ready.
	 *
	 * @param   string              $site_id    The site ID.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  SSH2|null
	 */
	private function wait_on_site_ssh( string $site_id, OutputInterface $output ): ?SSH2 {
		$output->writeln( '<comment>Waiting for Pressable site to be ready for SSH.</comment>' );

		$ssh_connection = null;

		if ( ! empty( get_pressable_site_sftp_user_by_email( $site_id, 'concierge@wordpress.com' ) ) ) {
			$progress_bar = new ProgressBar( $output );
			$progress_bar->start();

			for ( $try = 0, $delay = 5; $try <= 24; $try++ ) {
				$ssh_connection = Pressable_Connection_Helper::get_ssh_connection( $site_id );
				if ( ! \is_null( $ssh_connection ) ) {
					break;
				}

				$progress_bar->advance();
				sleep( $delay );
			}

			$progress_bar->finish();
			$output->writeln( '' ); // Empty line for UX purposes.
		}

		return $ssh_connection;
	}

	// endregion
}
