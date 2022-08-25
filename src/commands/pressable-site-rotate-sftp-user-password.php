<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_related_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\get_deployhq_project_by_permalink;
use function Team51\Helper\get_deployhq_project_permalink_from_pressable_site;
use function Team51\Helper\get_deployhq_project_servers;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_pressable_site_sftp_user_from_input;
use function Team51\Helper\get_pressable_site_sftp_users;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\output_related_pressable_sites;
use function Team51\Helper\reset_pressable_site_sftp_user_password;
use function Team51\Helper\update_deployhq_project_server;

/**
 * CLI command for rotating the SFTP password of users on Pressable sites.
 */
final class Pressable_Site_Rotate_SFTP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-sftp-user-password'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all' or 'related', if set.
	 *
	 * @var string|null
	 */
	protected ?string $multiple = null;

	/**
	 * The list of Pressable sites to process.
	 *
	 * @var array|null
	 */
	protected ?array $pressable_sites = null;

	/**
	 * The list of Pressable site SFTP users to process.
	 * Must be 1-to-1 in sync with $pressable_sites.
	 *
	 * @var array|null
	 */
	protected ?array $pressable_sftp_users = null;

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
		$this->setDescription( 'Rotates the SFTP user password of a given user on Pressable sites.' )
			->setHelp( 'This command allows you to rotate the SFTP password of users on Pressable sites. If the given user is also the website owner (default concierge@wordpress.com), then the DeployHQ configuration is also updated.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to rotate the SFTP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'ID, email, or username of the site SFTP user for which to rotate the password. Default is concierge@wordpress.com.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepts one of \'all\' or \'related\'.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current SFTP password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all', 'related' ) );

		// If processing a given site, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			if ( \is_null( $pressable_site ) ) {
				exit( 1 ); // Exit if the site does not exist.
			}

			// Store the ID of the site in the argument field.
			$input->setArgument( 'site', $pressable_site->id );
		} else {
			$pressable_site = null;
		}

		// If processing a given user, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$pressable_sftp_user = get_pressable_site_sftp_user_from_input( $input, $output, $pressable_site->id, fn() => $this->prompt_user_input( $input, $output ) );
			if ( \is_null( $pressable_sftp_user ) ) {
				exit( 1 ); // Exit if the SFTP user does not exist.
			}

			// Store the email of the SFTP user in the option field.
			$input->setOption( 'user', $pressable_sftp_user->email );
		} else {
			$pressable_sftp_user = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
			$input->setOption( 'user', $pressable_sftp_user ); // Store the email of the SFTP user in the option field.
		}

		// Compile the lists of Pressable sites and SFTP users to process.
		if ( \is_null( $this->multiple ) ) { // One single, given website.
			$this->pressable_sites      = array( $pressable_site );
			$this->pressable_sftp_users = array( $pressable_sftp_user );
		} else { // Multiple websites.
			if ( 'related' === $this->multiple ) {
				$this->pressable_sites = get_related_pressable_sites( $pressable_site );
				output_related_pressable_sites( $output, $this->pressable_sites );

				// Flatten out the related websites tree.
				$this->pressable_sites = \array_merge( ...$this->pressable_sites );
			} else { // 'all'
				$this->pressable_sites = get_pressable_sites();
			}

			$output->writeln( '<info>Compiling list of Pressable SFTP users...</info>' );
			$progress_bar = new ProgressBar( $output, \count( $this->pressable_sites ) );
			$progress_bar->start();

			$this->pressable_sftp_users = \array_map(
				static function( object $site ) use ( $pressable_sftp_user, $progress_bar ) {
					$progress_bar->advance();
					return get_pressable_site_sftp_user_by_email( $site->id, $pressable_sftp_user->email ?? $pressable_sftp_user );
				},
				$this->pressable_sites
			);

			$progress_bar->finish();
			$output->writeln( '' ); // Empty line for UX purposes.
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $this->multiple ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$input->getOption( 'user' )} on <fg=red;options=bold>ALL</> sites? [Y/n]</question> ", false );
				break;
			case 'related':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$input->getOption( 'user' )} on all the sites listed above? [Y/n]</question> ", false );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$this->pressable_sftp_users[0]->username} (ID {$this->pressable_sftp_users[0]->id}, email {$this->pressable_sftp_users[0]->email}) on {$this->pressable_sites[0]->displayName} (ID {$this->pressable_sites[0]->id}, URL {$this->pressable_sites[0]->url})? [Y/n]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( 'all' === $this->multiple && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the SFTP users password? [Y/n]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 * @noinspection DisconnectedForeachInstructionInspection
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->pressable_sites as $index => $pressable_site ) {
			$pressable_sftp_user = $this->pressable_sftp_users[ $index ];
			if ( \is_null( $pressable_sftp_user ) ) { // This can only happen if we're processing multiple sites. For single sites, the command would've aborted during initialization.
				$output->writeln( "<error>The SFTP user {$input->getOption( 'user' )} does not exist on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url). Skipping site...</error>" );
				continue;
			}

			$output->writeln( "<fg=magenta;options=bold>Rotating the SFTP password of $pressable_sftp_user->username (ID $pressable_sftp_user->id, email $pressable_sftp_user->email) on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</>" );

			// Rotate Pressable SFTP password.
			$new_pressable_sftp_password = $this->reset_site_sftp_user_password( $output, $pressable_site->id, $pressable_sftp_user->username );
			if ( \is_null( $new_pressable_sftp_password ) ) {
				continue;
			}

			$output->writeln( '<fg=green;options=bold>SFTP user password rotated.</>' );
			$output->writeln(
				"<comment>New SFTP user password:</comment> <fg=green;options=bold>$new_pressable_sftp_password</>",
				true === $pressable_sftp_user->owner ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_NORMAL
			);

			// Update the DeployHQ configuration, if required.
			if ( true !== $pressable_sftp_user->owner ) {
				$output->writeln( '<info>SFTP user is not project owner. No DeployHQ configuration update required.</info>', OutputInterface::VERBOSITY_VERBOSE );
				continue;
			}

			$output->writeln( '<info>SFTP user is project owner. DeployHQ project server configuration update required...</info>' );

			$result = $this->handle_deployhq_project_server_update( $input, $output, $pressable_site, $pressable_sftp_user->username, $new_pressable_sftp_password );
			if ( false === $result ) {
				$output->writeln( "<info>If needed, please update the DeployHQ project server password manually to: $new_pressable_sftp_password</info>" );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>DeployHQ project server configuration updated.</>' );
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
			$question = new Question( '<question>Enter the site ID or URL to rotate the SFTP password on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

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
				$question = new Question( '<question>Enter the user email to rotate the SFTP password for:</question> ' );
				if ( 'all' !== $this->multiple ) { // Autocompletion is only available when a singular site is provided.
					$question->setAutocompleterValues( \array_map( static fn( object $sftp_user ) => $sftp_user->email, get_pressable_site_sftp_users( $input->getArgument( 'site' ) ) ?? array() ) );
				}

				$email = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
		}

		return $email;
	}

	/**
	 * Resets a given user's SFTP password on the given site and returns the new password on success.
	 *
	 * @param   OutputInterface     $output     The output instance.
	 * @param   string              $site_id    The site ID.
	 * @param   string              $username   The username of the site SFTP user.
	 *
	 * @return  string|null
	 */
	private function reset_site_sftp_user_password( OutputInterface $output, string $site_id, string $username ): ?string {
		if ( true === $this->dry_run ) {
			$new_sftp_password = '********';
			$output->writeln( '<comment>Dry run: SFTP user password rotation skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
		} else {
			$new_sftp_password = reset_pressable_site_sftp_user_password( $site_id, $username );
			if ( \is_null( $new_sftp_password ) ) {
				$output->writeln( '<error>Failed to reset SFTP user password.</error>' );
			}
		}

		return $new_sftp_password;
	}

	/**
	 * Handles matching a DeployHQ project server to a given site and updating the password to the given one.
	 *
	 * @param   InputInterface      $input                  The input instance.
	 * @param   OutputInterface     $output                 The output instance.
	 * @param   object              $site                   The Pressable site object.
	 * @param   string              $sftp_username          The Pressable SFTP username.
	 * @param   string              $new_sftp_password      The new SFTP password.
	 *
	 * @return  bool
	 */
	private function handle_deployhq_project_server_update( InputInterface $input, OutputInterface $output, object $site, string $sftp_username, string $new_sftp_password ): bool {
		// Retrieve the DeployHQ project for the site.
		$deployhq_project = $this->get_deployhq_project_from_site( $input, $output, $site );
		if ( \is_null( $deployhq_project ) ) {
			$output->writeln( '<error>Failed to retrieve DeployHQ project.</error>' );
			return false;
		}

		$output->writeln( "<comment>DeployHQ project found: $deployhq_project->name ($deployhq_project->permalink).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

		// Retrieve the DeployHQ project servers.
		$deployhq_project_servers = get_deployhq_project_servers( $deployhq_project->permalink );
		if ( empty( $deployhq_project_servers ) ) { // Covers the case where the project has no servers.
			$output->writeln( '<error>Failed to retrieve DeployHQ servers or no servers configured.</error>' );
			return false;
		}

		// Find the correct DeployHQ server for the site by matching the SFTP username.
		$deployhq_server = \array_filter( $deployhq_project_servers, static fn( object $server ) => $server->username === $sftp_username );
		$deployhq_server = \reset( $deployhq_server );
		if ( false === $deployhq_server ) {
			$output->writeln( '<error>Failed to find DeployHQ server for site.</error>' );
			return false;
		}

		$output->writeln( "<comment>DeployHQ project server found: $deployhq_server->name ($deployhq_server->identifier).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

		// Update the DeployHQ server config password.
		return $this->update_deployhq_project_server_password( $output, $deployhq_project->permalink, $deployhq_server->identifier, $new_sftp_password );
	}

	/**
	 * Retrieves a DeployHQ project for a given site. If it fails, the user is prompted to provide a project permalink.
	 *
	 * @param   InputInterface      $input      The input instance.
	 * @param   OutputInterface     $output     The output instance.
	 * @param   object              $site       The Pressable site object.
	 *
	 * @return  object|null
	 */
	private function get_deployhq_project_from_site( InputInterface $input, OutputInterface $output, object $site ): ?object {
		$deployhq_project_permalink = get_deployhq_project_permalink_from_pressable_site( $site );
		$output->writeln( "<comment>DeployHQ project permalink: $deployhq_project_permalink</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

		$deployhq_project = get_deployhq_project_by_permalink( $deployhq_project_permalink );
		if ( \is_null( $deployhq_project ) ) {
			$output->writeln( "<error>Failed to retrieve DeployHQ project $deployhq_project_permalink.</error>" );

			if ( $input->isInteractive() ) {
				$question = new Question( '<question>Enter the DeployHQ project permalink:</question> ' );
				$question->setValidator(
					function( string $answer ) {
						$deployhq_project = get_deployhq_project_by_permalink( \str_replace( ' ', '-', \trim( $answer ) ) );
						if ( \is_null( $deployhq_project ) ) {
							throw new \RuntimeException( 'Invalid DeployHQ project permalink.' );
						}

						return $deployhq_project;
					}
				);
				$question->setMaxAttempts( 3 );

				$deployhq_project = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
		}

		return $deployhq_project;
	}

	/**
	 * Updates the DeployHQ server password for the given project to the given password.
	 *
	 * @param   OutputInterface     $output                 The output instance.
	 * @param   string              $project_permalink      The DeployHQ project permalink.
	 * @param   string              $server_identifier      The DeployHQ server identifier.
	 * @param   string              $new_sftp_password      The new Pressable SFTP password.
	 *
	 * @return  bool
	 */
	private function update_deployhq_project_server_password( OutputInterface $output, string $project_permalink, string $server_identifier, string $new_sftp_password ): bool {
		$result = true;

		if ( true === $this->dry_run ) {
			$output->writeln( '<comment>Dry run: DeployHQ project server password update skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
		} else {
			$deployhq_server = update_deployhq_project_server(
				$project_permalink,
				$server_identifier,
				array( // Sending just the 'password' parameter won't work. Experimentally, the protocol type parameter is also required.
					'protocol_type' => 'ssh',
					'password'      => $new_sftp_password,
				)
			);
			if ( \is_null( $deployhq_server ) ) {
				$output->writeln( '<error>Failed to update DeployHQ project server password.</error>' );
				$result = false;
			}
		}

		return $result;
	}

	// endregion
}
