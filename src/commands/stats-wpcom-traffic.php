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
				"This command will output a summary of wpcom traffic stats across all of our sites.\nExample usage:\nstats:wpcom-traffic --period=year --date=2022-12-12\nstats:wpcom-traffic --num=3 --period=week --date=2021-10-25\nstats:wpcom-traffic --num=6 --period=month --date=2021-02-28\nstats:wpcom-traffic --period=day --date=2022-02-27\n\nThe stats come from: https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/stats/summary/"
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
				"Options: day, week, month, year.\nday: The output will return results over the past [num] days, the last day being the date specified.\nweek: The output will return results over the past [num] weeks, the last week being the week containing the date specified.\nmonth: The output will return results over the past [num] months, the last month being the month containing the date specified.\nyear: The output will return results over the past [num] years, the last year being the year containing the date specified."
			)
			->addOption(
				'date',
				null,
				InputOption::VALUE_REQUIRED,
				'Date format: YYYY-MM-DD.'
			)
			->addOption(
				'csv',
				null,
				InputOption::VALUE_NONE,
				'Export stats to a CSV file.'
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

		$deny_list = array(
			'mystagingwebsite.com',
			'go-vip.co',
			'wpcomstaging.com',
			'wpengine.com',
			'jurassic.ninja',
			'woocommerce.com',
			'atomicsites.blog',
			'ninomihovilic.com',
			'team51.blog',
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
			$output->writeln( '<error>Zero production sites to check.<error>' );
			exit;
		}

		$output->writeln( "<info>{$site_count} sites found.<info>" );

		// Get site stats for each site
		$output->writeln( '<info>Fetching site stats for Team51 production sites...<info>' );
		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$team51_site_stats = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$stats = $this->get_site_stats( $site['blog_id'], $period, $date, $num );

			//Checking if stats are not null. If not, add to array
			if ( ! empty( $stats->views ) ) {
				array_push(
					$team51_site_stats,
					array(
						'blog_id'   => $site['blog_id'],
						'site_url'  => $site['site_url'],
						'views'     => $stats->views,
						'visitors'  => $stats->visitors,
						'comments'  => $stats->comments,
						'followers' => $stats->followers,
					)
				);
			}
		}

		if ( empty( $team51_site_stats ) ) {
			$output->writeln( '<error>Zero sites with stats.<error>' );
			exit;
		}

		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		//Sort the array by total gross sales
		usort(
			$team51_site_stats,
			function ( $a, $b ) {
				return $b['views'] - $a['views'];
			}
		);

		//Sum the totals
		$sum_total_views = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['views'];
			},
			0
		);

		$sum_total_visitors = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['visitors'];
			},
			0
		);

		$sum_total_comments = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['comments'];
			},
			0
		);

		$sum_total_followers = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['followers'];
			},
			0
		);

		$formatted_team51_site_stats = array();
		foreach ( $team51_site_stats as $site ) {
			$formatted_team51_site_stats[] = array( $site['blog_id'], $site['site_url'], number_format( $site['views'], 0 ), number_format( $site['visitors'], 0 ), number_format( $site['comments'], 0 ), number_format( $site['followers'], 0 ) );
		}

		$sum_total_views     = number_format( $sum_total_views, 0 );
		$sum_total_visitors  = number_format( $sum_total_visitors, 0 );
		$sum_total_comments  = number_format( $sum_total_comments, 0 );
		$sum_total_followers = number_format( $sum_total_followers, 0 );

		$output->writeln( '<info>Site stats for Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . '<info>' );
		// Output the stats in a table
		$stats_table = new Table( $output );
		$stats_table->setStyle( 'box-double' );
		$stats_table->setHeaders( array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ) );
		$stats_table->setRows( $formatted_team51_site_stats );
		$stats_table->render();

		$output->writeln( '<info>Total views across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_views . '<info>' );
		$output->writeln( '<info>Total visitors across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_visitors . '<info>' );
		$output->writeln( '<info>Total comments across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_comments . '<info>' );
		$output->writeln( '<info>Total followers across Team51 sites during the ' . $num . ' ' . $period . ' period ending ' . $date . ': ' . $sum_total_followers . '<info>' );

		// Output CSV if --csv flag is set
		if ( $input->getOption( 'csv' ) ) {
			$output->writeln( '<info>Making the CSV...<info>' );
			$timestamp = date( 'Y-m-d-H-i-s' );
			$fp        = fopen( 't51-traffic-stats-' . $timestamp . '.csv', 'w' );
			fputcsv( $fp, array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ) );
			foreach ( $formatted_team51_site_stats as $fields ) {
				fputcsv( $fp, $fields );
			}
			fclose( $fp );

			$output->writeln( '<info>Done, CSV saved to your current working directory: t51-traffic-stats-' . $timestamp . '.csv<info>' );

		}

		$output->writeln( '<info>All done! :)<info>' );
	}

	// Helper functions, getting site stats

	private function get_site_stats( $site_id, $period, $date, $num ) {
		$site_stats = $this->api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_id . '/stats/summary?period=' . $period . '&date=' . $date . '&num=' . $num, array() );
		return $site_stats;
	}
}
