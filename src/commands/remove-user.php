<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;

class Remove_User extends Command {
	protected static $defaultName = 'remove-user';
	private $api_helper;
	private $output;

	protected function configure() {
		$this
		->setDescription( 'Removes a Pressable collaborator and WordPress user based on email.' )
		->setHelp( 'This command allows you to bulk-delete from all sites a Pressable collaborator and WordPress user via CLI.' )
		->addOption( 'email', null, InputOption::VALUE_REQUIRED, "The email of the user you'd like to remove access from sites." )
		->addOption( 'list', null, InputOption::VALUE_NONE, 'List the sites where this email is found.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		$email = $input->getOption( 'email' );

		if ( empty( $email ) ) {
			$email = trim( readline( 'Please provide the email of the user you want to remove: ' ) );
			if ( empty( $email ) ) {
				$output->writeln( '<error>Missing collaborator email (--email=user@domain.com).</error>' );
				exit;
			}
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
			if ( $collaborator->email === $email ) {
				$collaborator_data[] = $collaborator;
			}
		}

		if ( empty( $collaborator_data ) ) {
			$output->writeln( "<info>No collaborators found in Pressable with the email '$email'.</info>" );
		} else {
			$site_info = new Table( $output );
			$site_info->setStyle( 'box-double' );
			$site_info->setHeaders( array( 'Default Pressable URL', 'Site ID' ) );

			$collaborator_sites = array();

			$output->writeln( '' );
			$output->writeln( "<info>$email is a collaborator on the following Pressable sites:</info>" );
			foreach ( $collaborator_data as $collaborator ) {
				$collaborator_sites[] = array( $collaborator->siteName . '.mystagingwebsite.com', $collaborator->siteId );
			}

			$site_info->setRows( $collaborator_sites );
			$site_info->render();
		}

		// Get users from wordpress.com
		$wpcom_collaborator_data = $this->get_wpcom_users( $email );

		if ( empty( $wpcom_collaborator_data ) ) {
			$output->writeln( "<info>No collaborators found in WordPress.com with the email '$email'.</info>" );
		} else {
			$site_info = new Table( $output );
			$site_info->setStyle( 'box-double' );
			$site_info->setHeaders( array( 'WP URL', 'Site ID', 'WP User ID' ) );
			$wpcom_collaborator_sites = array();

			$output->writeln( '' );
			$output->writeln( "<info>$email is a user on the following WordPress sites:</info>" );
			foreach ( $wpcom_collaborator_data as $collaborator ) {
				$wpcom_collaborator_sites[] = array( $collaborator->siteName, $collaborator->siteId, $collaborator->userId );
			}
			$site_info->setRows( $wpcom_collaborator_sites );
			$site_info->render();
		}

		// Bail here unless the user has asked to remove the collaborator.
		if ( $input->getOption( 'list' ) ) {
			exit;
		}

		// Remove?
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$confirm_remove = trim( readline( 'Are you sure you want to remove this user from WordPress.com and Pressable? (y/n) ' ) );
			if ( 'y' !== $confirm_remove ) {
				exit;
			}
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

		// TODO: Remove user from Github too?

		$output->writeln( '<info>All done!<info>' );
	}

	/**
	 * Given an email, return the list of sites w
	 */
	private function get_wpcom_users( $email ) {
		$wp_bearer_token = WPCOM_API_ACCOUNT_TOKEN;

		$this->output->writeln( '<comment>Fetching list of WordPress.com & Jetpack sites...</comment>' );

		$all_sites = $this->api_helper->call_wpcom_api( 'rest/v1.1/me/sites/?fields=ID,URL', array() );

		if ( ! empty( $all_sites->error ) ) {
			$this->output->writeln( '<error>Failed. ' . $all_sites->message . '<error>' );
			exit;
		}

		$this->output->writeln( "<comment>Searching for '$email' across " . count( $all_sites->sites ) . ' WordPress.com & Jetpack sites...</comment>' );

		$site_users_endpoints = array_map(
			function( $site ) use ( $email ) {
				return WPCOM_API_ENDPOINT . "rest/v1.1/sites/$site->ID/users/?search=$email&search_columns=user_email&fields=ID,email,site_ID,URL";
			},
			$all_sites->sites
		);

		// concurrent call for all endpoints.
		$sites_users = $this->api_helper->call_wpcom_api_concurrent( $site_users_endpoints );

		// clean up data by removing entries were user was not found.
		$sites_users = array_filter(
			$sites_users,
			function( $user ) {
				return ( isset( $user ) && ! isset( $user->error ) && $user->found > 0 );
			}
		);

		$data = array();
		foreach ( $all_sites->sites as $site ) {
			foreach ( $site_users_endpoints as $endpoint ) {
				if ( str_contains( $endpoint, $site->ID ) && isset( $sites_users[ $endpoint ] ) ) {
					$data[] = (object) array(
						'userId'   => $sites_users[ $endpoint ]->users[0]->ID,
						'email'    => $sites_users[ $endpoint ]->users[0]->email,
						'siteId'   => $site->ID,
						'siteName' => $site->URL,
					);
				}
			}
		}

		return $data;
	}
}
