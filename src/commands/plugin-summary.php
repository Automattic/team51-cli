<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;

class Plugin_Summary extends Command {

	protected static $defaultName = 'plugin-summary';

	/**
	 * @var Api_Helper|null API Helper instance.
	 */
	protected $api_helper = null;

	public function __construct() {
		parent::__construct();

		$this->api_helper = new API_Helper();
	}

	protected function configure() {
		$this
			->setDescription( 'Dump a CSV of all plugins on t51 sites' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		$api_helper = new API_Helper();

		$output->writeln( '<info>Fetching production sites connected to a8cteam51...<info>' );

		// Fetching sites connected to a8cteam51
		$sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites->blogs->blogs ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		// Filter out non-production sites
		$site_list = array();
		foreach ( $sites->blogs->blogs as $site ) {
			if ( strpos( $site->siteurl, 'mystagingwebsite.com' ) === false && strpos( $site->siteurl, 'go-vip.co' ) === false && strpos( $site->siteurl, 'wpcomstaging.com' ) === false && strpos( $site->siteurl, 'wpengine.com' ) === false && strpos( $site->siteurl, 'jurassic.ninja' ) === false ) {
				$site_list[] = array(
					'blog_id'  => $site->userblog_id,
					'site_url' => $site->siteurl,
				);
			}
		}
		$site_count = count( $site_list );

		$output->writeln( "<info>{$site_count} sites found.<info>" );

		// Get plugin lists from Jetpack profile data
		$output->writeln( '<info>Getting plugins from each site...<info>' );
		$jetpack_sites_plugins = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites/plugins/', array() );

		foreach ( $site_list as $site ) {
			if ( ! empty( $jetpack_sites_plugins->sites->{$site['blog_id']} ) ) {
				foreach ( $jetpack_sites_plugins->sites->{$site['blog_id']} as $site_plugin ) {
					$plugins_on_t51_sites[] = array(
						$site['site_url'],
                        $site['blog_id'],
						$site_plugin->slug,
                        $site_plugin->active,
					);
				}
			}
		}

		// make a csv out of the $plugins_on_t51_sites array
		$output->writeln( '<info>Making the CSV...<info>' );

		$fp = fopen( 'plugins-on-t51-sites.csv', 'w' );
        fputcsv( $fp, array( 'Site URL', 'Blog ID', 'Plugin Slug', 'Active' ) );
		foreach ( $plugins_on_t51_sites as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );

		$output->writeln( '<info>Done, CSV saved to your current working directory: plugins-on-t51-sites.csv<info>' );

	}

	// Helper functions, getting list of plugins and getting woocommerce stats
	private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		if ( ! empty( $plugin_list->error ) ) {
			$plugin_list = null;
		}
		return $plugin_list;
	}
}
