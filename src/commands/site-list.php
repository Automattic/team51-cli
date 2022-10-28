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
		->addArgument( 'export', InputArgument::OPTIONAL, "Optional.\nExports the results to a csv or json file saved in the team51-cli folder as sites.csv or sites.json. \nExample usage:\nsite-list csv-export\nsite-list json-export\n" )
		->addOption( 'audit', null, InputOption::VALUE_OPTIONAL, "Optional.\nProduces a full list of sites, with reasons why they were or were not filtered. \nExample usage:\nsite-list --audit='full'\n" )
		->addOption( 'exclude', null, InputOption::VALUE_OPTIONAL, "Optional.\nExclude columns from the export option. Possible values: Site Name, Domain, Site ID, and Host. Letter case is not important.\nExample usage:\nsite-list csv-export --exclude='Site name, Host'\nsite-list json-export --exclude='site id,host'\n" );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;
		$full_audit = false;

		if ( $input->getOption( 'audit' ) ) {
			switch ( $input->getOption( 'audit' ) ) {
				case 'full':
					$full_audit = true;
					break;
			}
		}

		$output->writeln( '<info>Fetching sites...<info>' );

		$all_sites = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites?fields=ID,name,URL,is_private,is_coming_soon,is_wpcom_atomic,jetpack,is_multisite', array() );

		if ( empty( $all_sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_count = count( $all_sites->sites );
		$output->writeln( "<info>{$site_count} sites found in total. Filtering...<info>" );

		$all_sites = $all_sites->sites;

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

		$ignore = array(
			'staging',
			'jurassic',
			'wpengine',
			'wordpress',
			'develop',
			'mdrovdahl',
			'/dev.',
			'woocommerce.com',
		);

		$multisite_patterns = array(
			'com/',
			'org/',
		);

		$free_pass = array(
			'wpspecialprojects.wordpress.com',
			'tumblr.wordpress.com',
			'tonyconrad.wordpress.com',
		);

		$alt_site_list = array();
		foreach ( $all_sites as $site ) {
			$alt_site_list[] = array(
				preg_replace( '/[^a-zA-Z0-9\s&!\/|\'#.()-:]/', '', $site->name ),
				$site->URL,
				$this->eval_ignore_list( $site, $ignore ),
				$this->eval_pass_list( $site, $free_pass ),
				$this->eval_is_private( $site ),
				$this->eval_is_coming_soon( $site ),
				$this->eval_which_host( $site, $pressable_sites ),
				$this->eval_is_multisite( $site, $multisite_patterns ),
				$site->ID,
			);
		}

/**
 * To do:
 * Check if wpcom api returns multi-site.
 */

		$final_site_list = $this->filter_public_sites( $alt_site_list );

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

	protected function eval_which_host( $site, $pressable_sites ) {
		if ( true === $site->is_wpcom_atomic ) {
			$server = 'Atomic';
		} elseif ( true === $site->jetpack ) {
			if ( in_array( parse_url( $site->URL, PHP_URL_HOST ), $pressable_sites ) ) {
				$server = 'Pressable';
			} else {
				$server = 'Other';
			}
		} else {
			$server = 'Simple';
		}
		return $server;
	}

	protected function filter_public_sites( $site_list ) {
		$filtered_site_list = array();
		foreach ( $site_list as $site) {
			if ( '' === $site[4] && '' === $site[5] && '' === $site[7] ) {
				if ( '' === $site[2] || ( '' !== $site[2] && '' !== $site[3] ) ) {
					$filtered_site_list[] = array(
						$site[0],
						$site[1],
						$site[8],
						$site[6],
					);
				}
			}
		}
		return $filtered_site_list;
	}

	protected function eval_ignore_list( $site, $ignore ) {
		$filtered_on = '';
		foreach ( $ignore as $word ) {
			if ( false !== strpos( $site->URL, $word ) ) {
				$filtered_on = $word;
				break;
			}
		}
		return $filtered_on;
	}

	protected function eval_is_multisite( $site, $patterns ) {
		/**
		 * An alternative to this implementation is to compare $site->URL against
		 * $site->options->main_network_site, however the API call is slower since we
		 * can't isolate 'main_network_site' in the call, ie. we get ALL the 'options' fields.
		 * Either a) users of this command are ok to wait longer, or b) we figure out how to
		 * isolate this field in the call/query.
		 * Additionally, the API call with 'options' returns fewer sites. At this moment, 790 vs 794 sites.
		 */
		if ( true === $site->is_multisite ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $site->URL, $pattern ) ) {
					return 'is_subsite';
				}
			}
		}
		return '';
	}

	protected function eval_pass_list( $site, $free_pass ) {
		$filtered_on = '';
		foreach ( $free_pass as $pass ) {
			if ( false !== strpos( $site->URL, $pass ) ) {
				$filtered_on = $pass;
				break;
			}
		}
		return $filtered_on;
	}

	protected function eval_is_private( $site ) {
		if ( true === $site->is_private ) {
			return 'is_private';
		} else {
			return '';
		}
	}

	protected function eval_is_coming_soon( $site ) {
		if ( true === $site->is_coming_soon ) {
			return 'is_coming_soon';
		} else {
			return '';
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
		$csv_header         = array( 'Site Name', 'Domain', 'Site ID', 'Host' );
		$csv_header_compare = array_map(
			function ( $column ) {
				return strtoupper( preg_replace( '/\s+/', '', $column ) );
			},
			$csv_header
		);

		$csv_summary = array(
			array( 'Atomic sites', $atomic_count ),
			array( 'Pressable sites', $pressable_count ),
			array( 'Simple sites', $simple_count ),
			array( 'Other hosts', $other_count ),
			array( 'Total sites', $filtered_site_count ),
		);
		if ( null !== $csv_ex_columns ) {
			$exclude_columns = explode( ',', preg_replace( '/\s+/', '', $csv_ex_columns ) );
			foreach ( $exclude_columns as $column ) {
				$column_index = array_search( strtoupper( $column ), $csv_header_compare, true );
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

	protected function create_json( $site_list_array, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $json_ex_columns ) {
		// To-do: After stripping columns, re-index, then build as an associative array.
		// Reformat summary as a proper pair.
		$json_header         = array( 'Site Name', 'Domain', 'Site ID', 'Host' );
		$json_header_compare = array_map(
			function ( $column ) {
				return strtoupper( preg_replace( '/\s+/', '', $column ) );
			},
			$json_header
		);

		$json_summary = array(
			'Atomic sites'    => $atomic_count,
			'Pressable sites' => $pressable_count,
			'Simple sites'    => $simple_count,
			'Other hosts'     => $other_count,
			'Total sites'     => $filtered_site_count,
		);

		$exclude_columns = explode( ',', strtoupper( preg_replace( '/\s+/', '', $json_ex_columns ) ) );
		$site_list       = array();
		$final_site_list = array();

		foreach ( $site_list_array as &$site ) {
			foreach ( $json_header as $column ) {
				$column_index = array_search( $column, $json_header, true );
				if ( in_array( $json_header_compare[ $column_index ], $exclude_columns, true ) ) {
					continue;
				}
				$site_list[ $json_header[ $column_index ] ] = $site[ $column_index ];
			}
			$final_site_list[] = $site_list;
		}

		$final_site_list[] = $json_summary;

		$fp = fopen( 'sites.json', 'w' );
		fwrite( $fp, json_encode( $final_site_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		fclose( $fp );
	}

}


