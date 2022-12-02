<?php

/*
1. Get list of sites with WooCommerce installed
2. Ping the endpoint for each site and save the output
3. Loop through the output and calculate the overall stats
4. Output the cumulative stats and top 15 grossing sites?
*/

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;

class Get_WooCommerce_Stats extends Command {
    protected static $defaultName = 'get-woocommerce-stats';

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
        ->setDescription( "Get WooCommerce order stats across all Team51 sites." )
        ->setHelp( "This command will output the top grossing WooCommerce sites we support with dollar amounts and an over amount summed across all of our sites.\nExample usage:\nget-woocommerce-stats\n" );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $plugin_slug = 'woocommerce';

        $api_helper = new API_Helper;

        $output->writeln( '<info>Fetching sites with WooCommerce installed...<info>' );

        $sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_list = array();
		foreach ( $sites->blogs->blogs as $site ) {
			$site_list[] = array( $site->userblog_id, $site->domain );
		}
		$site_count = count( $site_list );

		$output->writeln( "<info>{$site_count} sites found.<info>" );
		$output->writeln( "<info>Checking each site for the plugin slug: woocommerce<info>" );

		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$sites_with_plugin = array();
		$sites_not_checked = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$plugin_list = $this->get_list_of_plugins( $site[0] );
			if ( ! is_null( $plugin_list ) ) {
				if ( ! is_null( $plugin_list->data ) ) {
					$plugins_array = json_decode( json_encode( $plugin_list->data ), true );
					foreach ( $plugins_array as $plugin_path => $plugin ) {
						$folder_name = strstr( $plugin_path, '/', true );
                        $file_name   = str_replace( array( '/', '.php' ), '', strrchr( $plugin_path, '/' ) );
                        if ( $plugin_slug === $plugin['TextDomain'] || $plugin_slug === $folder_name || $plugin_slug === $file_name ) {
                            $sites_with_plugin[] = array( $site[1], $plugin['Name'], ( $plugin['active'] ? 'Active' : 'Inactive' ), $plugin['Version'] );
                        }
                    }
				}
			} else {
				$sites_not_checked[] = array( $site[1], $site[0] );
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );
        $output->writeln( var_dump($sites_with_plugin) );
        
    }

    private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		if ( ! empty( $plugin_list->error ) ) {
			$plugin_list = null;
		}
		return $plugin_list;
	}

}