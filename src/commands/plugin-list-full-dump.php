<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Plugin_Summary extends Command {
	use \Team51\Helper\Autocomplete;

	protected static $defaultName = 'plugin-list-full-dump';

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
			->setDescription( 'Dumps a CSV of all plugins on on all t51 sites, including activation status' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {

		$api_helper = new API_Helper();

		$output->writeln( '<info>Fetching production sites connected to a8cteam51...<info>' );

		// Fetching sites connected to a8cteam51
		$sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites->blogs->blogs ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		// Filter out non-production sites
		$deny_list = array(
			'mystagingwebsite.com',
			'go-vip.co',
			'wpcomstaging.com',
			'wpengine.com',
			'jurassic.ninja',
			'woocommerce.com',
			'atomicsites.blog',
		);

		foreach ( $sites->blogs->blogs as $site ) {
			$matches = false;
			foreach ( $deny_list as $deny ) {
				if ( strpos( $site->siteurl, $deny ) !== false ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				$site_list[] = array(
					'blog_id'  => $site->userblog_id,
					'site_url' => $site->siteurl,
				);
			}
		}

		$site_count = count( $site_list );

		if ( empty( $site_count ) ) {
			$output->writeln( '<error>No production sites found.<error>' );
			exit;
		}

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
						$site_plugin->version,
					);
				}
			}
		}

		// make a csv out of the $plugins_on_t51_sites array
		$output->writeln( '<info>Making the CSV...<info>' );
		$timestamp = date( 'Y-m-d-H-i-s' );
		$fp        = fopen( 'plugins-on-t51-sites-' . $timestamp . '.csv', 'w' );
		fputcsv( $fp, array( 'Site URL', 'Blog ID', 'Plugin Slug', 'Active', 'Version' ) );
		foreach ( $plugins_on_t51_sites as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );

		$output->writeln( '<info>Done, CSV saved to your current working directory: plugins-on-t51-sites-' . $timestamp . '.csv<info>' );

		return Command::SUCCESS;
	}

	// Helper functions, getting list of plugins and getting woocommerce stats
	private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		return $plugin_list;
	}
}
