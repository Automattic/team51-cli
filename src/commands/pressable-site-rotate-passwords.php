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
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_pressable_site_by_id;
use function Team51\Helper\get_related_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\output_related_pressable_sites;
use function Team51\Helper\run_app_command;

/**
 * CLI command for rotating the SFTP and WP user passwords of a given user on Pressable sites.
 */
final class Pressable_Site_Rotate_Passwords extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-passwords'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all' or 'related', if set.
	 *
	 * @var string|null
	 */
	protected ?string $multiple = null;

	/**
	 * The email of the user to process.
	 *
	 * @var string|null
	 */
	protected ?string $user_email = null;

	/**
	 * Whether to actually rotate the password or just simulate doing so.
	 *
	 * @var bool|null
	 */
	protected ?bool $dry_run = null;

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

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepts only \'related\' currently.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current passwords. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'related' ) );

		// Retrieve the user email which is always required.
		$this->user_email = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
		$input->setOption( 'user', $this->user_email ); // Store the email of the user in the input.

		// Retrieve the given site if applicable.
		if ( 'all' !== $this->multiple ) {
			$pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			if ( false !== \is_null( $pressable_site ) ) {
				exit( 1 ); // Exit if the site does not exist.
			}

			// Store the ID of the site in the argument field.
			$input->setArgument( 'site', $pressable_site->id );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $input->getOption( 'multiple' ) ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords for $this->user_email on <fg=red;options=bold>ALL</> sites? [Y/n]</question> ", false );
				break;
			case 'related':
				output_related_pressable_sites( $output, get_related_pressable_sites( get_pressable_site_by_id( $input->getArgument( 'site' ) ) ) );
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords of $this->user_email on all the sites listed above? [Y/n]</question> ", false );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords of $this->user_email on {$input->getArgument('site')}? [Y/n]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( 'all' === $this->multiple && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the passwords? [Y/n]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Rotating passwords for {$input->getOption( 'user' )}.</>" );

		// Rotate the SFTP user(s) password.
		$output->writeln( '' ); // Empty line for UX purposes.
		$output->writeln( '<fg=blue;options=bold>----- SFTP User(s) Password -----</>' );

		/* @noinspection PhpUnhandledExceptionInspection */
		run_app_command(
			$this->getApplication(),
			Pressable_Site_Rotate_SFTP_User_Password::getDefaultName(),
			\array_filter(
				array(
					'site'       => $input->getArgument( 'site' ),
					'--user'     => $this->user_email,
					'--multiple' => $this->multiple,
					'--dry-run'  => $this->dry_run,
				)
			),
			$output
		);

		// Rotate the WP user(s) password.
		$output->writeln( '' ); // Empty line for UX purposes.
		$output->writeln( '<fg=blue;options=bold>----- WP User(s) Password -----</>' );

		/* @noinspection PhpUnhandledExceptionInspection */
		run_app_command(
			$this->getApplication(),
			Pressable_Site_Rotate_WP_User_Password::getDefaultName(),
			\array_filter(
				array(
					'site'       => $input->getArgument( 'site' ),
					'--user'     => $this->user_email,
					'--multiple' => $this->multiple,
					'--dry-run'  => $this->dry_run,
				)
			),
			$output
		);

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for an email or returns the default if not in interactive mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function prompt_user_input( InputInterface $input, OutputInterface $output ): string {
		if ( ! $input->isInteractive() ) {
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
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to rotate the passwords on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
