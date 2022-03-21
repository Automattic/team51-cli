<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;

class Site_List extends Command {
	protected static $defaultName = 'site-list';

	protected function configure() {
		$this
		->setDescription( 'Shows list of public facing sites managed by Team 51.' )
		->setHelp( 'Use this command to show a list of sites and summary counts managed by Team 51.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;

		$output->writeln( '<info>Fetching sites, approx. 2 minutes...<info>' );

		$all_sites = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites', array() );

		if ( empty( $all_sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_count = count( $all_sites->sites );
		$output->writeln( "<info>{$site_count} sites found in total. Filtering...<info>" );

		$all_sites = $all_sites->sites;

		$ignore = array(
			'staging',
			'jurassic',
			'wpengine',
			'wordpress',
			'develop',
			'com/',
			'org/',
			'mdrovdahl',
		);

		$filtered_site_list = array();
		foreach ( $all_sites as $site ) {
			$found = false;
			//var_dump( $site->URL);
			foreach ( $ignore as $word ) {
				if ( false !== strpos( $site->URL, $word ) ) {
					$found = true;
					break;
				}
			}
			if ( false === $found && false === $site->is_private ) {
				$filtered_site_list[] = $site;
			}
		}

		// Remove private sites

		$final_site_list = array();
		foreach ( $filtered_site_list as $site ) {
			if ( true === $site->is_wpcom_atomic ) {
				$server = 'Atomic';
			} elseif ( true === $site->jetpack ) {
				$server = 'Pressable';
			} else {
				$server = 'Simple';
			}

			$final_site_list[] = array(
				$site->name,
				$site->URL,
				$site->ID,
				$server,
			);
		}

		$site_table = new Table( $output );
		$site_table->setStyle( 'box-double' );
		$site_table->setHeaders( array( 'Site Name', 'Domain', 'Site ID', 'Host' ) );

		$site_table->setRows( $final_site_list );
		$site_table->render();

		$atomic_count    = $this->count_sites( $final_site_list, 'Atomic' );
		$pressable_count = $this->count_sites( $final_site_list, 'Pressable' );
		$simple_count    = $this->count_sites( $final_site_list, 'Simple' );

		$output->writeln( "<info>{$atomic_count} Atomic sites.<info>" );
		$output->writeln( "<info>{$pressable_count} Pressable (or other) sites.<info>" );
		$output->writeln( "<info>{$simple_count} Simple sites.<info>" );

		$filtered_site_count = count( $filtered_site_list );
		$output->writeln( "<info>{$filtered_site_count} sites filtered.<info>" );

		//var_dump( $filtered_site_list );

	}

	protected function count_sites( $site_list, $term ) {
		$sites = array_filter(
			$site_list,
			function( $site ) use ( $term ) {
				return $term === $site[3];
			}
		);
		return count( $sites );
	}

}

// https://killscreen.com/previously (and /versions) ??? this is being filtered out, FYI.
// Flawed logic in classifying sites. Every site that's not Atomic, or doesn't have a Jetpack connection, is Simple. This is not necessarily true. Need to find another verifier.

