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

class Get_Site_Stats extends Command {

	protected static $defaultName = 'get-site-stats';

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
			->setDescription( 'Get Site Stats across all Team51 sites.' )
			->setHelp(
				"This command will output a summary of stats across all of our sites.\n
				Example usage:\n
				get-site-stats --period=year --date=2022\n
				get-site-stats --period=week --date=2022-W12\n
				get-site-stats --period=month --date=2021-10\n
				get-site-stats --period=day --date=2022-02-27"
			)
			->addOption(
				'period',
				null,
				InputOption::VALUE_REQUIRED,
				'Options: day, week, month, year.'
			)
			->addOption(
				'date',
				null,
				InputOption::VALUE_REQUIRED,
				'Options: YYYY-MM-DD, YYYY-W##, YYYY-MM, YYYY.'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		// error if the unir or date options are not set
		if ( empty( $input->getOption( 'period' ) ) ) {
			$output->writeln( '<error>Time unit is required for fetching stats. (example: --unit=year)</error>' );
			exit;
		}

		if ( empty( $input->getOption( 'date' ) ) ) {
			$output->writeln( '<error>Date is required for fetching stats (example: --date=2022-12-09)</error>' );
			exit;
		}

		$period = $input->getOption( 'period' );
		$date   = $input->getOption( 'date' );

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

		// Get site stats for each site
		$output->writeln( '<info>Fetching site stats for Team51 production sites...<info>' );
		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$team51_site_stats = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$stats = $this->get_site_stats( $site[0], $period, $date );
			//var_dump( $stats );
			//Checking if stats are not null. If not, add to array
			if ( isset( $stats->views ) ) {
				array_push( $team51_site_stats, array( $site[0], $site[1], $stats->views, $stats->visitors ) );
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		//Sort the array by total gross sales
		usort(
			$team51_site_stats,
			function ( $a, $b ) {
				return $b[2] - $a[2];
			}
		);

		//Sum the totals
		$sum_total_views = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site[2];
			},
			0
		);

		$sum_total_visitors = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site[3];
			},
			0
		);

		$output->writeln( '<info>Site stats for the selected time period: ' . $period . ' ' . $date . '<info>' );
		// Output the stats in a table
		$stats_table = new Table( $output );
		$stats_table->setStyle( 'box-double' );
		$stats_table->setHeaders( array( 'Site URL', 'Blog ID', 'Total Views', 'Total Visitors' ) );
		$stats_table->setRows( $team51_site_stats );
		$stats_table->render();

		$output->writeln( '<info>Total views across Team51 sites in ' . $period . ' ' . $date . ': ' . $sum_total_views . '<info>' );
		$output->writeln( '<info>Total visitors across Team51 sites in ' . $period . ' ' . $date . ': ' . $sum_total_visitors . '<info>' );

		$output->writeln( '<info>All done! :)<info>' );
	}

	// Helper functions, getting site stats

	private function get_site_stats( $site_id, $period, $date ) {
		$site_stats = $this->api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_id . '/stats/summary?period=' . $period . '&date=' . $date, array() );
		if ( ! empty( $site_stats->error ) ) {
			$site_stats = null;
		}
		return $site_stats;

	}
}
