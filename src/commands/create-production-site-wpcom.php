<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Team51\Helper\DeployHQ_API_Helper;
use Team51\Helper\GitHub_API_Helper;
use Team51\Helper\WPCOM_API_Helper;
use function Team51\Helper\get_wpcom_site;

class Create_Production_Site_WPCOM extends Command {
	protected static $defaultName = 'create-production-site-wpcom';

	const DEPLOYHQ_ZONE_EUROPE  = 3; // UK
	const DEPLOYHQ_ZONE_US_EAST = 6;
	const DEPLOYHQ_ZONE_US_WEST = 9;

	protected function configure() {
		$this
		->setDescription( 'Setup deployments fo a new production site (on WPCOM).' )
		->setHelp( 'This command allows you to create a new production site. The site needs to be manually created in our concierge account with a Business or higher plan and then this command can be used to setup deployments.' )
		->addOption( 'blog-id', null, InputOption::VALUE_REQUIRED, 'This is the Blog ID from the site in WPCOM.' )
		->addOption( 'connect-to-repo', null, InputOption::VALUE_REQUIRED, "The repository you'd like to have automatically configured in DeployHQ to work with the new site. This accepts the repository slug.\nOnly GitHub repositories are supported and they must be in the a8cteam51 organization, otherwise the script won't have access." )
		->addOption( 'zone-id', null, InputOption::VALUE_OPTIONAL, 'The zone that will be used while creating the project on DeployHQ. By default the DEPLOYHQ_DEFAULT_ZONE config param is used.' )
		->addOption( 'template-id', null, InputOption::VALUE_OPTIONAL, 'The template that will be used while creating the project on DeployHQ. By default the DEPLOYHQ_DEFAULT_PROJECT_TEMPLATE config param is used.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		if ( empty( $input->getOption( 'blog-id' ) ) ) {
			$output->writeln( '<error>Blog ID is required for production site creation.</error>' );
			exit;
		}

		if ( empty( $input->getOption( 'connect-to-repo' ) ) ) {
			$output->writeln( '<error>GitHub repository name is required for production site creation.</error>' );
			exit;
		}

		// Get site information
		$site = get_wpcom_site( $input->getOption( 'blog-id' ) );

		if ( empty( $site ) ) {
			$output->writeln( '<error>There was an issue when pulling site information.</error>' );
			exit;
		}

		$site_name_data = parse_url( $site->URL );
		$site_name_data = explode( '.', $site_name_data['host'] );
		$project_name   = $site_name_data[0];

		$github_repo = $input->getOption( 'connect-to-repo' );

		$progress_bar = new ProgressBar( $output );

		if ( false === $site->is_wpcom_atomic ) {
			$output->writeln( '<comment>Checking if site is eligible for Atomic</comment>' );
			$eligible_for_atomic_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/automated-transfers/eligibility' );

			if ( true !== $eligible_for_atomic_response->is_eligible ) {
				$output->writeln( '<error>Site is not eligible for Atomic! ' . implode( ' | ', $eligible_for_atomic_response->errors ) . '</error>' );
				exit;
			}

			$output->writeln( '<info>Site is eligible for Atomic</info>' );
			$output->writeln( '<comment>Starting transfer to Atomic</comment>' );

			$progress_bar->start();
			$progress_bar->advance();

			// This uses a hack to remove the HTML that is returned by the API. The API returns something like:
			// Original JSON:
			// {"atomic_transfer_id":799050,"blog_id":220708266,"status":"active","created_at":"2023-06-29 09:47:46","is_stuck":false,"is_stuck_reset":false,"in_lossless_revert":false,"transfer_id":799050,"success":true}<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
			// <html xmlns="http://www.w3.org/1999/xhtml">"
			// which causes an issue when decoding json, so we remove the HTML and then decode the JSON with fix_malformed_response = true
			$transfer_response = WPCOM_API_Helper::call_api( 'sites/' . $site->ID . '/automated-transfers/initiate', 'POST', array(), false, true );

			if ( ! isset( $transfer_response->transfer_id ) ) {
				$output->writeln( '<error>There was an issue when initiating the transfer to Atomic.</error>' );
				exit;
			}

			$transfer_id = $transfer_response->transfer_id;

			do {
				// Wait 10 seconds before checking the status, otherwise we get banned
				sleep( 10 );
				$transfer_status_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/automated-transfers/status/' . $transfer_id );
				$progress_bar->advance();

			} while ( 'complete' !== $transfer_status_response->status );

			$progress_bar->finish();

		}

		$output->writeln( '<info>Site is Atomic!</info>' );

		// Check if site has the SFTP/SSH user
		$ssh_users_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/hosting/ssh-users', array(), 'GET', true );

		if ( empty( $ssh_users_response->users ) ) {
			$output->writeln( '<comment>Creating the SFTP/SSH user.</comment>' );

			$create_ssh_user_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/hosting/ssh-user', array(), 'POST', true );

			if ( ! isset( $transfer_response->transfer_id ) ) {
				$output->writeln( '<error>There was an issue when creating the SFTP/SSH user.</error>' );
				exit;
			}

			$ssh_user['username'] = $create_ssh_user_response->username;

		} else {
			$ssh_user['username'] = $ssh_users_response->users[0];
		}

		$output->writeln( '<comment>Enabling SSH.</comment>' );
		$enable_ssh_response = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/hosting/ssh-access', array( 'setting' => 'ssh' ), 'POST', true );

		if ( 'ssh' !== $enable_ssh_response->setting ) {
			$output->writeln( '<error>Failed to enable SSH.</error>' );
			exit;
		}

		$enable_ssh_key = WPCOM_API_Helper::call_site_wpcom_api( $site->ID, '/hosting/ssh-keys', array( 'name' => 'default' ), 'POST', true );

		if ( ! $enable_ssh_key ) {
			$output->writeln( '<comment>There was an issue with attaching the SSH Key or it\'s already added.</comment>' );
		}

		$output->writeln( '<info>SSH Key attached.</info>' );
		$output->writeln( '<info>SSH enabled.</info>' );

		// Create DeployHQ Project
		// Assign default datacenter zone for DeployHQ.
		$deployhq_zone_id = self::DEPLOYHQ_ZONE_US_EAST;

		if ( ! empty( $input->getOption( 'zone-id' ) ) ) {
			$z_id = $input->getOption( 'zone-id' );
			$z_id = strtolower( $z_id );
			$z_id = str_replace( array( ' ', '-' ), '', $z_id );

			if ( \in_array( $z_id, array( 'us', 'uscentral' ), true ) ) {
				$deployhq_zone_id = self::DEPLOYHQ_ZONE_US_EAST;
			}

			if ( \in_array( $z_id, array( 'eu', 'eur', 'europe' ), true ) ) {
				$deployhq_zone_id = self::DEPLOYHQ_ZONE_EUROPE;
			}

			if ( \in_array( $z_id, array( 'uswest', 'west' ), true ) ) {
				$deployhq_zone_id = self::DEPLOYHQ_ZONE_US_WEST;
			}
			if ( \in_array( $z_id, array( 'useast', 'east' ), true ) ) {
				$deployhq_zone_id = self::DEPLOYHQ_ZONE_US_EAST;
			}
		}

		// Assign default DeployHQ Project Template
		$deployhq_template_id = DEPLOYHQ_DEFAULT_PROJECT_TEMPLATE;
		if ( ! empty( $input->getOption( 'template-id' ) ) ) {
			$deployhq_template_id = $input->getOption( 'template-id' );
		}

		$server_config = array(
			'name'          => 'Production',
			'environment'   => 'production',
			'branch'        => 'trunk',
			'sftp_username' => $ssh_user['username'],
			'server_path'   => 'wp-content',
		);

		$output->writeln( '<comment>Creating new project in DeployHQ</comment>' );
		$project_info = DeployHQ_API_Helper::call_api(
			'projects',
			'POST',
			array(
				'name'        => $project_name,
				'zone_id'     => $deployhq_zone_id,
				'template_id' => $deployhq_template_id,
			)
		);

		if ( empty( $project_info ) || empty( $project_info->permalink ) ) {
			$output->writeln( '<error>Failed to create new project in DeployHQ. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Created new project in DeployHQ.</info>\n" );
		}

		$output->writeln( '<comment>Adding private key to DeployHQ project.</comment>' );

		$project_info = DeployHQ_API_Helper::call_api(
			'projects/' . $project_info->permalink,
			'PUT',
			array(
				'project' => array(
					'custom_private_key' => DEPLOYHQ_PRIVATE_KEY,
				),
			)
		);

		if ( empty( $project_info ) || empty( $project_info->public_key ) ) {
			$output->writeln( '<error>Failed to add private key to DeployHQ project. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Successfully added private key to DeployHQ project.</info>\n" );
		}

		$repository_url = "git@github.com:a8cteam51/$github_repo.git";

		$output->writeln( '<comment>Connecting DeployHQ project to GitHub repository.</comment>' );
		$deploy_hq_add_repository_request = DeployHQ_API_Helper::call_api(
			"projects/{$project_info->permalink}/repository",
			'POST',
			array(
				'repository' => array(
					'scm_type' => 'git',
					'url'      => $repository_url,
					'branch'   => 'trunk',
					'username' => null,
					'port'     => null,
				),
			)
		);

		if ( empty( $deploy_hq_add_repository_request ) || empty( $deploy_hq_add_repository_request->url ) ) {
			$output->writeln( '<error>Failed to add GitHub repository to DeployHQ. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Successfully added and configured GitHub repository in DeployHQ</info>\n" );
		}

		$output->writeln( "<comment>Creating new DeployHQ {$server_config['environment']} server for project $project_name.</comment>" );

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

		$github_api_query = 'repos/' . GITHUB_API_OWNER . '/' . $github_repo . '/hooks';

		$output->writeln( "<comment>Adding DeployHQ webhook URL to GitHub repository's list of hooks.</comment>" );
		$github_webhook_url_request = GitHub_API_Helper::call_api(
			$github_api_query,
			'POST',
			array(
				'name'   => 'web',
				'events' => array( 'push' ),
				'active' => true,
				'config' => array(
					'url'          => $project_info->auto_deploy_url,
					'content_type' => 'form',
					'insecure_ssl' => 0,
				),
			)
		);

		if ( empty( $github_webhook_url_request ) || empty( $github_webhook_url_request->id ) ) {
			$output->writeln( '<error>Failed to add DeployHQ webhook URL to GitHub repository. Aborting!</error>' );
			exit;
		} else {
			$output->writeln( "<info>Successfully added DeployHQ webhook URL to GitHub repository.</info>\n" );
		}

		$output->writeln( "\n<info>Deploy HQ is now set up and ready to start receiving and deploying commits!</info>\n" );

		// todo remove api helper
		//      ( new API_Helper() )->log_to_slack((
		//          sprintf(
		//              'INFO: WPCOM / DeployHQ: %s run for %s',
		//              'create-production-site',
		//              $project_name
		//          )
		//      ));

		exit;
	}
}
