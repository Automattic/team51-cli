<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use function Amp\ParallelFunctions\parallelMap;
use function Amp\Promise\wait;

class Manage_Collaborators extends Command {
	protected static $defaultName = 'manage-collaborators';
	private $api_helper;
	private $output;

	protected function configure() {
		$this
		->setDescription( 'Manage Pressable and WordPress collaborators.' )
		->setHelp( 'This command allows you to bulk-manage Pressable and WordPress collaborators via CLI.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, "The email of the collaborator you'd like to run operations on." )
		->addOption( 'remove', null, InputOption::VALUE_NONE, 'Remove the collaborator from all sites.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		//$api_helper = new API_Helper();
		$this->api_helper = new API_Helper();
		$this->output = $output;

		$collaborator_email = $input->getOption( 'email' );

		if ( empty( $collaborator_email ) ) {
			$output->writeln( '<error>Missing collaborator email (--email=user@domain.com).</error>' );
			exit;
		}

		$output->writeln( '<comment>Getting collaborator data from Pressable.</comment>' );

		// Each site will have a separate collborator instance/ID for the same user/email.
		$collaborator_data = array();

		$collaborators = $this->api_helper->call_pressable_api(
			'collaborators',
			'GET',
			array()
		);

		// TODO: This code is duplicated below for the site clone. Should be a function.
		if ( empty( $collaborators->data ) ) {
			$output->writeln( '<error>Something has gone wrong while looking up the Pressable collaborators site.</error>' );
			exit;
		}

		foreach ( $collaborators->data as $collaborator ) {
			if ( $collaborator->email === $collaborator_email ) {
				$collaborator_data[] = $collaborator;
			}
		}

		if ( empty( $collaborator_data ) ) {
			$output->writeln( "<info>No collaborators found in Pressable with the email '$collaborator_email'.</info>" );
		} else {
			$site_info = new Table( $output );
			$site_info->setStyle( 'box-double' );
			$site_info->setHeaders( array( 'Default Pressable URL', 'Site ID' ) );
	
			$collaborator_sites = array();
	
			$output->writeln( '' );
			$output->writeln( "<info>User $collaborator_email is a collaborator on the following Pressable sites:</info>" );
			foreach ( $collaborator_data as $collaborator ) {
				$collaborator_sites[] = array( $collaborator->siteName . '.mystagingwebsite.com', $collaborator->siteId );
			}
	
			$site_info->setRows( $collaborator_sites );
			$site_info->render();
		}


		// Get users from wordpress.com
		$wpcom_collaborator_data = $this->get_wpcom_users( $collaborator_email );

		if ( empty( $wpcom_collaborator_data ) ) {
			$output->writeln( "<info>No collaborators found in WordPress.com with the email '$collaborator_email'.</info>" );
		} else {
			$site_info = new Table( $output );
			$site_info->setStyle( 'box-double' );
			$site_info->setHeaders( array( 'WP URL', 'Site ID', 'WP User ID' ) );
			$wpcom_collaborator_sites = array();
	
			$output->writeln( '' );
			$output->writeln( "<info>User $collaborator_email is a collaborator on the following WordPress sites:</info>" );
			foreach ( $wpcom_collaborator_data as $collaborator ) {
				$wpcom_collaborator_sites[] = array( $collaborator->siteName, $collaborator->siteId, $collaborator->userId );
			}
			$site_info->setRows( $wpcom_collaborator_sites );
			$site_info->render();
		}


		// Bail here unless the user has asked to remove the collaborator.
		if ( empty( $input->getOption( 'remove' ) ) ) {
			exit;
		}

		// Remove?
		$confirm_remove = trim(readline("Are you sure you want to remove this user from WordPress.com and Pressable? (y/n) "));
		if ( $confirm_remove !== 'y' ) {
			exit;
		}

		// Remove from Pressable
		foreach ( $collaborator_data as $collaborator ) {
			$removed_collaborator = $this->api_helper->call_pressable_api( "/sites/{$collaborator->siteId}/collaborators/{$collaborator->id}", 'DELETE', array() );
			if ( 'Success' === $removed_collaborator->message ) {
				$output->writeln( "<info>✓ Removed {$collaborator->email} from {$collaborator->siteName}. (Pressable site)</info>" );
			} else {
				$output->writeln( "<comment>❌ Failed to remove from {$collaborator->email} from Pressable site '{$collaborator->siteName}.</comment>" );
			}
		}

		// Remove from WordPress
		foreach ( $wpcom_collaborator_data as $collaborator ) {
			$removed_collaborator = $this->api_helper->call_wpcom_api( "rest/v1.1/sites/{$collaborator->siteId}/users/{$collaborator->userId}/delete", array(), 'POST' );

			if ( isset( $removed_collaborator->success ) && $removed_collaborator->success ) {
				$output->writeln( "<info>✓ Removed {$collaborator->email} from {$collaborator->siteName} (WordPress site).</info>" );
			} else {
				$output->writeln( "<comment>❌ Failed to remove {$collaborator->email} from WordPress site '{$collaborator->siteName}.</comment>" );
			}
		}

		$output->writeln( '<info>All done!<info>' );
	}

	private function get_wpcom_users( $email ) {
		$wp_bearer_token = WPCOM_API_ACCOUNT_TOKEN;

		$this->output->writeln( '<comment>Fetching list of wpcom sites...</comment>' );

		$result = $this->api_helper->call_wpcom_api( 'rest/v1.1/me/sites/?fields=ID,URL', array() );

		if ( ! empty( $result->error ) ) {
			$this->output->writeln( '<error>Failed. ' . $result->message . '<error>' );
			exit;
		}

		$this->output->writeln( "<comment>Searching for '$email' across " . count( $result->sites ) . ' wpcom sites...</comment>' );

		// Prepare array with /sites/[siteID]/users/
		$users_search_urls = array();
		foreach ( $result->sites as $k => $site ) {
			$users_search_urls[] = array(
				'site_id'     => $site->ID,
				'site_url'    => $site->URL,
				'wp_endpoint' => WPCOM_API_ENDPOINT . "rest/v1.1/sites/$site->ID/users/?search=$email&search_columns=user_email&fields=ID,email,site_ID,URL",
			);
		}

		$logins_to_be_banned = wait(
			parallelMap(
				$users_search_urls,
				function ( $user_search_url ) use ( $wp_bearer_token, $site ) {
					// Need to pass the Bearer and full URL as arguments
					// because async workers won't have access to globally defined constants
					$users_per_site = $this->api_helper->call_generic_api( $user_search_url['wp_endpoint'], array(), 'GET', $wp_bearer_token );

					if ( ! isset( $users_per_site ) || isset( $users_per_site->error ) ) {
						return array();
					}

					$logins = array();
					if ( $users_per_site->found > 0 ) {
						foreach ( $users_per_site->users as $u ) {
							$logins[] = (object) array(
								'userId'   => $u->ID,
								'email'    => $u->email,
								'siteId'   => $user_search_url['site_id'],
								'siteName' => $user_search_url['site_url'],
							);
						}
					}
					return $logins;
				}
			)
		);

		// flatten with array_merge.
		return array_merge( ...$logins_to_be_banned );
    }
}
