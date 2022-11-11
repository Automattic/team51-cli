<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Jetpack_Module extends Command {
	protected static $defaultName = 'jetpack-module';

	private array $settings = array(
		'enable'  => true,
		'disable' => false,
	);

	protected function configure() {
		$this
		->setDescription( 'Enable/disable Jetpack modules for a site.' )
		->setHelp( 'Use this command to enable/disable Jetpack modules. This command requires a Jetpack site connected to the a8cteam51 account.' )
		->addArgument( 'site-domain', InputArgument::REQUIRED, 'The domain of the Jetpack connected site.' )
		->addArgument( 'module', InputArgument::REQUIRED, 'The desired Jetpack module.' )
		->addArgument( 'setting', InputArgument::REQUIRED, 'enable/disable' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$site_domain     = $input->getArgument( 'site-domain' );
		$module          = $input->getArgument( 'module' );
		$setting         = $input->getArgument( 'setting' );
		$setting_boolean = $this->get_setting_boolean( $setting );

		if ( null === $setting_boolean ) {
			$output->writeln( '<error>Wrong setting! Accepted values are enable/disable.<error>' );
			exit;
		}

		$api_helper = new API_Helper();

		$output->writeln( '<info>Fetching site information...<info>' );

		$site = $api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_domain, array() );

		if ( empty( $site->ID ) ) {
			$output->writeln( '<error>Failed to fetch site information.<error>' );
			$output->writeln( '<info>Are you sure this site is connected to Jetpack and on the a8cteam51 account?<info>' );
			exit;
		}

		$output->writeln( '<info>Asking Jetpack to set the module setting...<info>' );

		$data = array(
			'path' => '/jetpack/v4/settings/',
			'json' => true,
			'body' => json_encode(
				array(
					$module => $setting_boolean,
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

	/**
	 * @param $setting
	 *
	 * @return bool|null
	 */
	private function get_setting_boolean( $setting ) {
		foreach ( $this->settings as $key => $val ) {
			if ( $setting === $key ) {
				return $val;
			}
		}
		return null;
	}
}
