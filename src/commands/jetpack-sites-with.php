<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;


class Jetpack_Sites_With extends Command {
	use \Team51\Helper\Autocomplete;

	protected static $defaultName = 'jetpack-sites-with';

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
		->setDescription( 'Searches Team 51 sites for a specified Jetpack module based on its status.' )
		->setHelp( 'Use this command to find and show a list of sites where a particular Jetpack module is on or off. This command requires a Jetpack site connected to the a8cteam51 account. Usage Example: team51 jetpack-sites-with adwords on' )
		->addArgument( 'module-slug', InputArgument::REQUIRED, 'The slug of the Jetpack module to search for.' )
		->addArgument( 'module-status', InputArgument::OPTIONAL, 'The status of the Jetpack module to search for.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$module_slug    = strtolower( $input->getArgument( 'module-slug' ) );
		$module_status  = strtolower( $input->getArgument( 'module-status' ) );
		$validation_run = true;

		if ( ! in_array( $module_status, array( 'on', 'off' ), true ) ) {
			$output->writeln( '<error>module-status should be "on" or "off" only. Aborting.<error>' );
			exit;
		}

		$api_helper = new API_Helper;

		$output->writeln( '<info>Fetching list of sites...<info>' );

		$sites = $api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/', array() );

		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_list = array();
		foreach ( $sites->blogs->blogs as $site ) {
			$site_list[] = array( $site->userblog_id, $site->domain );
		}
		$site_count = count( $site_list );

		$output->writeln( "<info>{$site_count} sites found.<info>" );
		$output->writeln( "<info>Checking each site for the Jetpack module: {$module_slug}<info>" );
		$output->writeln( '<info>Expected duration: 8 - 10 mins. Use Ctrl+C to abort.</info>' );

		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$module_match_status = array();
		$sites_not_checked   = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$module_list = $this->get_list_of_modules( $site[0] );
			if ( ! is_null( $module_list ) ) {
				if ( ! is_null( $module_list->data ) ) {
					if ( $validation_run ) {
						$all_modules = array();
						foreach ( $module_list->data as $module ) {
							$all_modules[] = array( $module->module, $module->name );
						}
						if ( ! in_array( $module_slug, array_column( $all_modules, 0 ), true ) ) {
							$output->writeln( '<info>  Oooops!</info>' );
							$output->writeln( "<info>Jetpack module slug \"{$module_slug}\" unknown.<info>" );
							$output->writeln( '<info>Available slugs:<info>' );
							$modules_table = new Table( $output );
							$modules_table->setStyle( 'box-double' );
							$modules_table->setHeaders( array( 'slug', 'Name' ) );
							$modules_table->setRows( $all_modules );
							$modules_table->render();
							exit;
						} else {
							$validation_run = false;
						}
					}

					foreach ( $module_list->data as $module ) {
						if ( $module_slug === $module->module ) {
							$module_match_status[] = array( $site[1], ( $module->activated ? 'on' : 'off' ) );
						}
					}
				}
			} else {
				$sites_not_checked[] = array( $site[1], $site[0] );
			}
		}
		$progress_bar->finish();

		$sites_with_module = array_filter(
			$module_match_status,
			function ( $site ) use ( $module_status ) {
				return $module_status === $site[1];
			}
		);

		$output->writeln( '<info>  Yay!</info>' );
		$output->writeln( "<info>Sites with the Jetpack module \"{$module_slug}\" turned \"{$module_status}\":</info>" );

		$site_table = new Table( $output );
		$site_table->setStyle( 'box-double' );
		$site_table->setHeaders( array( 'Site URL', 'Module Status' ) );
		$site_table->setRows( $sites_with_module );
		$site_table->render();

		$output->writeln( '<info>Ignored sites - either not a Jetpack connected site, or the connection is broken:<info>' );
		$not_found_table = new Table( $output );
		$not_found_table->setStyle( 'box-double' );
		$not_found_table->setHeaders( array( 'Site URL', 'Site ID' ) );
		$not_found_table->setRows( $sites_not_checked );
		$not_found_table->render();

		$output->writeln( '<info>All done! :)<info>' );

		return Command::SUCCESS;
	}

	private function get_list_of_modules( $site_id ) {
		$module_list = $this->api_helper->call_wpcom_api( 'rest/v1.1/jetpack-blogs/' . $site_id . '/rest-api/?path=/jetpack/v4/module/all', array() );
		if ( ! empty( $module_list->error ) ) {
			$module_list = null;
		}
		return $module_list;
	}
}
