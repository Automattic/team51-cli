<?php

namespace Team51\Command;

use phpseclib3\Net\SSH2;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\run_pressable_site_wp_cli_command;

class Update_Plugin_All_Sites extends Command {
	protected static $defaultName = 'update-plugin-all-sites';

	protected function configure() {
		$this
		->setDescription( 'Updates a plugin on all Pressable sites' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper();

		$pressable_data = $api_helper->call_pressable_api(
			'sites/',
			'GET',
			array()
		);

		if ( empty( $pressable_data->data ) ) {
			$output->writeln( '<error>Failed to retrieve Pressable sites. Aborting!</error>' );
			exit;
		}

		foreach ( $pressable_data->data as $pressable_site ) {

			$output->writeln( '<info>Accessing ' . $pressable_site->name . '</info>' );

			$ssh_connection = $this->wait_on_site_ssh( $pressable_site->id, $output );
			if ( is_null( $ssh_connection ) ) {
				$output->writeln( '<error>Failed to connect to the Pressable site via SSH.</error>' );
			}

			if ( ! is_null( $ssh_connection ) ) {
				$ssh_connection->exec( 'ls htdocs/wp-content/plugins', function ( $result ) use ( $pressable_site, $output ) {
					if ( false !== strpos( $result, 'plugin-autoupdate-filter' ) ) {
						
							// We have to delete and install, because wp plugin update doesn't work with URLs.
							
							run_pressable_site_wp_cli_command(
								$this->getApplication(),
								$pressable_site->id,
								'plugin delete plugin-autoupdate-filter',
								$output
							);
				
							run_pressable_site_wp_cli_command(
								$this->getApplication(),
								$pressable_site->id,
								'plugin install https://github.com/a8cteam51/plugin-autoupdate-filter/releases/latest/download/plugin-autoupdate-filter.zip',
								$output
							);
				
							$output->writeln( '<info>Plugin updated.</info>' . PHP_EOL );
						} else {
							$output->writeln( '<info>Plugin not previously installed on site. Skipping</info>' . PHP_EOL );
						}
					} );
			}
			exit;
		}

	}

	/**
	 * Periodically checks the status of the SSH connection to a Pressable site until it's ready.
	 *
	 * @param   string              $site_id    The site ID.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  SSH2|null
	 */
	private function wait_on_site_ssh( string $site_id, OutputInterface $output ): ?SSH2 {

		$ssh_connection = null;

		if ( ! empty( get_pressable_site_sftp_user_by_email( $site_id, 'concierge@wordpress.com' ) ) ) {

			for ( $try = 0, $delay = 5; $try <= 24; $try++ ) {
				$ssh_connection = Pressable_Connection_Helper::get_ssh_connection( $site_id );
				if ( ! \is_null( $ssh_connection ) ) {
					break;
				}

				sleep( $delay );
			}

		}

		return $ssh_connection;
	}

}
