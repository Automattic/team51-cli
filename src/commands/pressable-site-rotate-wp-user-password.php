<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\decode_json_content;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_related_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\generate_random_password;
use function Team51\Helper\get_email_input;
use function Team51\Helper\get_pressable_site_collaborator_by_email;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_site_sftp_user_by_email;
use function Team51\Helper\get_wpcom_site_user_by_email;
use function Team51\Helper\is_case_insensitive_match;
use function Team51\Helper\output_related_pressable_sites;
use function Team51\Helper\reset_pressable_site_collaborator_wp_password;
use function Team51\Helper\reset_pressable_site_owner_wp_password;
use function Team51\Helper\set_wpcom_site_user_wp_password;

/**
 * CLI command for rotating the WP password of users on Pressable sites.
 */
final class Pressable_Site_Rotate_WP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-wp-user-password'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

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
	 * The email of the WP user to process.
	 *
	 * @var string|null
	 */
	protected ?string $wp_user_email = null;

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
		$this->setDescription( 'Rotates the WordPress user password of a given user on Pressable sites.' )
			->setHelp( 'This command allows you to rotate the WP password of users on Pressable sites. Finally, it attempts to update the 1Password values of rotated passwords as well.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to rotate the WP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'Email of the site WP user for which to rotate the password. Default is concierge@wordpress.com.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepts one of \'all\' or \'related\'.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current WP user password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all', 'related' ) );

		// Retrieve the WP user email.
		$this->wp_user_email = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
		$input->setOption( 'user', $this->wp_user_email ); // Store the email of the user in the input.

		// If processing a given site, retrieve it from the input.
		$pressable_site = null;
		if ( 'all' !== $this->multiple ) {
			$pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			if ( \is_null( $pressable_site ) ) {
				exit( 1 ); // Exit if the site does not exist.
			}

			// Store the ID of the site in the argument field.
			$input->setArgument( 'site', $pressable_site->id );
		}

		// Compile the lists of Pressable sites to process.
		if ( \is_null( $this->multiple ) ) { // One single, given website.
			$this->pressable_sites = array( $pressable_site );
		} elseif ( 'related' === $this->multiple ) {
			$this->pressable_sites = get_related_pressable_sites( $pressable_site );
			output_related_pressable_sites( $output, $this->pressable_sites );

			// Flatten out the related websites tree.
			$this->pressable_sites = \array_merge( ...$this->pressable_sites );
		} else { // 'all'
			$this->pressable_sites = get_pressable_sites();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $this->multiple ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of $this->wp_user_email on <fg=red;options=bold>ALL</> sites? [Y/n]</question> ", false );
				break;
			case 'related':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of $this->wp_user_email on all the sites listed above? [Y/n]</question> ", false );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of $this->wp_user_email on {$this->pressable_sites[0]->displayName} (ID {$this->pressable_sites[0]->id}, URL {$this->pressable_sites[0]->url})? [Y/n]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( 'all' === $this->multiple && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the WP users password? [Y/n]</question> ', false );
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
		foreach ( $this->pressable_sites as $pressable_site ) {
			unset( $wp_username ); // Make sure we don't carry over the previous site's WP username.

			$output->writeln( "<fg=magenta;options=bold>Rotating the WP user password of $this->wp_user_email on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</>" );

			// Rotate the WP user password.
			$result = $this->rotate_site_wp_user_password( $output, $pressable_site, $new_wp_user_password, $wp_username );
			if ( true !== $result ) {
				$output->writeln( '<error>Failed to rotate WP user password.</error>' );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>WP user password rotated.</>' );
			$output->writeln( "<comment>New WP user password:</comment> <fg=green;options=bold>$new_wp_user_password</>", OutputInterface::VERBOSITY_DEBUG );

			/*
			// Update the 1Password password value.
			$result = $this->update_1password_login( $input, $output, $new_wp_user_password, $wp_username );
			if ( true !== $result ) {
				$output->writeln( '<error>Failed to update 1Password value.</error>' );
				$output->writeln( "<info>If needed, please update the 1Password value manually to: $new_wp_user_password</info>" );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>WP user password updated in 1Password.</>' );
			*/
		}

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
				$question = new Question( '<question>Enter the user email to rotate the WP password for:</question> ' );
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
			$question = new Question( '<question>Enter the site ID or URL to rotate the WP password on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Rotates the WP password of the given user on a given site.
	 *
	 * @param   OutputInterface     $output             The output instance.
	 * @param   object              $pressable_site     The Pressable site object.
	 * @param   string|null         $password           The new password.
	 * @param   string|null         $username           The username of the user, if found.
	 *
	 * @return  bool|null   Whether the password was successfully set. Null means that an API attempt wasn't even made (most likely, no user found).
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function rotate_site_wp_user_password( OutputInterface $output, object $pressable_site, ?string &$password = null, ?string &$username = null ): ?bool {
		$result = null;

		/* @noinspection PhpUnhandledExceptionInspection */
		$new_password = $password ?? generate_random_password();

		// First attempt to set the password via the WPCOM/Jetpack API.
		$wpcom_user = get_wpcom_site_user_by_email( $pressable_site->url, $this->wp_user_email );
		if ( ! \is_null( $wpcom_user ) ) { // User found on site and Jetpack connection is active.
			$output->writeln( "<comment>WP user $wpcom_user->login (ID $wpcom_user->ID, email $wpcom_user->email) found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
			$output->writeln( "<info>Setting the WP user password for $wpcom_user->login (ID $wpcom_user->ID, email $wpcom_user->email) via the WPCOM API.</info>", OutputInterface::VERBOSITY_VERBOSE );

			$username = $wpcom_user->login;
			if ( true === $this->dry_run ) {
				$output->writeln( '<comment>Dry run: WP user password setting skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
				$result = true;
			} else {
				$result = set_wpcom_site_user_wp_password( $pressable_site->url, $wpcom_user->ID, $new_password );
			}
		} else {
			$output->writeln( "<comment>WP user $this->wp_user_email <fg=red;options=bold>NOT</> found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
		}

		// If the password wasn't set via the WPCOM/Jetpack API, maybe try resetting it via the Pressable API.
		if ( true !== $result ) {
			// Pressable has special endpoints for owners vs collaborators.
			$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $this->wp_user_email );
			if ( ! \is_null( $pressable_sftp_user ) && true === $pressable_sftp_user->owner ) { // SFTP user found on site and is a site owner.
				$output->writeln( "<info>Resetting the WP user password for the Pressable site owner $pressable_sftp_user->username (ID $pressable_sftp_user->id, email $pressable_sftp_user->email) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

				if ( true === $this->dry_run ) {
					$output->writeln( '<comment>Dry run: WP user password reset of Pressable site owner skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );

					/* @noinspection PhpUnhandledExceptionInspection */
					$new_password = generate_random_password();
					$result       = true;
				} else {
					$new_password = reset_pressable_site_owner_wp_password( $pressable_site->id );
					$result       = ! \is_null( $new_password );
				}
			} else {
				$pressable_collaborator = get_pressable_site_collaborator_by_email( $pressable_site->id, $this->wp_user_email );
				if ( ! \is_null( $pressable_collaborator ) ) {
					$output->writeln( "<info>Resetting the WP user password for Pressable collaborator $pressable_collaborator->wpUsername (ID $pressable_collaborator->id, email $pressable_collaborator->email) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

					$username = $pressable_collaborator->wpUsername;
					if ( true === $this->dry_run ) {
						$output->writeln( '<comment>Dry run: WP user password reset of Pressable site collaborator skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );

						/* @noinspection PhpUnhandledExceptionInspection */
						$new_password = generate_random_password();
						$result       = true;
					} else {
						$new_password = reset_pressable_site_collaborator_wp_password( $pressable_site->id, $pressable_collaborator->id );
						$result       = ! \is_null( $new_password );
					}
				} else {
					$output->writeln( "<comment>WP user $this->wp_user_email <fg=red;options=bold>NOT</> found via the Pressable API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
				}
			}
		}

		$password = $result ? $new_password : $password;
		return $result;
	}

	/**
	 * Updates the 1Password entry for the WP user and site.
	 *
	 * The 1Password CLI has a limitation that prevents setting multiple URLs per entry, so it's irrelevant whether we
	 * actually managed to set the same password everywhere or not. We need one entry per site anyway.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 * @param   string              $password   The password to set.
	 * @param   string|null         $username   The username of the WP user, if known.
	 *
	 * @return  bool|null   True if the update was successful, null if the update was never attempted.
	 */
	private function update_1password_login( InputInterface $input, OutputInterface $output, string $password, ?string $username = null ): ?bool {
		static $op_items = null;

		if ( true === \is_null( $op_items ) ) { // Performance and rate-limiting optimization.
			$op_items = decode_json_content( \shell_exec( 'op item list --categories login --format json' ) );
			if ( true === \is_null( $op_items ) ) {
				$output->writeln( '<error>1Password logins could not be retrieved.</error>' );
				return null;
			}
		}

		// Find main production site login.
		$op_login = \array_filter(
			\array_map(
				function ( object $login ) use ( $output, $username ) {
					$login_urls = \property_exists( $login, 'urls' ) ? (array) $login->urls : array();
					foreach ( $login_urls as $login_url ) {
						$login_url = \trim( $login_url->href );
						if ( false !== \strpos( $login_url, 'http' ) ) {
							// Strip away everything but the domain itself.
							$login_url = \parse_url( $login_url, PHP_URL_HOST );
						} else {
							// Strip away endings like /wp-admin or /wp-login.php.
							$login_url = \explode( '/', $login_url )[0];
						}

						if ( true !== \is_null( $login_url ) && true === is_case_insensitive_match( $login_url, $this->pressable_site->url ) ) {
							$op_username = \trim( \shell_exec( "op item get $login->id --fields label=username" ) );
							if ( empty( $op_username ) ) {
								$op_username = \trim( \shell_exec( "op item get $login->id --fields label=user_login" ) );
							}
							if ( empty( $op_username ) ) {
								$output->writeln( "<error>Cannot find username of 1Password login $login->title (ID $login->id) which is a potential match.</error>" );
								continue;
							}

							if ( true === is_case_insensitive_match( $this->wp_user_email, $op_username ) ) {
								return $login;
							}
							if ( true !== \is_null( $username ) && true === is_case_insensitive_match( $username, $op_username ) ) {
								return $login;
							}

							// Sometimes, the concierge user is stored as the username instead of the email.
							if ( true === is_case_insensitive_match( $this->wp_user_email, 'concierge@wordpress.com' ) ) {
								if ( true === is_case_insensitive_match( 'wpconcierge', $op_username ) ) {
									return $login;
								}
								if ( true === is_case_insensitive_match( 'wordpressconcierge', $op_username ) ) {
									return $login;
								}
							}
						}
					}

					return null;
				},
				$op_items
			)
		);
		if ( 1 < \count( $op_login ) ) {
			$output->writeln( "<error>Multiple 1Password logins found for $this->wp_user_email on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</error>" );
			return false;
		}

		// Create or update the entry.
		if ( 0 === \count( $op_login ) ) {
			$output->writeln( "<info>Creating 1Password login <fg=cyan;options=bold>{$this->pressable_site->displayName}</>.</info>", OutputInterface::VERBOSITY_DEBUG );

			$result = \shell_exec( "op item create --category login --vault 'eysfzwd3el7tlphjd7koc6qfdu' username='$this->wp_user_email' password='$password' --url 'https://{$this->pressable_site->url}' --title '{$this->pressable_site->displayName}'" . ( $input->getOption( 'dry-run' ) ? ' --dry-run' : '' ) );
			if ( empty( $result ) ) {
				$output->writeln( '<error>1Password login could not be created.</error>' );
				return false;
			}
		} else {
			$op_login = \reset( $op_login );
			$output->writeln( "<info>Updating 1Password login <fg=cyan;options=bold>$op_login->title</> (ID $op_login->id).</info>", OutputInterface::VERBOSITY_DEBUG );

			// The URL flag is required because otherwise a login entry valid for prod and staging will update both times with different passwords, and one will be lost...
			$result = \shell_exec( "op item edit $op_login->id password='$password' --url 'https://{$this->pressable_site->url}' --title '{$this->pressable_site->displayName}'" . ( $input->getOption( 'dry-run' ) ? ' --dry-run' : '' ) );
			if ( empty( $result ) ) {
				$output->writeln( '<error>1Password login could not be updated.</error>' );
				return false;
			}
		}

		return true;
	}

	// endregion
}
