<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for getting PHP errors from a Pressable site.
 */
class Pressable_Site_PHP_Errors extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'php-errors'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to retrieve the logs for.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The format to output the logs in.
	 *
	 * @var string|null
	 */
	protected ?string $format = null;

	/**
	 * The number of distinct fatal errors to retrieve.
	 *
	 * @var int|null
	 */
	protected ?int $limit = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Pulls the 3 most recent distinct fatal errors from the site\'s PHP error log.' )
			->setHelp( 'Ex: team51 php-errors asia.si.edu --format raw --limit 10' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to retrieve the error log from.' )
			->addOption( 'format', null, InputOption::VALUE_REQUIRED, 'The format to output the logs in. Accepts either "table" or "raw".' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'The number of distinct PHP fatal errors to return. Default is 3.', 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->format = get_enum_input( $input, $output, 'format', array( 'raw', 'table' ) );
		$this->limit  = max( 1, (int) $input->getOption( 'limit' ) );

		// Retrieve and validate the site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the input.
		$input->setArgument( 'site', $this->pressable_site->id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Retrieving the last $this->limit distinct PHP fatal errors for {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</>" );

		// Connect to the site via SFTP.
		$sftp_connection = Pressable_Connection_Helper::get_sftp_connection( $this->pressable_site->id );
		if ( \is_null( $sftp_connection ) ) {
			$output->writeln( "<error>Failed to connect to SFTP for {$this->pressable_site->url}. Aborting!</error>" );
			return 1;
		}

		// Retrieve the error log file.
		$output->writeln( '<comment>Downloading the PHP error log.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$error_log = $sftp_connection->get( '/tmp/php-errors' );
		if ( empty( $error_log ) ) {
			if ( false === $error_log ) {
				$output->writeln( "<error>Failed to download the PHP error log for {$this->pressable_site->url}. Aborting!</error>" );
			} else {
				$output->writeln( "<info>The PHP error log for {$this->pressable_site->url} appears to be empty. Go make some errors and try again!</info>" );
			}

			return 1;
		}

		// Output the raw log if requested in said format.
		if ( 'raw' === $this->format ) {
			$this->output_raw_error_log( $error_log, $output );
			return 0;
		}

		// Parse the error log for the most recent fatal errors.
		$output->writeln( '<comment>Parsing the error log into something usable.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$php_errors = $this->parse_error_log( $error_log );
		if ( 0 === count( $php_errors ) ) {
			$output->writeln( "<info>The PHP error log for {$this->pressable_site->url} appears to be empty. Go make some errors and try again!</info>" );
			return 0;
		}

		$stats_table = $this->analyze_error_entries( $php_errors );
		$stats_table = \array_slice( $stats_table, 0, $this->limit );

		// Output the log based on requested format.
		switch ( $this->format ) {
			case 'table':
				$this->output_table_error_log( $stats_table, $output );
				break;
			default:
				$this->output_default_error_log( $stats_table, $output );
				break;
		}

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to retrieve the error log for:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Parses a given error log string into its constituent error entries.
	 *
	 * @param   string  $error_log  The raw string content of the error log.
	 *
	 * @return  array[]
	 */
	private function parse_error_log( string $error_log ): array {
		$parsed_php_errors = array();

		$php_errors = \explode( "\n", $error_log ); // Pressable sites run on Linux, so the separator is always \n. PHP_EOL could be \r\n on Windows.
		foreach ( $php_errors as $php_error ) {
			// Ignore non-fatal entries.
			if ( false === \stripos( $php_error, 'php fatal' ) ) {
				continue;
			}

			// Extract individual components of the error entry.
			\preg_match( '/\[(.*)\].*(PHP .*?):(.*)/', $php_error, $matches );
			$matches = \array_map( 'trim', $matches );

			if ( empty( $matches[1] ) || empty( $matches[2] ) || empty( $matches[3] ) ) {
				continue;
			}

			$parsed_php_errors[] = array(
				'timestamp'     => $matches[1],
				'error_level'   => $matches[2],
				'error_message' => $matches[3],
			);
		}

		return $parsed_php_errors;
	}

	/**
	 * Sorts the distinct error log entries by when they last happened.
	 *
	 * @param   array   $parsed_php_errors  The error log entries as parsed by the @parse_error_log method.
	 *
	 * @return  array
	 */
	private function analyze_error_entries( array $parsed_php_errors ): array {
		$stats_table = array();

		// Count each distinct error and keep track of its most recent occurrence.
		foreach ( $parsed_php_errors as $parsed_php_error ) {
			$error_hash = \hash( 'md5', $parsed_php_error['error_message'] );
			if ( isset( $stats_table[ $error_hash ] ) ) {
				$stats_table[ $error_hash ]['error_count']++;

				if ( \strtotime( $parsed_php_error['timestamp'] ) > \strtotime( $stats_table[ $error_hash ]['timestamp'] ) ) {
					$stats_table[ $error_hash ]['timestamp'] = $parsed_php_error['timestamp'];
				}
			} else {
				$stats_table[ $error_hash ] = array(
					'timestamp'     => $parsed_php_error['timestamp'],
					'error_level'   => $parsed_php_error['error_level'],
					'error_message' => $parsed_php_error['error_message'],
					'error_count'   => 1,
				);
			}
		}

		// Sort fatal errors by timestamp.
		\usort(
			$stats_table,
			static fn ( $a, $b ) => -1 * \strtotime( $b['timestamp'] ) <=> \strtotime( $a['timestamp'] )
		);

		return $stats_table;
	}

	/**
	 * Outputs the raw error log to the console.
	 *
	 * @param   string              $error_log      The error log as downloaded via SFTP.
	 * @param   OutputInterface     $output         The output object.
	 *
	 * @return  void
	 */
	private function output_raw_error_log( string $error_log, OutputInterface $output ): void {
		\passthru( 'clear' );
		$output->write( $error_log );
	}

	/**
	 * Outputs the error log as a formatted table.
	 *
	 * @param   array               $stats_table    The sorted log entries table.
	 * @param   OutputInterface     $output         The output object.
	 *
	 * @return  void
	 */
	private function output_table_error_log( array $stats_table, OutputInterface $output ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( 'The 3 most recent PHP Fatal Errors' );
		$table->setHeaders( array( '' ) );

		foreach ( $stats_table as $key => $table_row ) {
			$table->addRow( array( new TableCell( "Timestamp: {$table_row['timestamp']}" ) ) );
			$table->addRow( array( new TableCell( "Error Level: {$table_row['error_level']}" ) ) );
			$table->addRow( array( new TableCell( "Error Count: {$table_row['error_count']}" ) ) );
			$table->addRow( array( new TableCell( "<fg=magenta>{$table_row['error_message']}</>" ) ) );

			if ( \array_key_last( $stats_table ) !== $key ) {
				$table->addRow( new TableSeparator() );
			}
		}

		$table->setColumnMaxWidth( 0, 128 );
		$table->setStyle( 'box-double' );
		$table->render();
	}

	/**
	 * Outputs the error log entry by entry.
	 *
	 * @param   array               $stats_table    The sorted log entries table.
	 * @param   OutputInterface     $output         The output object.
	 *
	 * @return  void
	 */
	private function output_default_error_log( array $stats_table, OutputInterface $output ): void {
		$output->writeln( '' );
		$output->writeln( '-- The 3 most recent PHP Fatal Errors --' );
		$output->writeln( '' );

		foreach ( $stats_table as $table_row ) {
			$output->writeln( "<info>Timestamp: {$table_row['timestamp']}</info>" );
			$output->writeln( "<info>Error Level: {$table_row['error_level']}</info>" );
			$output->writeln( "<info>Error Count: {$table_row['error_count']}</info>" );
			$output->writeln( "<fg=magenta>{$table_row['error_message']}</>" );

			/* @noinspection DisconnectedForeachInstructionInspection */
			$output->writeln( '' );
		}
	}

	// endregion
}
