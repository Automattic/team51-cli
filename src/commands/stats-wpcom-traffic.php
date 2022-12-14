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

	protected static $defaultName = 'stats:wpcom-traffic';

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
			->setDescription( 'Get wpcom traffic across all Team51 sites.' )
			->setHelp(
				"This command will output a summary of wpcom traffic stats across all of our sites.\n
				Example usage:\n
				stats:wpcom-traffic --period=year --date=2022-12-12\n
				stats:wpcom-traffic --num=3 --period=week --date=2021-10-25\n
				stats:wpcom-traffic --num=6 --period=month --date=2021-02-28\n
				stats:wpcom-traffic --period=day --date=2022-02-27\n\n
				The stats come from: https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/stats/summary/"
			)
			->addOption(
				'num',
				null,
				InputOption::VALUE_OPTIONAL,
				'Number of periods to include in the results Default: 1.',
				1
			)
			->addOption(
				'period',
				null,
				InputOption::VALUE_REQUIRED,
				'Options: day, week, month, year.\n
				day: The output will return results over the past [num] days, the last day being the date specified.\n
				week: The output will return results over the past [num] weeks, the last week being the week containing the date specified.\n
				month: The output will return results over the past [num] months, the last month being the month containing the date specified.\n
				year: The output will return results over the past [num] years, the last year being the year containing the date specified.'
			)
			->addOption(
				'date',
				null,
				InputOption::VALUE_REQUIRED,
				'Date format: YYYY-MM-DD.'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		// error if the unir or date options are not set
		if ( empty( $input->getOption( 'period' ) ) ) {
			$output->writeln( '<error>Time unit is required for fetching stats. (example: --period=year)</error>' );
			exit;
		}

		if ( empty( $input->getOption( 'date' ) ) ) {
			$output->writeln( '<error>Date is required for fetching stats (example: --date=2022-12-09)</error>' );
			exit;
		}

		$period = $input->getOption( 'period' );
		$date   = $input->getOption( 'date' );
		$num    = $input->getOption( 'num' );

		$output->writeln( '<info>Checking for stats for Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . '<info>' );

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
			$stats = $this->get_site_stats( $site[0], $period, $date, $num );
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

		$formatted_team51_site_stats = array();
		foreach ( $team51_site_stats as $site ) {
			$formatted_team51_site_stats[] = array( $site[0], $site[1], number_format( $site[2], 0 ), number_format( $site[3], 0 ) );
		}

		$sum_total_views    = number_format( $sum_total_views, 0 );
		$sum_total_visitors = number_format( $sum_total_visitors, 0 );

		$output->writeln( '<info>Site stats for Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . '<info>' );
		// Output the stats in a table
		$stats_table = new Table( $output );
		$stats_table->setStyle( 'box-double' );
		$stats_table->setHeaders( array( 'Site URL', 'Blog ID', 'Total Views', 'Total Visitors' ) );
		$stats_table->setRows( $formatted_team51_site_stats );
		$stats_table->render();

		$output->writeln( '<info>Total views across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_views . '<info>' );
		$output->writeln( '<info>Total visitors across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_visitors . '<info>' );

		$output->writeln( '<info>All done! :)<info>' );
	}

	// Helper functions, getting site stats

	private function get_site_stats( $site_id, $period, $date, $num ) {
		$site_stats = $this->api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_id . '/stats/summary?period=' . $period . '&date=' . $date . '&num=' . $num, array() );
		if ( ! empty( $site_stats->error ) ) {
			$site_stats = null;
		}
		return $site_stats;

	}
}
