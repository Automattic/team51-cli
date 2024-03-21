<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class Plugin_List extends Command {
	use \Team51\Helper\Autocomplete;

	protected static $defaultName = 'plugin-list';

	protected function configure() {
		$this
		->setDescription( 'Shows list of plugins on a specified site.' )
		->setHelp( 'Use this command to show a list of installed plugins on a site. This command requires a Jetpack site connected to the a8cteam51 account.' )
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

		$output->writeln( "<info>Plugins installed on {$site_domain}<info>" );

		$plugin_data = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site->ID . '/rest-api/?path=/jetpack/v4/plugins', array() );

		if ( ! empty( $plugin_data->error ) ) {
			$output->writeln( '<error>Failed. ' . $plugin_data->message . '<error>' );
			exit;
		}

		$plugin_table = new Table( $output );
		$plugin_table->setStyle( 'box-double' );
		$plugin_table->setHeaders( array( 'Plugin slug', 'Status', 'Version' ) );

		$plugin_list = array();
		foreach ( $plugin_data->data as $plugin ) {
				$plugin_list[] = array( ( empty( $plugin->TextDomain ) ? $plugin->Name . ' - (No slug)' : $plugin->TextDomain ), ( $plugin->active ? 'Active' : 'Inactive' ), $plugin->Version );
		}
		asort( $plugin_list );

		$plugin_table->setRows( $plugin_list );
		$plugin_table->render();

	}
}
