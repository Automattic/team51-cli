<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for running a WP-CLI command on a Pressable site.
 */
final class Pressable_Site_Run_WP_CLI_Command extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:run-site-wp-cli-command'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The WP-CLI command to run.
	 *
	 * @var string|null
	 */
	protected ?string $wp_command = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Runs a given WP-CLI command on a given Pressable site.' )
			->setHelp( 'This command allows you to run an arbitrary WP-CLI command on a Pressable site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to run the command on.' )
			->addArgument( 'wp-cli-command', InputArgument::REQUIRED, 'The WP-CLI command to run.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		// Retrieve the given command.
		$this->wp_command = $input->getArgument( 'wp-cli-command' ) ?? $this->prompt_command_input( $input, $output );
		if ( empty( $this->wp_command ) ) { // Also checks for empty string not just null.
			$output->writeln( '<error>WP-CLI command not provided.</error>' );
			exit( 1 ); // Exit if the WP-CLI command does not exist.
		}

		// Store the command in the argument field.
		$this->wp_command = \escapeshellcmd( \trim( \preg_replace( '/^wp/', '', \trim( $this->wp_command ) ) ) );
		$input->setArgument( 'wp-cli-command', $this->wp_command );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? [Y/n]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Running the command `wp $this->wp_command` on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</>" );

		$ssh = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>SSH connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

		$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
		$ssh->exec(
			"wp $this->wp_command",
			static function( string $str ): void {
				echo $str;
			}
		);

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
			$question = new Question( '<question>Enter the site ID or URL to run the WP-CLI command on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Prompts the user for a WP-CLI command if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_command_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the WP-CLI command to run:</question> ' );
			$command  = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $command ?? null;
	}

	// endregion
}
