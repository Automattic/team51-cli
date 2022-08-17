<?php

namespace Team51\Command;

use stdClass;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class Mu_Check extends Command {
	protected static $defaultName = 'mu-check';

	const VAULT_FILE = '/Users/taco/Dropbox/Vault/a8c/sftp_credentials.csv';

	private $api_helper;
	private $output;

	/** @inheritDoc */
	protected function configure() {
		$this
		->setDescription( 'Checks for MU Plugins Autoloader' )
		->setHelp( 'obsidian://open?vault=Second%20Brain&file=1%20Projects%2FT51-CLI%20-%20Add%20Safety%20Net%20to%20dev%20sites%2FNotes' );
	}

	/**
	 * Main callback for the command.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		// Set up export files.
		$this->open_export_files();

		// Get the list of sites via Pressable API.
		$sites = $this->get_site_list();

		foreach ( $sites as $site ) {
			$this->output->writeln( '<comment>Checking site: ' . $site->url . '</comment>' );
			$sftp_account = array();

			// Check for SFTP account under SFTP_ACCOUNT_EMAIL.
			// Get SFTP credentials at the same time.
			$sftp_connection = $this->api_helper->pressable_sftp_connect( $site->id, $sftp_account );

			if ( ! $sftp_connection ) {
				$this->output->writeln( "<error>Failed to create SFTP account for $site->url" );
				continue;
			}
			// Export credentials to Vault.
			$this->export_sftp_credentials( $site, $sftp_account );

			// Check for mu-plugins folder.
			$this->check_mu_plugins_folder( $sftp_account );
		}

		// Clean up files.
		$this->close_export_files();

		$output->writeln( "<info>\nAll done!<info>" );
	}


	/*************************************
	 *************************************
	 *             HANDLERS              *
	 *************************************
	 *************************************/

	/**
	 * Get the list of sites via Pressable API.
	* Returns an array of sites.
	*/
	private function get_site_list() {
		$response = $this->api_helper->call_pressable_api( 'sites?tag_name=mikestraw-test', 'GET', array() );
		if ( empty( $response->data ) ) {
			$this->output->writeln( '<error>Failed to get site list</error>' );
			exit;
		}
		return $response->data;
	}

	/**
	 * Exports the SFTP credentials to Vault.
	 * @param stdClass $sftp_account
	 */
	private function export_sftp_credentials( $site, $sftp_account ) {
		$this->output->writeln( '<comment>Exporting SFTP credentials to Vault</comment>' );
		$record = array(
			'title'    => $site->displayName,
			'url'      => sprintf( 'sftp://%s@%s', $sftp_account['sftp_username'], $sftp_account['sftp_hostname'] ),
			'username' => $sftp_account['sftp_username'],
			'password' => $sftp_account['sftp_password'],
		);
		fwrite( $this->file_handles['vault'], implode( ',', $record ) . "\n" );
	}

	/**
	 * Checks for the mu-plugins folder.
	 * @param stdClass $sftp_account
	 */
	private function check_mu_plugins_folder( $sftp_account ) {
		// If doesn't exist, output to passed-sites.txt
		// Otherwise, check if mu-autoloader.php exists.
		// If it exists, check to see if it matches the example.
		// It does, output to passed-sites.txt
		// Otherwise, dump mu-autoloader.php to <sitename>-<siteid>-autoloader.php
		// If mu-autoloader.php doesn't exist, dump file list of mu-plugins to <sitename>-<siteid>-mu-plugins.txt
	}

	private function open_export_files() {
		$this->output->writeln( '<comment>Opening export files</comment>' );
		$this->file_handles['vault'] = fopen( self::VAULT_FILE, 'w' );
		if ( ! $this->file_handles['vault'] ) {
			$this->output->writeln( '<error>Failed to open Vault export file</error>' );
			exit;
		}
		fwrite( $this->file_handles['vault'], "Title,URL,Username,Password\n" );
	}

	private function close_export_files() {
		foreach ( $this->file_handles as $handle ) {
			fclose( $handle );
		}
	}
}
