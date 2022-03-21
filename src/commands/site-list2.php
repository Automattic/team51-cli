<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;

class Site_List2 extends Command {
	protected static $defaultName = 'site-list2';

	protected function configure() {
		$this
		->setDescription( 'Shows list of sites owned by .' )
		->setHelp( 'Use this command to show a list of installed plugins on a site. This command requires a Jetpack site connected to the a8cteam51 account.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;

		$wpcom_sites = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites', array() );

		//var_dump( $wpcom_sites );

		$site_count = count( $wpcom_sites->sites );
		$output->writeln( "<info>{$site_count} wpcom sites found.<info>" );

		if ( empty( $wpcom_sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_table = new Table( $output );
		$site_table->setStyle( 'box-double' );
		$site_table->setHeaders( array( 'Site Name', 'Domain', 'Site ID' ) );

		$site_list = array();
		foreach ( $wpcom_sites->sites as $site ) {
			//$test = strpos( $site->siteurl, 'mystagingwebsite' );
			//var_dump( $site->siteurl );
			if ( false === strpos( $site->URL, 'staging' ) && false === strpos( $site->URL, 'jurassic' ) && false === strpos( $site->URL, 'wpengine' ) && false === strpos( $site->URL, 'wordpress' ) && false === strpos( $site->URL, 'develop' ) ) {
				$site_list[] = array( $site->name, $site->URL, $site->ID );
			}
		}
		asort( $site_list );

		$site_table->setRows( $site_list );
		$site_table->render();

		$site_list_count = count( $site_list );

		$output->writeln( "<info>{$site_list_count} Non development wpcom sites found.<info>" );

		$jetpack_sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

				$jetpack_site_count = count( $jetpack_sites->blogs->blogs );
		$output->writeln( "<info>{$jetpack_site_count} Jetpack sites found.<info>" );

		if ( empty( $jetpack_sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$jetpack_site_table = new Table( $output );
		$jetpack_site_table->setStyle( 'box-double' );
		$jetpack_site_table->setHeaders( array( 'Site Name', 'Domain', 'Site ID' ) );

		$jetpack_site_list = array();
		foreach ( $jetpack_sites->blogs->blogs as $blog ) {
			//$test = strpos( $site->siteurl, 'mystagingwebsite' );
			//var_dump( $site->siteurl );
			if ( false === strpos( $blog->siteurl, 'staging' ) && false === strpos( $blog->siteurl, 'jurassic' ) && false === strpos( $blog->siteurl, 'wpengine' ) && false === strpos( $blog->siteurl, 'wordpress' ) && false === strpos( $blog->siteurl, 'develop' ) ) {
				$jetpack_site_list[] = array( $blog->blogname, $blog->siteurl, $blog->userblog_id );
			}
		}
		asort( $jetpack_site_list );

		$jetpack_site_table->setRows( $site_list );
		$jetpack_site_table->render();

		$jetpack_site_list_count = count( $jetpack_site_list );

		$output->writeln( "<info>{$jetpack_site_list_count} non-development Jetpack sites found.<info>" );

		$output->writeln( "<info>Cross checking lists.<info>" );

		$filtered_jetpack_site_list = array();

		foreach ( $jetpack_site_list as $jetpack_site ) {
			//var_dump( $jetpack_site[1] );
			//$key_one = in_array( $jetpack_site[1], array_column( $site_list, 1 ) );
			//var_dump( $key_one );

			if ( false !== ( $key = array_search( $jetpack_site[1], array_column( $site_list, 1 ) ) ) ) {
				unset( $site_list[ $key ] );
				sort( $site_list );
			} else {
				$filtered_jetpack_site_list[] = $jetpack_site;
			}
		}

		//$cross_check = array_diff( $jetpack_site_list, $site_list );
		$new_site_count = count( $site_list );
		$new_blog_count = count( $filtered_jetpack_site_list );

		//$jetpack_sites_difference = array_diff( $jetpack_site_list, $filtered_jetpack_site_list );

		$output->writeln( "<info>WPCom sites.<info>" );
		$wp_site_table = new Table( $output );
		$wp_site_table->setStyle( 'box-double' );
		$wp_site_table->setHeaders( array( 'Site Name', 'Domain', 'Site ID' ) );
		$wp_site_table->setRows( $site_list );
		$wp_site_table->render();

		$output->writeln( "<info>Jetpack sites.<info>" );
		$jp_site_table = new Table( $output );
		$jp_site_table->setStyle( 'box-double' );
		$jp_site_table->setHeaders( array( 'Site Name', 'Domain', 'Site ID' ) );
		$jp_site_table->setRows( $filtered_jetpack_site_list );
		$jp_site_table->render();

		$output->writeln( "<info>{$new_site_count} WPCom sites left after filtering out Jetpack sites.<info>" );
		$output->writeln( "<info>{$new_blog_count} Jetpack sites not in wpcom list of sites.<info>" );

	}
}

/*
* Filter out duplicates
* Fitler out private sites
* Make a filter list to check against?
* WPCom has all the sites.
*

Get array of sites on wpcom.
Filter out sites that are dev, staging, etc. array_map or array_filter?
Filter out private sites.
Separate into Simple, Atomic, and Pressable (or other)
Summarize with counts of each.
Send to team to verify results.

*/








