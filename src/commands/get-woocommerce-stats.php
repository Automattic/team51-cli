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

        $output->writeln( '<info>Fetching production sites connected to a8cteam51...<info>' );

        $sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_list = array();
		foreach ( $sites->blogs->blogs as $site ) {
            if ( strpos( $site->domain, 'mystagingwebsite.com' ) !== false || strpos( $site->domain, 'go-vip.co') == !false ) {
                //skip
            } else {
                $site_list[] = array( $site->userblog_id, $site->domain );
            }
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
                        if ( ($plugin_slug === $plugin['TextDomain'] || $plugin_slug === $folder_name || $plugin_slug === $file_name) && $plugin['active'] == 'Active' ) {
                            $sites_with_woocommerce[] = array( $site[1], $site[0], );
                        }
                    }
				}
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );
        //$output->writeln( var_dump($sites_with_woocommerce) );

        $team51_woocommerce_stats = array();
        foreach ( $sites_with_woocommerce as $site ) {
            $output->writeln( "<info>Fetching stats for {$site[0]}<info>" );
            $stats = $this->get_woocommerce_stats( $site[1] );
            //$output->writeln( var_dump($stats) );
           //LEFT OFF HERE - checking if stats are not zero. If not, add to array
           // if ( $stats->total_gross_sales && )
            $team51_woocommerce_stats[] = array( $site[0], $site[1], $stats->total_gross_sales, $stats->total_net_sales, $stats->total_orders, $stats->total_products );
        }

        $stats_table = new Table( $output );
		$stats_table->setStyle( 'box-double' );
		$stats_table->setHeaders( array( 'Site URL', 'Blog ID', 'Total Gross Sales', 'Total Orders', 'Total Products' ) );
		$stats_table->setRows( $team51_woocommerce_stats );
		$stats_table->render();
        
        $output->writeln( '<info>All done! :)<info>' );
    }

    private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		if ( ! empty( $plugin_list->error ) ) {
			$plugin_list = null;
		}
		return $plugin_list;
	}

    private function get_woocommerce_stats( $site_id ) {
        $woocommerce_stats = $this->api_helper->call_wpcom_api( '/wpcom/v2/sites/' . $site_id . '/stats/orders?unit=year&date=2022&quantity=1', array() );
        if ( ! empty( $woocommerce_stats->error ) ) {
            $woocommerce_stats = null;
        }
        return $woocommerce_stats;
    }

}