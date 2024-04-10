<?php

namespace Team51\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\create_pressable_site_collaborator;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\list_1password_accounts;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\reset_pressable_site_sftp_user_password;

/**
 * CLI command for connecting to a Pressable site via SSH/SFTP and continuing on the interactive shell.
 */
class Pressable_Site_Open_Shell extends Command {
	use \Team51\Helper\Autocomplete;

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
	 * The email of the Pressable collaborator to connect as.
	 *
	 * @var string|null
	 */
	protected ?string $user_email = null;

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
			->setHelp( 'This command accepts a Pressable site as an input, then searches for the concierge user to generate the host argument. Lastly, it calls the system SSH/SFTP applications which will authenticate automatically via AutoProxxy. If the command is run in verbose mode, it will display the SSH user ID.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to connect to.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'Email of the user to connect as. Defaults to your Team51 1Password email.' )
			->addOption( 'shell-type', null, InputOption::VALUE_REQUIRED, 'The type of shell to open. Accepts either "ssh" or "sftp". Default "ssh".', 'ssh' );
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

		// Figure out the SFTP user to connect as.
		$this->user_email = get_email_input(
			$input,
			$output,
			static function() {
				$team51_op_account = \array_filter(
					list_1password_accounts(),
					static fn( object $account ) => 'ZVYA3AB22BC37JPJZJNSGOPYEQ' === $account->account_uuid
				);
				return empty( $team51_op_account ) ? null : \reset( $team51_op_account )->email;
			},
			'user'
		);
		$input->setOption( 'user', $this->user_email ); // Store the user email in the input.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Opening an interactive $this->shell_type shell for {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) as $this->user_email.</>" );

		// Retrieve the SFTP user for the given email.
		$sftp_user = get_pressable_site_sftp_user_by_email( $this->pressable_site->id, $this->user_email );
		if ( \is_null( $sftp_user ) ) {
			$output->writeln( "<comment>Could not find a Pressable SFTP user with the email $this->user_email on {$this->pressable_site->displayName}. Creating...</comment>", OutputInterface::VERBOSITY_VERBOSE );

			$sftp_user = create_pressable_site_collaborator( $this->user_email, $this->pressable_site->id );
			if ( \is_null( $sftp_user ) ) {
				$output->writeln( "<error>Could not create a Pressable SFTP user with the email $this->user_email on {$this->pressable_site->displayName}.</>" );
				return Command::FAILURE;
			}

			// SFTP users are different from collaborator users. We need to query the API again to get the SFTP user.
			$sftp_user = get_pressable_site_sftp_user_by_email( $this->pressable_site->id, $this->user_email );
		}

		// Team51 users are logged-in through AutoProxxy, but for everyone else we must first reset their password and display it.
		if ( ! \strpos( $this->user_email, '@automattic.com' ) ) { // Check both against 'false' and '0'.
			$output->writeln( "<comment>Resetting the SFTP password of $sftp_user->email on {$this->pressable_site->displayName}...</comment>", OutputInterface::VERBOSITY_VERBOSE );

			$result = reset_pressable_site_sftp_user_password( $this->pressable_site->id, $sftp_user->username );
			if ( \is_null( $result ) ) {
				$output->writeln( "<error>Could not reset the SFTP password of $sftp_user->email on {$this->pressable_site->displayName}.</>" );
				return Command::FAILURE;
			}

			$output->writeln( "<comment>New SFTP user password:</comment> <fg=green;options=bold>$result</>" );
		}

		// Call the system SSH/SFTP application.
		$ssh_host = $sftp_user->username . '@' . Pressable_Connection_Helper::SSH_HOST;

		// If verbose mode is set, show the SSH connect string.
		if ( $output->isVerbose() ) {
			$output->writeln( "<comment>Connecting to $ssh_host...</comment>" );
		}
		if ( ! \is_null( \passthru( "$this->shell_type $ssh_host", $result_code ) ) ) {
			$output->writeln( "<error>Could not open an interactive $this->shell_type shell. Error code: $result_code</error>" );
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
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
