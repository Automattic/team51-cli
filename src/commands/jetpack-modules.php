<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class Jetpack_Modules extends Command {
	protected static $defaultName = 'jetpack-modules';

	protected function configure() {
		$this
		->setDescription( 'Shows status of Jetpack modules on a specified site.' )
		->setHelp( 'Use this command to show a list of Jetpack modules on a site, and their status. This command requires a Jetpack site connected to the a8cteam51 account.' )
		->addArgument( 'site-domain', InputArgument::REQUIRED, 'The domain of the Jetpack connected site.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$site_domain = $input->getArgument( 'site-domain' );

		$api_helper = new API_Helper;

		$site = $api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_domain, array() );

		if ( empty( $site->ID ) ) {
			$output->writeln( '<error>Failed to fetch site information.<error>' );
			$output->writeln( '<info>Are you sure this site is connected to Jetpack and on the a8cteam51 account?<info>' );
			exit;
		}

		$output->writeln( "<info>Jetpack module status for {$site_domain}<info>" );

		$module_data = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site->ID . '/rest-api/?path=/jetpack/v4/module/all', array() );

		if ( ! empty( $module_data->error ) ) {
			$output->writeln( '<error>Failed. ' . $result->message . '<error>' );
			exit;
		}

		$module_table = new Table( $output );
		$module_table->setStyle( 'box-double' );
		$module_table->setHeaders( array( 'Module', 'Status' ) );

		$module_list = array();
		foreach ( $module_data->data as $module ) {
				$module_list[] = array( $module->module, ( $module->activated ? 'on' : 'off' ) );
		}
		asort( $module_list );

		$module_table->setRows( $module_list );
		$module_table->render();

	}
}
