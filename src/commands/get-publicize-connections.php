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

class Publicize_Connections extends Command {

	protected static $defaultName = 'get-publicize-connections';

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

		$output->writeln( '<info>Fetching publicize connections to Twitter for each site...<info>' );

		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$publicize_connections = $this->get_publicize_connections( $site['blog_id'] );
			if ( ! empty( $publicize_connections->connections ) ) {
				$publicize_twitter_connections[] = array(
					'site_url'             => $site['site_url'],
					'blog_id'              => $site['blog_id'],
					'service'              => $publicize_connections->connections[0]->service,
					'external_name'        => $publicize_connections->connections[0]->external_name,
					'external_profile_URL' => $publicize_connections->connections[0]->external_profile_URL,
					'connected'            => $publicize_connections->connections[0]->issued,
					'status'               => $publicize_connections->connections[0]->status,
					'expires'              => $publicize_connections->connections[0]->expires,

				);

			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Done!</info>' );

		// make a csv out of the $plugins_on_t51_sites array
		$output->writeln( '<info>Making the CSV...<info>' );

		$fp = fopen( 'sites-with-twitter-connections.csv', 'w' );
		fputcsv( $fp, array( 'Site URL', 'Blog ID', 'Twitter URL' ) );
		foreach ( $publicize_twitter_connections as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );

		$output->writeln( '<info>Done, CSV saved to your current working directory: sites-with-twitter-connections.csv<info>' );

	}

	// Helper functions, getting list of plugins and getting woocommerce stats
	private function get_publicize_connections( $site_id ) {
		$publicize_connections = $this->api_helper->call_wpcom_api( 'rest/v1.1/sites/' . $site_id . '/publicize-connections/?service=twitter', array() );
		if ( ! empty( $publicize_connections->error ) ) {
			$publicize_connections = null;
		}
		return $publicize_connections;
	}
}
