<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Jetpack_Enable_SSO extends Command {
	use \Team51\Helper\Autocomplete;

	protected static $defaultName = 'jetpack-enable-sso';

	protected function configure() {
		$this
		->setDescription( 'Activates Jetpack SSO module and enables two-factor authentication.' )
		->setHelp( 'Use this command to enable the SSO module and two-factor authentication option in a single step. This command requires a Jetpack site connected to the a8cteam51 account.' )
		->addArgument( 'site-domain', InputArgument::REQUIRED, 'The domain of the Jetpack connected site.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$site_domain = $input->getArgument( 'site-domain' );

		$api_helper = new API_Helper();

		$output->writeln( '<info>Fetching site information...<info>' );

		$site = $api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_domain, array() );

		if ( empty( $site->ID ) ) {
			$output->writeln( '<error>Failed to fetch site information.<error>' );
			$output->writeln( '<info>Are you sure this site is connected to Jetpack and on the a8cteam51 account?<info>' );
			exit;
		}

		$output->writeln( '<info>Asking Jetpack to enable SSO...<info>' );

		$data = array(
			'path' => '/jetpack/v4/settings/',
			'json' => true,
			'body' => json_encode(
				array(
					'sso'                          => true, // activates SSO module.
					'jetpack_sso_require_two_step' => true,
				)
			),
		);

		$result = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site->ID . '/rest-api/', $data, 'POST' );

		if ( ! empty( $result->error ) ) {
			$output->writeln( '<error>Failed. ' . $result->message . '<error>' );
			exit;
		}

		$output->writeln( '<info>All done! :)<info>' );
	}
}
