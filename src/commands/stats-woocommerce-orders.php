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

class Get_WooCommerce_Stats extends Command {

	protected static $defaultName = 'stats:woocommerce-orders';

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
			->setDescription( 'Get WooCommerce order stats across all Team51 sites.' )
			->setHelp(
				"This command will output the top grossing WooCommerce sites we support with dollar amounts and an over amount summed across all of our sites.\n
				Example usage:\n
				stats:woocommerce-orders --unit=year --date=2022\n
				stats:woocommerce-orders --unit=week --date=2022-W12\n
				stats:woocommerce-orders --unit=month --date=2021-10\n
				stats:woocommerce-orders --unit=day --date=2022-02-27"
			)
			->addOption(
				'unit',
				null,
				InputOption::VALUE_REQUIRED,
				'Options: day, week, month, year.'
			)
			->addOption(
				'date',
				null,
				InputOption::VALUE_REQUIRED,
				'Options: YYYY-MM-DD, YYYY-W##, YYYY-MM, YYYY.'
			)
			->addOption(
				'check-production-sites',
				null,
				InputOption::VALUE_NONE,
				'Checks production sites instead of the Jetpack Profile for the sites. Takes much longer to run.'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$plugin_slug = 'woocommerce';

		$api_helper = new API_Helper();

		// error if the unir or date options are not set
		if ( empty( $input->getOption( 'unit' ) ) ) {
			$output->writeln( '<error>Time unit is required for fetching stats. (example: --unit=year)</error>' );
			exit;
		}

		if ( empty( $input->getOption( 'date' ) ) ) {
			$output->writeln( '<error>Date is required for fetching stats (example: --date=2021-08)</error>' );
			exit;
		}

		$unit = $input->getOption( 'unit' );
		$date = $input->getOption( 'date' );

		$output->writeln( '<info>Fetching production sites connected to a8cteam51...<info>' );

		// Fetching sites connected to a8cteam51
		$sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		// Filter out non-production sites
		$site_list = array();
		foreach ( $sites->blogs->blogs as $site ) {
			if ( strpos( $site->siteurl, 'mystagingwebsite.com' ) === false && strpos( $site->siteurl, 'go-vip.co' ) === false && strpos( $site->siteurl, 'wpcomstaging.com' ) === false && strpos( $site->siteurl, 'wpengine.com' ) === false && strpos( $site->siteurl, 'jurassic.ninja' ) === false ) {
				$site_list[] = array( $site->userblog_id, $site->siteurl );
			}
		}
		$site_count = count( $site_list );

		$output->writeln( "<info>{$site_count} sites found.<info>" );
		$output->writeln( '<info>Checking each site for WooCommerce...<info>' );

		if ( $input->getOption( 'check-production-sites' ) ) {
			$output->writeln( '<info>Checking production sites for WooCommerce...<info>' );
			$progress_bar = new ProgressBar( $output, $site_count );
			$progress_bar->start();
			// Checking each site for the plugin slug: woocommerce, and only saving the sites that have it active
			foreach ( $site_list as $site ) {
				$progress_bar->advance();
				$plugin_list = $this->get_list_of_plugins( $site[0] );
				if ( ! is_null( $plugin_list ) ) {
					if ( ! is_null( $plugin_list->data ) ) {
						$plugins_array = json_decode( json_encode( $plugin_list->data ), true );
						foreach ( $plugins_array as $plugin_path => $plugin ) {
							$folder_name = strstr( $plugin_path, '/', true );
							$file_name   = str_replace( array( '/', '.php' ), '', strrchr( $plugin_path, '/' ) );
							if ( ( $plugin_slug === $plugin['TextDomain'] || $plugin_slug === $folder_name || $plugin_slug === $file_name ) && $plugin['active'] == 'Active' ) {
								$sites_with_woocommerce[] = array( $site[1], $site[0] );
							}
						}
					}
				}
			}
			$progress_bar->finish();
			$output->writeln( '<info>  Yay!</info>' );

		} else {
			// Get plugin lists from Jetpack profile data
			$output->writeln( '<info>Checking Jetpack site profiles for WooCommerce...<info>' );
			$jetpack_sites_plugins = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites/plugins/', array() );

			foreach ( $site_list as $site ) {
				//check if the site exists in the jetpack_sites_plugins object
				if ( isset( $jetpack_sites_plugins->sites->{$site[0]} ) ) {
					// loop through the plugins and check for WooCommerce
					foreach ( $jetpack_sites_plugins->sites->{$site[0]} as $site_plugin ) {
						if ( $site_plugin->slug === $plugin_slug && $site_plugin->active === true ) {
							$sites_with_woocommerce[] = array( $site[1], $site[0] );
						}
					}
				}
			}
		}

		$woocommerce_count = count( $sites_with_woocommerce );
		$output->writeln( "<info>{$woocommerce_count} sites have WooCommerce installed and active.<info>" );

		// Get WooCommerce stats for each site
		$output->writeln( '<info>Fetching WooCommerce stats for Team51 production sites...<info>' );
		$progress_bar = new ProgressBar( $output, $woocommerce_count );
		$progress_bar->start();

		$team51_woocommerce_stats = array();
		foreach ( $sites_with_woocommerce as $site ) {
			$progress_bar->advance();
			$stats = $this->get_woocommerce_stats( $site[1], $unit, $date );

			//Checking if stats are not zero. If not, add to array
			if ( isset( $stats->total_gross_sales ) && $stats->total_gross_sales > 0 && $stats->total_orders > 0 ) {
				array_push( $team51_woocommerce_stats, array( $site[0], $site[1], $stats->total_gross_sales, $stats->total_net_sales, $stats->total_orders, $stats->total_products ) );
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		//Sort the array by total gross sales
		usort(
			$team51_woocommerce_stats,
			function ( $a, $b ) {
				return $b[2] - $a[2];
			}
		);

		// Format sales as money
		$formatted_team51_woocommerce_stats = array();
		foreach ( $team51_woocommerce_stats as $site ) {
			array_push( $formatted_team51_woocommerce_stats, array( $site[0], $site[1], '$' . number_format( $site[2], 2 ), '$' . number_format( $site[3], 2 ), $site[4], $site[5] ) );
		}

		//Sum the total gross sales
		$sum_total_gross_sales = array_reduce(
			$team51_woocommerce_stats,
			function ( $carry, $site ) {
				return $carry + $site[2];
			},
			0
		);

		//round the sum
		$sum_total_gross_sales = number_format( $sum_total_gross_sales, 2 );

		$output->writeln( '<info>Site stats for the selected time period: ' . $unit . ' ' . $date . '<info>' );
		// Output the stats in a table
		$stats_table = new Table( $output );
		$stats_table->setStyle( 'box-double' );
		$stats_table->setHeaders( array( 'Site URL', 'Blog ID', 'Total Gross Sales', 'Total Net Sales', 'Total Orders', 'Total Products' ) );
		$stats_table->setRows( $formatted_team51_woocommerce_stats );
		$stats_table->render();

		$output->writeln( '<info>Total Gross Sales across Team51 sites in ' . $unit . ' ' . $date . ': $' . $sum_total_gross_sales . '<info>' );

		$output->writeln( '<info>All done! :)<info>' );
	}

	// Helper functions, getting list of plugins and getting woocommerce stats
	private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		if ( ! empty( $plugin_list->error ) ) {
			$plugin_list = null;
		}
		return $plugin_list;
	}

	private function get_woocommerce_stats( $site_id, $unit, $date ) {
		$woocommerce_stats = $this->api_helper->call_wpcom_api( '/wpcom/v2/sites/' . $site_id . '/stats/orders?unit=' . $unit . '&date=' . $date . '&quantity=1', array() );
		if ( ! empty( $woocommerce_stats->error ) ) {
			$woocommerce_stats = null;
		}
		return $woocommerce_stats;
	}
}
