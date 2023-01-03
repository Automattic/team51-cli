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
		->addOption( 'audit', null, InputOption::VALUE_OPTIONAL, "Optional.\nProduces a full list of sites, with reasons why they were or were not filtered.\nCurrently works with the csv-export and --exclude options.\nAudit values include 'full', for including all sites, 'no-staging' to exclude staging sites, as well as\na general column/text based exclusive filter, eg. 'is_private' will include only private sites. \nExample usage:\nsite-list --audit='full'\nsite-list --audit='no-staging' csv-export\nsite-list --audit='is_private' csv-export --exclude='is_multisite'\n" )
		->addOption( 'exclude', null, InputOption::VALUE_OPTIONAL, "Optional.\nExclude columns from the export option. Possible values: Site Name, Domain, Site ID, and Host. Letter case is not important.\nExample usage:\nsite-list csv-export --exclude='Site name, Host'\nsite-list json-export --exclude='site id,host'\n" );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;

		$audit = false;

		if ( $input->getOption( 'audit' ) ) {
			$audit      = true;
			$audit_type = $input->getOption( 'audit' );
		}

		$output->writeln( '<info>Fetching sites...<info>' );

		$all_sites = $api_helper->call_wpcom_api( 'rest/v1.1/me/sites?include_domain_only=true&fields=ID,name,URL,is_private,is_coming_soon,is_wpcom_atomic,jetpack,is_multisite,options', array() );

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
			$output->writeln( '<error>Failed to retrieve Pressable sites. Aborting!</error>' );
			exit;
		}

		$pressable_sites = array();
		foreach ( $pressable_data->data as $_pressable_site ) {
			$pressable_sites[] = $_pressable_site->url;
		}

		$ignore = array(
			'staging',
			'testing',
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
			'killscreen.com/previously',
		);

		$full_site_list = array();
		foreach ( $all_sites as $site ) {
			$full_site_list[] = array(
				'Site Name'      => preg_replace( '/[^a-zA-Z0-9\s&!\/|\'#.()-:]/', '', $site->name ),
				'Domain'         => $site->URL,
				'ignore'         => $this->eval_ignore_list( $site, $ignore ),
				'free_pass'      => $this->eval_pass_list( $site, $free_pass ),
				'is_private'     => $this->eval_is_private( $site ),
				'is_coming_soon' => $this->eval_is_coming_soon( $site ),
				'Host'           => $this->eval_which_host( $site, $pressable_sites ),
				'is_multisite'   => $this->eval_is_multisite( $site, $multisite_patterns, $pressable_sites ),
				'Site ID'        => $site->ID,
				'is_domain_only' => $this->eval_is_domain_only( $site ),
			);
		}

		if ( $audit ) {
			$audited_site_list = $this->eval_site_list( $full_site_list, $audit_type );

			if ( empty( $audited_site_list ) ) {
				$output->writeln( "<error>Failed to find any sites using the search parameter {$audit_type}.<error>" );
				exit;
			}

			$site_table = new Table( $output );
			$site_table->setStyle( 'box-double' );
			$table_header = array_keys( $audited_site_list[0] );
			$site_table->setHeaders( $table_header );

			$site_table->setRows( $audited_site_list );
			$site_table->render();

			$filters_output = array(
				'MANUAL FILTERS:' => '',
				'The following filters are used to exclude sites from the live site count list.' => '',
				'It works by searching for the term in the site url and if found,' => '',
				'the site is excluded unless explicitly overridden.' => '',
				'Term list:'      => '',
			);

			foreach ( $ignore as $term ) {
				$filters_output[ $term ] = '';
			}

			$filters_output['The following sites are allowed to pass the above filtered terms and'] = '';
			$filters_output['counted as live sites:'] = '';
			foreach ( $free_pass as $pass ) {
				$filters_output[ $pass ] = '';
			}

			$summary_output = array(
				'REPORT SUMMARY'         => '',
				'Private sites'          => $this->count_sites( $audited_site_list, 'is_private', 'is_private' ),
				"'Coming Soon' sites"    => $this->count_sites( $audited_site_list, 'is_coming_soon', 'is_coming_soon' ),
				'Multisite parent sites' => $this->count_sites( $audited_site_list, 'is_parent', 'is_multisite' ),
				'Multisite subsites'     => $this->count_sites( $audited_site_list, 'is_subsite', 'is_multisite' ),
				'Domain only sites'      => $this->count_sites( $audited_site_list, 'is_domain_only', 'is_domain_only' ),
				'Atomic sites'           => $this->count_sites( $audited_site_list, 'Atomic', 'Host' ),
				'Pressable sites'        => $this->count_sites( $audited_site_list, 'Pressable', 'Host' ),
				'Simple sites'           => $this->count_sites( $audited_site_list, 'Simple', 'Host' ),
				'Other hosts'            => $this->count_sites( $audited_site_list, 'Other', 'Host' ),
				'PASSED sites'           => $this->count_sites( $audited_site_list, 'PASS', 'Result' ),
				'FAILED sites'           => $this->count_sites( $audited_site_list, 'FAIL', 'Result' ),
				'Total sites'            => count( $audited_site_list ),
				'AUDIT TYPE/FILTER'      => $audit_type,
			);
			foreach ( $filters_output as $key => $value ) {
				$output->writeln( "<info>{$key}<info>" );
			}
			$output->writeln( "\n" );

			foreach ( $summary_output as $key => $value ) {
				$output->writeln( "<info>{$key}: {$value}<info>" );
			}

			$summary_output  = array_merge( $filters_output, $summary_output );
			$final_site_list = $audited_site_list;
		} else {
			$final_site_list = $this->filter_public_sites( $full_site_list );

			$site_table = new Table( $output );
			$site_table->setStyle( 'box-double' );
			$table_header = array_keys( $final_site_list[0] );
			$site_table->setHeaders( $table_header );

			$site_table->setRows( $final_site_list );
			$site_table->render();

			// Maintain for JSON output compatibility.
			$atomic_count        = $this->count_sites( $final_site_list, 'Atomic', 'Host' );
			$pressable_count     = $this->count_sites( $final_site_list, 'Pressable', 'Host' );
			$other_count         = $this->count_sites( $final_site_list, 'Other', 'Host' );
			$simple_count        = $this->count_sites( $final_site_list, 'Simple', 'Host' );
			$filtered_site_count = count( $final_site_list );

			$summary_output = array(
				'REPORT SUMMARY'  => '',
				'Atomic sites'    => $this->count_sites( $final_site_list, 'Atomic', 'Host' ),
				'Pressable sites' => $this->count_sites( $final_site_list, 'Pressable', 'Host' ),
				'Simple sites'    => $this->count_sites( $final_site_list, 'Simple', 'Host' ),
				'Other hosts'     => $this->count_sites( $final_site_list, 'Other', 'Host' ),
				'Total sites'     => count( $final_site_list ),
			);

			foreach ( $summary_output as $key => $value ) {
				$output->writeln( "<info>{$key}: {$value}<info>" );
			}
		}

		if ( 'csv-export' === $input->getArgument( 'export' ) ) {
			if ( $input->getOption( 'exclude' ) ) {
				$csv_ex_columns = $input->getOption( 'exclude' );
			} else {
				$csv_ex_columns = null;
			}
			$this->create_csv( $table_header, $final_site_list, $summary_output, $csv_ex_columns );
			$output->writeln( '<info>Exported to sites.csv in the current folder.<info>' );
		}

		if ( 'json-export' === $input->getArgument( 'export' ) ) {
			if ( $input->getOption( 'exclude' ) ) {
				$json_ex_columns = $input->getOption( 'exclude' );
			} else {
				$json_ex_columns = null;
			}
			$this->create_json( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $json_ex_columns );
			$output->writeln( '<info>Exported to sites.json in the current folder.<info>' );
		}
	}

	protected function eval_which_host( $site, $pressable_sites ) {
		if ( true === $site->is_wpcom_atomic ) {
			$server = 'Atomic';
		} elseif ( true === $site->jetpack ) {
			if ( in_array( parse_url( $site->URL, PHP_URL_HOST ), $pressable_sites, true ) ) {
				$server = 'Pressable';
			} else {
				$server = 'Other';
			}
		} else {
			$server = 'Simple'; // Need a better way to determine if site is simple. eg. 410'd Jurrasic Ninja sites will show as Simple.
		}
		return $server;
	}

	protected function filter_public_sites( $site_list ) {
		$filtered_site_list = array();
		foreach ( $site_list as $site ) {
			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || ( '' !== $site['ignore'] && '' !== $site['free_pass'] ) ) {
					$filtered_site_list[] = array(
						'Site Name' => $site['Site Name'],
						'Domain'    => $site['Domain'],
						'Site ID'   => $site['Site ID'],
						'Host'      => $site['Host'],
					);
				}
			}
		}
		return $filtered_site_list;
	}

	protected function eval_site_list( $site_list, $audit_type ) {
		$audit_site_list = array();
		foreach ( $site_list as $site ) {
			if ( 'no-staging' === $audit_type && false !== strpos( $site[1], 'staging' ) ) {
				continue;
			}
			if ( 'full' !== $audit_type && 'no-staging' !== $audit_type && ! in_array( $audit_type, $site, true ) ) {
				continue;
			}
			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || ( '' !== $site['ignore'] && '' !== $site['free_pass'] ) ) {
					$result = 'PASS';
				} else {
					$result = 'FAIL';
				}
			} else {
				$result = 'FAIL';
			}
			$audit_site_list[] = array(
				'Site Name'      => $site['Site Name'],
				'Domain'         => $site['Domain'],
				'ignore'         => $site['ignore'],
				'free_pass'      => $site['free_pass'],
				'is_private'     => $site['is_private'],
				'is_coming_soon' => $site['is_coming_soon'],
				'is_multisite'   => $site['is_multisite'],
				'is_domain_only' => $site['is_domain_only'],
				'Host'           => $site['Host'],
				'Result'         => $result,
				'Site ID'        => $site['Site ID'],
			);
		}
		return $audit_site_list;
	}

	protected function eval_ignore_list( $site, $ignore ) {
		$filtered_on = array();
		foreach ( $ignore as $word ) {
			if ( false !== strpos( $site->URL, $word ) ) {
				$filtered_on[] = $word;
			}
		}
		return implode( ',', $filtered_on );
	}

	protected function eval_is_multisite( $site, $patterns, $pressable_sites ) {
		/**
		 * An alternative to this implementation is to compare $site->URL against
		 * $site->options->main_network_site, however all simple sites are returned
		 * as multisites. More investigation required.
		 */
		if ( true === $site->is_multisite ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $site->URL, $pattern ) ) {
					return 'is_subsite';
				} elseif ( 'Simple' !== $this->eval_which_host( $site, $pressable_sites ) ) {
					return 'is_parent';
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

	protected function eval_is_domain_only( $site ) {
		if ( true === $site->options->is_domain_only ) {
			return 'is_domain_only';
		} else {
			return '';
		}
	}

	protected function count_sites( $site_list, $term, $column ) {
		$sites = array_filter(
			$site_list,
			function( $site ) use ( $term, $column ) {
				return $term === $site[ $column ];
			}
		);
		return count( $sites );
	}

	protected function create_csv( $csv_header, $final_site_list, $csv_summary, $csv_ex_columns ) {
		$csv_header_compare = array_map(
			function ( $column ) {
				return strtoupper( preg_replace( '/\s+/', '', $column ) );
			},
			$csv_header
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
		foreach ( $csv_summary as $key => $item ) {
			$final_site_list[] = array( $key, $item );
		}

		$fp = fopen( 'sites.csv', 'w' );
		foreach ( $final_site_list as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );
	}

	protected function create_json( $site_list_array, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $json_ex_columns ) {
		// To-do: After stripping columns, re-index, then build as an associative array.
		// The above is no longer required as the passed array is now and associative array.
		// Improved logic/handling required in L411 to L419, and perhaps others.
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
				$site_list[ $json_header[ $column_index ] ] = $site[ $json_header[ $column_index ] ];
			}
			$final_site_list[] = $site_list;
		}

		$final_site_list[] = $json_summary;

		$fp = fopen( 'sites.json', 'w' );
		fwrite( $fp, json_encode( $final_site_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		fclose( $fp );
	}
}


