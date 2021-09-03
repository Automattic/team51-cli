<?php
namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Remove_User_From_Sites extends Command {
	protected static $defaultName = 'remove-user-from-sites';

	protected function configure() {
		$this
		->setDescription( 'Removes a user from Pressable and WP Admin' )
		->setHelp( 'Use this command to remove a user from Pressable and WordPress sites.' )
		->addArgument( 'email', InputArgument::REQUIRED, "The email of the user to be removed." );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		$email = $input->getArgument( 'email' );
		$email = explode( "=", $email )[1];

		$output->writeln( '<info>Fetching list of sites...<info>' );

		$result = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites/?fields=ID,URL', array() );

		if ( ! empty( $result->error ) ) {
			$output->writeln( '<error>Failed. ' . $result->message . '<error>' );
			exit;
		}

		$output->writeln( "<info>Searching for '$email' across " . count($result->sites) . " the sites...<info>" );

		$sites_user = array();
		foreach( $result->sites as $key => $site ) {

			$users_result = $api_helper->call_wpcom_api( "rest/v1.1/sites/$site->ID/users/?search=$email&search_columns=user_email", array() );

			if ( isset( $users_result->error ) ) {
				continue;
			}

			if ( !isset($users_result->found) ) { var_dump($users_result); } // TODO: Remove

			if ( $users_result->found > 0 ) {
				foreach ( $users_result->users as $u ) {
					array_push(
						$sites_user,
						array(
							'url'   => $site->URL,
							'site'  => $site->ID,
							'login' => $u->login,
						)
					);
				}
			}
		}

		var_dump($sites_user);

		// for url in $(head -n500 urls.txt); do
		// content="$(curl -sH 'Accept: application/json' -H 'Authorization: Bearer [REDACTED]' https://public-api.wordpress.com/rest/v1.1/sites/$url/users)"
		// echo "$url // $content" >> siteusers.txt
		// done

		// $result = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site->ID . '/rest-api/', $data );

		

		$output->writeln( '<info>All done!<info>' );
	}
}

