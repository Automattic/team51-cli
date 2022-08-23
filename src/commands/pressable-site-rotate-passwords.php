<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;

/**
 * CLI command for rotating the SFTP and WP user passwords of a given user on one or all Pressable sites.
 */
final class Pressable_Site_Rotate_Passwords extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-passwords';

	/**
	 * The Pressable site to rotate the passwords on.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The email of the user to rotate the passwords for.
	 *
	 * @var string|null
	 */
	protected ?string $user_email = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Rotates the SFTP user and WordPress user passwords of a given user on Pressable sites.' )
			->setHelp( 'This command calls the commands "pressable:rotate-site-sftp-user-password" and "pressable:rotate-site-wp-user-password", in this order, with the same arguments and options as provided to this command.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to rotate the passwords.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'Email of the user for which to rotate the passwords. Default is concierge@wordpress.com.' );

		$this->addOption( 'all-sites', null, InputOption::VALUE_NONE, 'Rotate the passwords on all sites.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current passwords. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve the user email.
		$this->user_email = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
		$input->setOption( 'user', $this->user_email ); // Store the email in the input for subcommands to use.

		// Retrieve the site and make sure it exists.
		if ( ! $input->getOption( 'all-sites' ) ) {
			$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			if ( false !== \is_null( $this->pressable_site ) ) {
				exit; // Exit if the site does not exist.
			}

			$input->setArgument( 'site', $this->pressable_site->id ); // Store the ID of the site in the input for subcommands to use.
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( $input->getOption( 'all-sites' ) ) {
			$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords for $this->user_email on <fg=red;options=bold>ALL</> sites? [Y/n]</question> ", false );
		} else {
			$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords for $this->user_email on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? [Y/n]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit;
		}

		if ( $input->getOption( 'all-sites' ) && ! $input->getOption( 'dry-run' ) ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the passwords? [Y/n]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit;
			}
		}
	}

	/**
	 * {@inheritDoc}
	 * @noinspection NullPointerExceptionInspection
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Rotating passwords for $this->user_email.</>" );

		// Rotate the SFTP user(s) password.
		$output->writeln( '' ); // Empty line for UX purposes.
		$output->writeln( '<fg=blue;options=bold>----- SFTP User(s) Password -----</>' );

		$sftp_password_rotate_command       = $this->getApplication()->find( 'pressable:rotate-site-sftp-user-password' );
		$sftp_password_rotate_command_input = new ArrayInput(
			array(
				'site'        => $input->getArgument( 'site' ),
				'--user'      => $this->user_email,
				'--all-sites' => $input->getOption( 'all-sites' ),
				'--dry-run'   => $input->getOption( 'dry-run' ),
			)
		);
		$sftp_password_rotate_command_input->setInteractive( false );

		/* @noinspection PhpUnhandledExceptionInspection */
		$sftp_password_rotate_command->run( $sftp_password_rotate_command_input, $output );

		// Rotate the WP user(s) password.
		$output->writeln( '' ); // Empty line for UX purposes.
		$output->writeln( '<fg=blue;options=bold>----- WP User(s) Password -----</>' );

		$wp_password_rotate_command       = $this->getApplication()->find( 'pressable:rotate-site-wp-user-password' );
		$wp_password_rotate_command_input = new ArrayInput(
			array(
				'site'        => $input->getArgument( 'site' ),
				'--user'      => $this->user_email,
				'--all-sites' => $input->getOption( 'all-sites' ),
				'--dry-run'   => $input->getOption( 'dry-run' ),
			)
		);
		$wp_password_rotate_command_input->setInteractive( false );

		/* @noinspection PhpUnhandledExceptionInspection */
		$wp_password_rotate_command->run( $wp_password_rotate_command_input, $output );

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for an email or returns the default if in 'no-interaction' mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function prompt_user_input( InputInterface $input, OutputInterface $output ): string {
		if ( $input->getOption( 'no-interaction' ) ) {
			$email = 'concierge@wordpress.com';
		} else {
			$question = new ConfirmationQuestion( '<question>No user was provided. Do you wish to continue with the default concierge user? [Y/n]</question> ', false );
			if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$email = 'concierge@wordpress.com';
			} else {
				$question = new Question( '<question>Enter the user email to rotate the passwords for:</question> ' );
				$email    = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
		}

		return $email;
	}

	/**
	 * Prompts the user for a site if not in 'no-interaction' mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$question = new Question( '<question>Enter the site ID or URL to rotate the passwords on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
