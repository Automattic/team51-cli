<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Team51\Helper\WPCOM_API_Helper;
use function Team51\Helper\decode_json_content;
use function Team51\Helper\encode_json_content;
use function Team51\Helper\get_wpcom_jetpack_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command to search all Team51 sites for a specific plugin.
 */
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
		$this->setDescription( 'Search all Team51 WPCOM Jetpack sites for a specific plugin.' )
			->setHelp( "This command will output a list of Jetpack sites connected to the a8cteam51 account where a particular plugin is installed.\nThe search can be made for an exact match plugin slug, or\na general text search. Letter case is ignored in both search types.\nExample usage:\nplugin-search woocommerce\nplugin-search woo --partial\n" );

		$this->addArgument( 'plugin-slug', InputArgument::REQUIRED, "The slug of the plugin to search for. This is an exact match against the plugin installation folder name,\nthe main plugin file name without the .php extension, and the Text Domain.\n" )
			->addOption( 'partial', null, InputOption::VALUE_NONE, "Optional.\nUse for general text/partial match search. Using this option will also search the plugin Name field." );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->plugin_slug = \strtolower( $input->getArgument( 'plugin-slug' ) );
		$this->partial     = (bool) $input->getOption( 'partial' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$partial_match_text = $this->partial ? 'partial' : 'exact';
		$output->writeln( "<fg=magenta;options=bold>Searching through the WPCOM Jetpack sites for the plugin with the slug `$this->plugin_slug` ($partial_match_text match).</>" );

		// Get the list of sites.
		$sites = get_wpcom_jetpack_sites();
		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>Successfully fetched ' . count( $sites ) . ' Jetpack sites.</>', OutputInterface::VERBOSITY_VERBOSE );

		// Get the list of plugins for each site.
		$sites_plugins = WPCOM_API_Helper::call_api_concurrent(
			\array_map(
				static fn( $site ) => "/jetpack-blogs/$site->userblog_id/rest-api/?path=/jetpack/v4/plugins",
				$sites
			)
		);
		$sites_plugins = \array_combine(
			\array_column( $sites, 'userblog_id' ),
			$sites_plugins
		);

		// Filter the sites that have the plugin installed.
		$sites_with_plugin = array();
		$sites_not_checked = array();
		foreach ( $sites_plugins as $jetpack_id => $plugins_list ) {
			if ( \is_null( $plugins_list ) || ! \property_exists( $plugins_list, 'data' ) ) {
				$sites_not_checked[ $jetpack_id ] = $sites[ $jetpack_id ];
				continue;
			}

			$plugins_array = decode_json_content( encode_json_content( $plugins_list->data ), true );
			foreach ( $plugins_array as $plugin_path => $plugin_data ) {
				$plugin_folder = \strstr( $plugin_path, '/', true );
				$plugin_file   = \basename( $plugin_path, '.php' );

				if ( $this->is_exact_match( $plugin_data, $plugin_folder, $plugin_file ) || ( $this->partial && $this->is_partial_match( $plugin_data, $plugin_folder, $plugin_file ) ) ) {
					$sites_with_plugin[ $jetpack_id ]['site']      = $sites[ $jetpack_id ];
					$sites_with_plugin[ $jetpack_id ]['plugins'][] = $plugin_data + array( 'path' => $plugin_path );
				}
			}
		}

		// Output the results.
		$output->writeln( '<fg=green;options=bold>Found ' . count( $sites_with_plugin ) . ' sites with the plugin installed.</>', OutputInterface::VERBOSITY_VERBOSE );
		$this->output_found_site_list( $sites_with_plugin, $output );

		if ( ! empty( $sites_not_checked ) ) {
			$output->writeln( '<fg=yellow;options=bold>Could not check ' . count( $sites_not_checked ) . ' sites for the plugin.</>', OutputInterface::VERBOSITY_VERBOSE );
			$this->output_not_checked_site_list( $sites_not_checked, $output );
		}

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns whether the given plugin data is an exact match for the plugin slug.
	 *
	 * @param   array   $plugin_data        The plugin data as returned by the API.
	 * @param   string  $plugin_folder      The plugin folder name.
	 * @param   string  $plugin_file        The plugin file name.
	 *
	 * @return  bool
	 */
	protected function is_exact_match( array $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return $this->plugin_slug === $plugin_data['TextDomain'] || $this->plugin_slug === $plugin_folder || $this->plugin_slug === $plugin_file;
	}

	/**
	 * Returns whether the given plugin data is a partial match for the plugin slug.
	 *
	 * @param   array   $plugin_data        The plugin data as returned by the API.
	 * @param   string  $plugin_folder      The plugin folder name.
	 * @param   string  $plugin_file        The plugin file name.
	 *
	 * @return  bool
	 */
	protected function is_partial_match( array $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return false !== strpos( $plugin_data['TextDomain'], $this->plugin_slug ) || false !== strpos( $plugin_folder, $this->plugin_slug ) || false !== strpos( $plugin_file, $this->plugin_slug ) || false !== stripos( $plugin_data['Name'], $this->plugin_slug );
	}

	/**
	 * Outputs in tabular form the list of sites that have the plugin installed.
	 *
	 * @param   array               $sites_with_plugin      The list of sites with the plugin installed.
	 * @param   OutputInterface     $output                 The output object.
	 *
	 * @return  void
	 */
	protected function output_found_site_list( array $sites_with_plugin, OutputInterface $output ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( 'Found sites' );
		$table->setHeaders( array( 'Site URL', 'Plugin Name', 'Plugin Path', 'Plugin Status', 'Plugin Version' ) );

		foreach ( $sites_with_plugin as $site ) {
			foreach ( $site['plugins'] as $match ) {
				$table->addRow( array( $site['site']->domain, $match['Name'], $match['path'], ( $match['active'] ? 'Active' : 'Inactive' ), $match['Version'] ) );
			}
		}

		$table->setColumnMaxWidth( 0, 128 );
		$table->setStyle( 'box-double' );
		$table->render();
	}

	/**
	 * Outputs in tabular form the list of sites that could not be checked.
	 *
	 * @param   array               $sites_not_checked      The list of sites that could not be checked.
	 * @param   OutputInterface     $output                 The output object.
	 *
	 * @return  void
	 */
	protected function output_not_checked_site_list( array $sites_not_checked, OutputInterface $output ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( 'Sites not checked - most likely, the connection is broken' );
		$table->setHeaders( array( 'Site URL', 'Site ID' ) );

		foreach ( $sites_not_checked as $site ) {
			$table->addRow( array( $site->domain, $site->userblog_id ) );
		}

		$table->setColumnMaxWidth( 0, 128 );
		$table->setStyle( 'box-double' );
		$table->render();
	}

	// endregion
}
