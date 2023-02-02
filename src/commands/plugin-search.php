<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Team51\Helper\WPCOM_API_Helper;
use function Team51\Helper\get_wpcom_jetpack_sites;
use function Team51\Helper\get_wpcom_sites;
use function Team51\Helper\maybe_define_console_verbosity;


class Plugin_Search extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'plugin-search'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The plugin slug to search for.
	 *
	 * @var string|null
	 */
	protected ?string $plugin_slug = null;

	/**
	 * Whether to do a partial search.
	 *
	 * @var bool|null
	 */
	protected ?bool $partial = null;

	//endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( "Search all Team 51 sites for a specific plugin." )
			->setHelp( "This command will output a list of sites where a particular plugin is installed. A Jetpack site connected to the a8cteam51 account.\nThe search can be made for an exact match plugin slug, or\na general text search. Letter case is ignored in both search types.\nExample usage:\nplugin-search woocommerce\nplugin-search woo --partial='true'\n" );

		$this->addArgument( 'plugin-slug', InputArgument::REQUIRED, "The slug of the plugin to search for. This is an exact match against the plugin installation folder name,\nthe main plugin file name without the .php extension, and the Text Domain.\n" )
			->addOption( 'partial', null, InputOption::VALUE_OPTIONAL, "Optional.\nUse for general text/partial match search. Using this option will also search the plugin Name field." );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->plugin_slug = strtolower( $input->getArgument( 'plugin-slug' ) );
		$this->partial     = (bool) $input->getOption( 'partial' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$plugins = WPCOM_API_Helper::call_api( '/me/sites/plugins' );
		var_dump( count( (array) $plugins->sites ) );

		$sites = get_wpcom_jetpack_sites();
		var_dump( count( $sites ) );

		$sites = get_wpcom_sites();
		var_dump( count( $sites ) );

		return 0;

		$output->writeln( '<info>Fetching list of sites...<info>' );

		$sites = get_wpcom_jetpack_sites();
		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			return 1;
		}

		$site_list = array();
		foreach ( $sites as $site ) {
			$site_list[] = array( $site->userblog_id, $site->domain );
		}
		$site_count = count( $site_list );

		$output->writeln( "<info>{$site_count} sites found.<info>" );
		$output->writeln( "<info>Checking each site for the plugin slug: {$this->plugin_slug}<info>" );

		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$sites_with_plugin = array();
		$sites_not_checked = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$plugin_list = $this->get_list_of_plugins( $site[0] );
			if ( ! is_null( $plugin_list ) ) {
				if ( ! is_null( $plugin_list->data ) ) {
					$plugins_array = json_decode( json_encode( $plugin_list->data ), true );
					foreach ( $plugins_array as $plugin_path => $plugin ) {
						$folder_name = strstr( $plugin_path, '/', true );
						$file_name   = str_replace( array( '/', '.php' ), '', strrchr( $plugin_path, '/' ) );
						if ( $this->partial ) {
							if ( false !== strpos( $plugin['TextDomain'], $this->plugin_slug ) || false !== strpos( $folder_name, $this->plugin_slug ) || false !== strpos( $file_name, $this->plugin_slug ) || false !== strpos( strtolower( $plugin['Name'] ), $this->plugin_slug ) ) {
								$sites_with_plugin[] = array( $site[1], $plugin['Name'], ( $plugin['active'] ? 'Active' : 'Inactive' ), $plugin['Version'] );
							}
						} else {
							if ( $this->plugin_slug === $plugin['TextDomain'] || $this->plugin_slug === $folder_name || $this->plugin_slug === $file_name ) {
								$sites_with_plugin[] = array( $site[1], $plugin['Name'], ( $plugin['active'] ? 'Active' : 'Inactive' ), $plugin['Version'] );
							}
						}
					}
				}
			} else {
				$sites_not_checked[] = array( $site[1], $site[0] );
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		$site_table = new Table( $output );
		$site_table->setStyle( 'box-double' );
		$site_table->setHeaders( array( 'Site URL', 'Plugin Name', 'Plugin Status', 'Plugin Version' ) );
		$site_table->setRows( $sites_with_plugin );
		$site_table->render();

		$output->writeln( '<info>Ignored sites - either not a Jetpack connected site, or the connection is broken.<info>' );
		$not_found_table = new Table( $output );
		$not_found_table->setStyle( 'box-double' );
		$not_found_table->setHeaders( array( 'Site URL', 'Site ID' ) );
		$not_found_table->setRows( $sites_not_checked );
		$not_found_table->render();

		$output->writeln( '<info>All done! :)<info>' );
		return 0;
	}

	// endregion

	private function get_list_of_plugins( $site_id ) {
		$plugin_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/plugins', array() );
		if ( ! empty( $plugin_list->error ) ) {
			$plugin_list = null;
		}
		return $plugin_list;
	}

}
