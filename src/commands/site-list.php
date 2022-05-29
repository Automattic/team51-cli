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

class Site_List extends Command {
	protected static $defaultName = 'site-list';

	protected function configure() {
		$this
		->setDescription( 'Shows list of public facing sites managed by Team 51.' )
		->setHelp( 'Use this command to show a list of sites and summary counts managed by Team 51.' )
		->addArgument( 'export', InputArgument::OPTIONAL, 'Optional, output the results to a csv or json file by using csv-export or json-export.' )
		->addOption( 'exclude', null, InputOption::VALUE_OPTIONAL, 'Optional, exclude columns from csv output (e.g. --exclude="Site Name","Host"). Possible values: Site Name, Domain, Site ID, Host' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;

		$output->writeln( '<info>Fetching sites...<info>' );

		$all_sites = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites?fields=ID,name,URL,is_private,is_coming_soon,is_wpcom_atomic,jetpack', array() );

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
			'/dev.',
			'woocommerce.com',
		);

		$free_pass = array(
			'wpspecialprojects.wordpress.com',
			'tumblr.wordpress.com',
			'tonyconrad.wordpress.com',
		);

		$filtered_sites = array();
		foreach ( $all_sites as $site ) {
			$found = false;
			foreach ( $ignore as $word ) {
				if ( false !== strpos( $site->URL, $word ) ) {
					$passed = false;
					foreach ( $free_pass as $pass ) {
						if ( false !== strpos( $site->URL, $pass ) ) {
							$passed = true;
							break;
						}
					}
					if ( false === $passed ) {
						$found = true;
						break;
					}
				}
			}
			if ( false === $found && false === $site->is_private ) {
				$filtered_sites[] = $site;
			}
		}

		$filtered_site_list = array_filter(
			$filtered_sites,
			function( $site ) {
				return false === $site->is_coming_soon;
			}
		);

		$pressable_data = $api_helper->call_pressable_api(
			'sites/',
			'GET',
			array()
		);

		if ( empty( $pressable_data->data ) ) {
			$output->writeln( "<error>Failed to retrieve Pressable sites. Aborting!</error>" );
			exit;
		}

		$pressable_sites = array();
		foreach ( $pressable_data->data as $_pressable_site ) {
			$pressable_sites[] = $_pressable_site->url;
		}

		$final_site_list = array();
		foreach ( $filtered_site_list as $site ) {
			if ( true === $site->is_wpcom_atomic ) {
				$server = 'Atomic';
			} elseif ( true === $site->jetpack ) {
				if ( in_array( parse_url( $site->URL, PHP_URL_HOST  ), $pressable_sites ) ) {
					$server = 'Pressable';
				} else {
					$server = 'Other';
				}
			} else {
				$server = 'Simple';
			}

			$final_site_list[] = array(
				preg_replace( '/[^a-zA-Z0-9\s&!\/|\'#.()-:]/', '', $site->name ),
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
		$other_count     = $this->count_sites( $final_site_list, 'Other' );
		$simple_count    = $this->count_sites( $final_site_list, 'Simple' );

		$output->writeln( "<info>{$atomic_count} Atomic sites.<info>" );
		$output->writeln( "<info>{$pressable_count} Pressable sites.<info>" );
		$output->writeln( "<info>{$simple_count} Simple sites.<info>" );
		$output->writeln( "<info>{$other_count} sites hosted elsewhere.<info>" );

		$filtered_site_count = count( $final_site_list );
		$output->writeln( "<info>{$filtered_site_count} sites total.<info>" );

		if ( 'csv-export' === $input->getArgument( 'export' ) ) {
			if ( $input->getOption( 'exclude' ) ) {
				$csv_ex_columns = $input->getOption( 'exclude' );
			} else {
				$csv_ex_columns = null;
			}
			$this->create_csv( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $csv_ex_columns );
			$output->writeln( '<info>Exported to sites.csv in the team51 root folder.<info>' );
		}

		if ( 'json-export' === $input->getArgument( 'export' ) ) {
			if ( $input->getOption( 'exclude' ) ) {
				$json_ex_columns = $input->getOption( 'exclude' );
			} else {
				$json_ex_columns = null;
			}
			$this->create_json( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $json_ex_columns );
			$output->writeln( '<info>Exported to sites.json in the team51 root folder.<info>' );
		}
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

	protected function create_csv( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $csv_ex_columns ) {
		$csv_header  = array( 'Site Name', 'Domain', 'Site ID', 'Host' );
		$csv_summary = array(
			array( $atomic_count . ' Atomic sites.' ),
			array( $pressable_count . ' Pressable (or other) sites.' ),
			array( $simple_count . ' Simple sites.' ),
			array( $other_count . ' sites hosted elsewhere.' ),
			array( $filtered_site_count . ' sites total.' ),
		);
		if ( null !== $csv_ex_columns ) {
			$exclude_columns = explode( ',', $csv_ex_columns );
			foreach ( $exclude_columns as $column ) {
				$column_index = array_search( $column, $csv_header, true );
				unset( $csv_header[ $column_index ] );
				foreach ( $final_site_list as &$site ) {
					unset( $site[ $column_index ] );
				}
				unset( $site );
			}
		}
		array_unshift( $final_site_list, $csv_header );
		foreach ( $csv_summary as $item ) {
			$final_site_list[] = $item;
		}

		$fp = fopen( 'sites.csv', 'w' );
		foreach ( $final_site_list as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );
	}

	protected function create_json( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $json_ex_columns ) {
		// To-do: After stripping columns, re-index, then build as an associative array.
		// Reformat summary as a proper pair.
		$json_header  = array( 'Site Name', 'Domain', 'Site ID', 'Host' );
		$json_summary = array(
			array( $atomic_count . ' Atomic sites.' ),
			array( $pressable_count . ' Pressable (or other) sites.' ),
			array( $simple_count . ' Simple sites.' ),
			array( $other_count . ' sites hosted elsewhere.' ),
			array( $filtered_site_count . ' sites total.' ),
		);
		if ( null !== $json_ex_columns ) {
			$exclude_columns = explode( ',', $json_ex_columns );
			foreach ( $exclude_columns as $column ) {
				$column_index = array_search( $column, $json_header, true );
				unset( $json_header[ $column_index ] );
				foreach ( $final_site_list as &$site ) {
					unset( $site[ $column_index ] );
				}
				unset( $site );
			}
		}
		array_unshift( $final_site_list, $json_header );
		foreach ( $json_summary as $item ) {
			$final_site_list[] = $item;
		}

		$fp = fopen( 'sites.json', 'w' );
		fwrite( $fp, json_encode( $final_site_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT ) );
		fclose( $fp );
	}

}


