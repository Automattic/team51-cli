<?php

namespace Team51\Command;

use stdClass;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class Mu_Check extends Command {
	protected static $defaultName = 'mu-check';

	const OUTPUT_PATH = '/Users/taco/Dropbox/Vault/a8c/';
	const PASSED_FILE = self::OUTPUT_PATH . '/passed-sites.txt';
	const VAULT_FILE  = self::OUTPUT_PATH . '/site-creds.csv';

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
		// $this->open_export_files();

		// Get the list of sites via Pressable API.
		$sites      = $this->get_site_list();
		$site_names = array_column( $sites, 'name' );
		$this->output->writeln( sprintf( '<comment>%s</comment>', implode( "\n", $site_names ) ) );

		// foreach ( $sites as $site ) {
		// 	$this->output->writeln( '<comment>Checking site: ' . $site->url . '</comment>' );
		// 	$sftp_account = array();

		// 	// Check for SFTP account under SFTP_ACCOUNT_EMAIL.
		// 	// Get SFTP credentials at the same time.
		// 	$sftp_connection = $this->api_helper->pressable_sftp_connect( $site->id, $sftp_account );

		// 	if ( ! $sftp_connection ) {
		// 		$this->output->writeln( "<error>Failed to create SFTP account for $site->url" );
		// 		continue;
		// 	}
		// 	// Export credentials to Vault.
		// 	$this->export_sftp_credentials( $site, $sftp_account );

		// 	// Check for mu-plugins folder.
		// 	$this->check_mu_plugins_folder( $site, $sftp_connection );
		// }

		// Clean up files.
		// $this->close_files( $this->file_handles );

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
		$response = $this->api_helper->call_pressable_api( 'sites', 'GET', array() );
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
	private function check_mu_plugins_folder( $site, $sftp_connection ) {
		$mu_plugins_dir      = '/htdocs/wp-content/mu-plugins';
		$mu_autoloader_regex = '/function\s*disable_autoupdate_specific_plugins\s*\(\s*\$update,\s*\$item\s*\)\s*{\s*\/\/\s*Array\s*of\s*plugin\s*slugs\s*to\s*never\s*auto-update\s*\$plugins\s*=\s*array\s*\(\s*\'akismet\',\s*\'buddypress\',\s*\);\s*if\s*\(\s*in_array\(\s*\$item->slug,\s*\$plugins\s*\)\s*\)\s*{\s*\/\/\s*Never\s*update\s*plugins\s*in\s*this\s*array\s*return\s*false;\s*}\s*else\s*{\s*\/\/\s*Else,\s*do\s*whatever\s*it\s*was\s*going\s*to\s*do\s*before\s*return\s*\$update;\s*}\s*}\s*add_filter\(\s*\'auto_update_plugin\',\s*\'disable_autoupdate_specific_plugins\',\s*11,\s*2\s*\);/m';

		// If doesn't exist, output to passed-sites.txt
		$sftp_connection->chdir( $mu_plugins_dir );
		if ( $mu_plugins_dir !== $sftp_connection->pwd() ) {
			// If there's no mu-plugins folder, we don't need to check for the autoloader.
			fwrite( $this->file_handles['passed'], $site->name . "\n" );
			$this->output->writeln( sprintf( '<comment>%s: No mu-plugins folder found</comment>', $site->name ) );
			return;
		}
		// Otherwise, check if mu-autoloader.php exists.
		$tmp_file          = tempnam( sys_get_temp_dir(), 'mu-autoloader' );
		$has_mu_autoloader = $sftp_connection->get( 'mu-autoloader.php', $tmp_file );

		if ( $has_mu_autoloader ) {
			// If it exists, check to see if it matches the example.
			exec(
				sprintf( 'diff -w -B %s %s', 'scaffold/mu-autoloader.php', $tmp_file ),
				$diff
			);
			if ( empty( $diff ) ) {
				// If it matches, output to passed-sites.txt.
				fwrite( $this->file_handles['passed'], $site->name . "\n" );
				$this->output->writeln( sprintf( '<comment>%s: mu-autoloader.php matches</comment>', $site->name ) );
				return;
			}

			// Otherwise, dump the diff to <sitename>-<siteid>-autoloader.diff
			$outfile = fopen( sprintf( '%s/%s-%s-autoloader.diff', self::OUTPUT_PATH, $site->name, $site->id ), 'w' );

			fwrite( $outfile, implode( "\n", $diff ) );
			$this->output->writeln( sprintf( '<comment>%s: mu-autoloader.php does not match</comment>', $site->name ) );
			fclose( $outfile );
			return;
		}
		// If mu-autoloader.php doesn't exist, check for files beyond just index.php
		$files = $sftp_connection->rawlist();
		if ( count( $files ) > 3 // Including . and ..
				|| ! isset( $files['index.php'] ) ) {
			// If there are more than just index.php, dump the file list to <sitename>-<siteid>-files.txt.
			$outfile = fopen( sprintf( '%s/%s-%s-files.txt', self::OUTPUT_PATH, $site->name, $site->id ), 'w' );
			fwrite( $outfile, var_export( array_keys( $files ), true ) );
			$this->output->writeln( sprintf( '<comment>%s: mu-autoloader.php not found</comment>', $site->name ) );
			fclose( $outfile );
			return;
		}
		// If there are no other files, add to passed-sites.txt
		fwrite( $this->file_handles['passed'], $site->name . "\n" );
	}

	private function open_export_files() {
		$this->output->writeln( '<comment>Opening export files</comment>' );
		$this->file_handles['vault'] = fopen( self::VAULT_FILE, 'w' );
		if ( ! $this->file_handles['vault'] ) {
			$this->output->writeln( '<error>Failed to open Vault export file</error>' );
			exit;
		}
		fwrite( $this->file_handles['vault'], "Title,URL,Username,Password\n" );

		$this->file_handles['passed'] = fopen( self::PASSED_FILE, 'w' );
		if ( ! $this->file_handles['passed'] ) {
			$this->output->writeln( '<error>Failed to open passed sites export file</error>' );
			exit;
		}
	}

	private function close_files( $files ) {
		foreach ( $files as $handle ) {
			fflush( $handle );
			fclose( $handle );
		}
	}

	/**
	 * Opens output files for a site.
	 *
	 * @param string $site
	 * @param int $site_id
	 * @return array Array of file handles.
	 */
	private function open_site_files( $site_name, $site_id ) {
		$autoloader_file = sprintf( '%s/%s-%s-autoloader.diff', self::OUTPUT_PATH, $site_name, $site_id );
		$mu_plugins_file = sprintf( '%s/%s-%s-mu-plugins.txt', self::OUTPUT_PATH, $site_name, $site_id );

		$files               = array();
		$files['autoloader'] = fopen( $autoloader_file, 'w' );
		if ( ! $files['autoloader'] ) {
			$this->output->writeln( "<error>Failed to open file {$autoloader_file}</error>" );
			exit;
		}
		$files['mu'] = fopen( $mu_plugins_file, 'w' );
		if ( ! $files['mu'] ) {
			$this->output->writeln( "<error>Failed to open file {$mu_plugins_file}</error>" );
			exit;
		}
		return $files;
	}
}
