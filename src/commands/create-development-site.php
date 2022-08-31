<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class Create_Development_Site extends Command {
	protected static $defaultName = 'create-development-site';

	protected function configure() {
		$this
		->setDescription( 'Creates a new development site (on Pressable).' )
		->setHelp( 'This command allows you to create a new development site.' )
		->addOption( 'site-id', null, InputOption::VALUE_REQUIRED, "The site ID of the production Pressable site you'd like to clone." )
		->addOption( 'temporary-clone', null, InputOption::VALUE_NONE, 'Creates a temporary clone of the production site for short-term development work. The site created is meant to be deleted after use.' )
		->addOption( 'label', null, InputOption::VALUE_REQUIRED, 'Used to name the Pressable instance. If not specified, time() will be used.' )
		->addOption( 'branch', null, InputOption::VALUE_REQUIRED, "The GitHub branch you would like to the development site to use. Defaults to 'develop'." );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		$production_site_id = $input->getOption( 'site-id' );

		if ( empty( $input->getOption( 'site-id' ) ) ) {
			$output->writeln( '<error>Site ID is required for development site creation.</error>' );
			exit;
		}

		$output->writeln( "<comment>Creating a new Pressable site by cloning #$production_site_id.</comment>" );
		$pressable_site = $api_helper->call_pressable_api(
			"sites/$production_site_id",
			'GET',
			array()
		);

		// TODO: This code is duplicated below for the site clone. Should be a function.
		if ( empty( $pressable_site->data ) || empty( $pressable_site->data->id ) ) {
			$output->writeln( '<error>Something has gone wrong while looking up the Pressable production site. Aborting!</error>' );
			exit;
		}

		// If the production site was created with this script, follow the same naming convention.
		if ( false !== strpos( $pressable_site->data->name, '-production' ) ) {
      $site_name    = str_replace( '-production', '-development', $pressable_site->data->name );
      $project_name = str_replace( '-development', '', $site_name );
		} else {
      $site_name    = str_replace( '-development', '', $pressable_site->data->name ) . '-development';
      $project_name = $pressable_site->data->name;
		}

		if ( ! empty( $input->getOption( 'temporary-clone' ) ) ) {
			if ( ! empty( $input->getOption( 'label' ) ) ) {
				$site_name = str_replace( '-development', '', $site_name );
				$label  = $input->getOption( 'label' );
			} else {
				$label = time();
			}
			$site_name .= '-' . $label;
		}

		$pressable_site = $api_helper->call_pressable_api(
			"sites/$production_site_id/clone",
			'POST',
			array(
				'name' => $site_name,
			)
		);

    // catching and displaying useful errors here
    if ( $pressable_site->errors ) {
      $site_creation_errors = '';
      foreach( $pressable_site->errors as $error ) {
        $site_creation_errors .= $error;
      }
      $output->writeln( "<error>Pressable error while creating new site: $site_creation_errors - Aborting!</error>" );
      exit;
    }

		// TODO this code is duplicated above
		if ( empty( $pressable_site->data ) || empty( $pressable_site->data->id ) ) {
			$output->writeln( '<error>Failed to create new Pressable site. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( '<info>Created new Pressable site.</info>' );
		}

		$output->writeln( '<comment>Waiting for Pressable site to deploy.</comment>' );
		$progress_bar = new ProgressBar( $output );
		$progress_bar->start();
		do {
			$pressable_site_check = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}", 'GET', array() );

			if ( empty( $pressable_site_check->data ) || empty( $pressable_site_check->data->id ) ) {
				$output->writeln( '<error>Something has gone wrong while checking on the Pressable site. Aborting!</error>' );
				exit;
			}

			if ( ! empty( $pressable_site_check->data->state ) ) {
				$pressable_site_state = $pressable_site_check->data->state;
			} else {
				$pressable_site_state = 'deployed';
			}

			$progress_bar->advance();
			sleep( 1 );
		} while ( 'deploying' === $pressable_site_state );

		$progress_bar->finish();
		$output->writeln( '' );
		$output->writeln( "<info>The Pressable site has been deployed!</info>\n" );

		$server_config = array(
			'name'        => ! empty( $input->getOption( 'temporary-clone' ) ) ? 'Development-' . time() : 'Development',
			'environment' => 'development',
			'branch'      => ! empty( $input->getOption( 'branch' ) ) ? $input->getOption( 'branch' ) : 'develop',
		);

		// Set server config elements common to production and development environments.
		$server_config['server_path'] = 'wp-content';

		// Grab SFTP connection info from Pressable.
		$ftp_data = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}/ftp", 'GET', array() );

		if ( ! empty( $ftp_data->data ) ) {
			foreach ( $ftp_data->data as $ftp_user ) {
				if ( true === $ftp_user->owner ) { // If concierge@wordpress.com is the owner, grab the info.
					$server_config['pressable_sftp_username'] = $ftp_user->username;
					$server_config['pressable_sftp_hostname'] = $ftp_user->sftpDomain;

					$password_reset = $api_helper->call_pressable_api( "sites/{$pressable_site->data->id}/ftp/password/{$ftp_user->username}", 'POST', array() );
					if ( ! empty( $password_reset->data ) ) {
						$server_config['pressable_sftp_password'] = $password_reset->data;
					}
				}
			}
		}

		$output->writeln( '<comment>Retrieving project info from DeployHQ.</comment>' );

		$project_info = $api_helper->call_deploy_hq_api( "projects/{$project_name}", 'GET', array() );

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

		$develop_branch = $api_helper->call_github_api(
			sprintf( 'repos/%s/%s/git/ref/heads/develop', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ),
			'',
			'GET'
		);

		if ( empty( $develop_branch->ref ) ) {
			$output->writeln( "<comment>No 'develop' branch present. Creating one now.</comment>" );

			// Grab SHA for trunk branch so we can use it to create the develop branch from that point.
			$trunk_branch = $api_helper->call_github_api(
				sprintf( 'repos/%s/%s/git/ref/heads/trunk', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ),
				'',
				'GET'
			);

			if ( empty( $trunk_branch->object->sha ) ) {
				$output->writeln( "<error>Failed to retrieve 'trunk' branch SHA. Aborting!</error>" );
				exit;
			}

			$develop_branch = $api_helper->call_github_api(
				sprintf( 'repos/%s/%s/git/refs', GITHUB_API_OWNER, basename( $project_info->repository->url, '.git' ) ),
				array(
					'ref' => 'refs/heads/develop',
					'sha' => $trunk_branch->object->sha,

				),
				'POST'
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

		$deploy_hq_add_repository_request = $api_helper->call_deploy_hq_api(
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

		$progress_bar->start();
		while ( empty( $server_info ) || empty( $server_info->host_key ) ) {
			$server_info = $api_helper->call_deploy_hq_api(
				"projects/{$project_info->permalink}/servers",
				'POST',
				array(
					'server' => array(
						'name'               => $server_config['name'],
						'protocol_type'      => 'ssh',
						'server_path'        => $server_config['server_path'],
						'email_notify_on'    => 'never',
						'root_path'          => '',
						'auto_deploy'        => true,
						'notification_email' => '',
						'branch'             => $server_config['branch'],
						'environment'        => $server_config['environment'],
						'hostname'           => $server_config['pressable_sftp_hostname'],
						'username'           => $server_config['pressable_sftp_username'],
						'password'           => $server_config['pressable_sftp_password'],
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

		$output->writeln( "\n<info>Deploy HQ is now set up and ready to start receiving and deploying commits to the new staging site https://$site_name.mystagingwebsite.com.</info>\n" );

		$output->writeln( '' );

		$site_name_for_slack = $input->getOption( 'site-id' );

		$api_helper->log_to_slack(
			sprintf(
				'INFO: Pressable / DeployHQ: %s run for %s',
				'create-development-site',
				$site_name_for_slack
			)
		);

		exit;
	}

	// Convert a text string to something ready to be used as a unique, machine-friendly identifier
	protected function _slugify( $_text ) {

		$_slug = strtolower( $_text ); // convert to lowercase
		$_slug = preg_replace( '/\s+/', '-', $_slug ); // convert all contiguous whitespace to a single hyphen
		$_slug = preg_replace( '/[^a-z0-9\-]/', '', $_slug ); // Lowercase alphanumeric characters and dashes are allowed.
		$_slug = preg_replace( '/-+/', '-', $_slug ); // convert multiple contiguous hyphens to a single hyphen

		return $_slug;
	}
}
