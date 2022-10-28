<?php

namespace Team51\Command;

use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for connecting to a Pressable site via SSH/SFTP and continuing on the interactive shell.
 */
class Pressable_Site_Open_Shell extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:open-site-shell'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to connect to.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The interactive hell type to open.
	 *
	 * @var string|null
	 */
	protected ?string $shell_type = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Opens an interactive SSH or SFTP shell to a given Pressable site.' )
			->setHelp( 'This command accepts a Pressable site as an input, then searches for the concierge user to generate the host argument. Lastly, it calls the system SSH/SFTP applications which will authenticate automatically via AutoProxxy.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to connect to.' )
			->addOption( 'shell-type', null, InputArgument::OPTIONAL, 'The type of shell to open. Accepts either "ssh" or "sftp".', 'ssh' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->shell_type = get_enum_input( $input, $output, 'shell-type', array( 'ssh', 'sftp' ), 'ssh' );

		// Retrieve and validate the site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to open an interactive $this->shell_type shell for {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? [Y/n]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Opening an interactive $this->shell_type shell for {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</>" );

		$concierge_user = get_pressable_site_sftp_user_by_email( $this->pressable_site->id, 'concierge@wordpress.com' );
		if ( \is_null( $concierge_user ) ) {
			$output->writeln( '<error>Could not find the concierge user for this site.</error>' );
			return 1;
		}

		$ssh_host = $concierge_user->username . '@' . Pressable_Connection_Helper::SSH_HOST;
		if ( ! \is_null( \passthru( "$this->shell_type $ssh_host", $result_code ) ) ) {
			$output->writeln( "<error>Could not open an interactive $this->shell_type shell. Error code: $result_code</error>" );
			return 1;
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
			$question = new Question( '<question>Enter the site ID or URL to connect to:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
