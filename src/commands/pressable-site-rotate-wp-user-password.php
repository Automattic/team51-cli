<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\decode_json_content;
use function Team51\Helpers\define_console_verbosity;
use function Team51\Helpers\generate_random_password;
use function Team51\Helpers\get_email_input;
use function Team51\Helpers\get_pressable_site_collaborator_by_email;
use function Team51\Helpers\get_pressable_sites;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\get_wpcom_site_user_by_email;
use function Team51\Helpers\is_case_insensitive_match;
use function Team51\Helpers\reset_pressable_site_collaborator_wp_password;
use function Team51\Helpers\reset_pressable_site_owner_wp_password;
use function Team51\Helpers\set_wpcom_site_user_wp_password;

/**
 * CLI command for rotating the WP password of users on Pressable sites.
 */
final class Pressable_Site_Rotate_WP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-wp-user-password';

	/**
	 * The Pressable site to rotate the WP user password on.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_prod_site = null;

	/**
	 * The email of the WP user to rotate the password for.
	 *
	 * @var string|null
	 */
	protected ?string $wp_user_email = null;

	/**
	 * The Pressable site and all of its development clones in a tree-like structure.
	 *
	 * @var array|null
	 */
	protected ?array $related_pressable_sites = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription('Rotates the WP password of the concierge user or that of a given user for a given site and all of its development clones.')
			->setHelp( 'This command allows you to rotate the WP password of users on a given Pressable site and all of its development clones. Finally, it attempts to update the 1Password value as well.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to rotate the WP user password.' )
	        ->addOption( 'user', 'u', InputOption::VALUE_OPTIONAL, 'Email of the site WP user for which to rotate the password. Default is concierge@wordpress.com.' )
		    ->addOption( 'force', null, InputOption::VALUE_NONE, 'Force the rotation of the WP user password on all sites even if out-of-sync with the other sites.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current WP user password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		define_console_verbosity( $output->getVerbosity() );

		// Retrieve the WP user email.
		$this->wp_user_email = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );

		// Retrieve the site and make sure it exists.
		$this->pressable_prod_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( false !== \is_null( $this->pressable_prod_site ) ) {
			exit; // Exit if the site does not exist.
		}

		// Ensure we're working with the production site and compile the tree of related sites.
		while ( ! empty( $this->pressable_prod_site->clonedFromId ) ) {
			$this->pressable_prod_site = get_pressable_site_by_id( $this->pressable_prod_site->clonedFromId );
		}

		$this->compile_sites_tree();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$this->output_sites_tree( $output, false );
		$question = new ConfirmationQuestion( "<question>Do you want to proceed with rotating the WP user password of $this->wp_user_email on all the sites listed above? (y/n)</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<info>Rotating the WP user password of $this->wp_user_email on {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}) and all of its development clones.</info>" );

		// Rotate the WP user password on the main dev site.
		$pressable_main_dev_node = &$this->find_main_dev_site_node();
		if ( ! \is_null( $pressable_main_dev_node ) ) {
			$result = $this->change_wp_user_password( $input, $output, $pressable_main_dev_node['site_object'], $new_wp_user_password );
			if ( true === $result ) {
				$output->writeln( '<fg=green;options=bold>Main dev site WP password rotated.</>' );
				$pressable_main_dev_node['new_password'] = $new_wp_user_password;
			} else {
				$output->writeln( '<fg=red;options=bold>Main dev site WP password failed to rotate.</>' );
			}
		} else {
			$output->writeln( '<comment>No main dev site found.</comment>' );
		}

		// Rotate the WP user password on the production site.
		$result = $this->change_wp_user_password( $input, $output, $this->pressable_prod_site, $new_wp_user_password );
		if ( true === $result ) {
			$output->writeln( '<fg=green;options=bold>Production site WP password rotated.</>' );
			$this->related_pressable_sites[0][ $this->pressable_prod_site->id ]['new_password'] = $new_wp_user_password;
		} else {
			// If we fail to rotate the password on the production site, then what's the point of doing it on the development sites? We definitely shouldn't update it in 1Password for now.
			$output->writeln( '<fg=red;options=bold>Production site WP password failed to rotate. Here is a summary of rotated passwords:</>' );
			$this->output_sites_tree( $output, true );
			return 1;
		}

		// Rotate the WP user password on the rest of the development sites.
		foreach ( $this->related_pressable_sites as $level => &$nodes ) {
			if ( 0 === $level ) {
				continue; // Skip the production site.
			}

			foreach ( $nodes as &$node ) {
				if ( $pressable_main_dev_node && $pressable_main_dev_node['site_object']->id === $node['site_object']->id ) {
					continue; // Skip the main dev site.
				}

				$new_wp_user_password = $this->related_pressable_sites[0][ $this->pressable_prod_site->id ]['new_password']; // Attempt to use the same password as the production site.
				$result               = $this->change_wp_user_password( $input, $output, $node['site_object'], $new_wp_user_password );
				if ( true === $result ) {
					$output->writeln( "<fg=green;options=bold>{$node['site_object']->displayName} WP password rotated.</>" );
					$node['new_password'] = $new_wp_user_password;
				}
			}
			unset( $node );
		}
		unset( $nodes );

		// Update the passwords in 1Password and output the end result.
		$this->update_1password_logins( $input, $output );
		$this->output_sites_tree( $output,true );

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
			$question = new ConfirmationQuestion( '<question>No user was provided. Do you wish to continue with the default concierge user? (y/n)</question> ', false );
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
	 * Prompts the user for a site if not in 'no-interaction' mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$question = new Question( '<question>Enter the site ID or URL to rotate the WP password on:</question> ' );
			$question->setAutocompleterValues( \array_filter( \array_map( static fn( $site ) => empty( $site->clonedFromId ) ? $site->url : false, get_pressable_sites() ?? array() ) ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Compiles a tree-like structure of cloned Pressable sites for a given site node.
	 *
	 * @return  void
	 */
	private function compile_sites_tree(): void {
		// Initialize the tree with the production site.
		$this->related_pressable_sites = array(
			0 => array(
				$this->pressable_prod_site->id => array(
					'site_object'   => $this->pressable_prod_site,
					'new_password'  => null,
					'1password'     => null,
				)
			)
		);

		// Identify the related sites by level.
		$all_sites = get_pressable_sites();

		do {
			$has_next_level = false;
			$current_level  = \count( $this->related_pressable_sites );

			foreach ( \array_keys( $this->related_pressable_sites[ $current_level - 1 ] ) as $parent_site_id ) {
				foreach ( $all_sites as $maybe_clone_site ) {
					if ( $maybe_clone_site->clonedFromId === $parent_site_id ) {
						$site_node = array(
							'site_object'  => $maybe_clone_site,
							'temporary'    => true,
							'new_password' => null,
							'1password'    => null,
						);
						if ( 1 === $current_level && '-development' === \substr( $maybe_clone_site->name, -1 * \strlen( '-development' ) ) ) {
							$site_node['temporary'] = false;
						}

						$this->related_pressable_sites[ $current_level ][ $maybe_clone_site->id ] = $site_node;
						$has_next_level = true;
					}
				}
			}
		} while ( true === $has_next_level );
	}

	/**
	 * Outputs the related sites in a table format.
	 *
	 * @param   OutputInterface $output                 The output interface.
	 * @param   bool            $include_password_info  Whether to include the new password information or not.
	 *
	 * @return  void
	 */
	private function output_sites_tree( OutputInterface $output, bool $include_password_info ): void {
		$table = new Table( $output );

		$headers = array( 'ID', 'Name', 'URL', 'Level', 'Parent ID' );
		if ( true === $include_password_info ) {
			$headers       = \array_merge( $headers, array( 'New password' ) );
			$main_password = $this->get_production_site_new_password() ?? '--';
		}

		$table->setHeaderTitle( 'Related Pressable sites' );
		$table->setHeaders( $headers );
		foreach ( $this->related_pressable_sites as $level => $nodes ) {
			foreach ( $nodes as $node ) {
				$is_main_dev_site = ( 1 === $level && false === $node['temporary'] );

				$site_row = array(
					$node['site_object']->id,
					$node['site_object']->name . ( $is_main_dev_site ? ' <info>(main dev)</info>' : '' ),
					$node['site_object']->url,
					$level,
					$node['site_object']->clonedFromId ?: '--',
				);

				if ( true === $include_password_info ) {
					$node['new_password'] ??= '--';
					if ( $main_password !== $node['new_password'] ) {
						$site_row[] = "<error>{$node['new_password']}</error>";
					} else {
						$site_row[] = $node['new_password'];
					}
				}

				$table->addRow( $site_row );
			}

			if ( $level < \count( $this->related_pressable_sites ) - 1 ) {
				$table->addRow( new TableSeparator() );
			}
		}

		$table->setStyle('box-double');
		$table->render();
	}

	/**
	 * Returns a reference to the main dev site.
	 *
	 * @return  object|null
	 */
	private function &find_main_dev_site_node(): ?array {
		$return = null;

		if ( 1 < \count( $this->related_pressable_sites ) ) {
			foreach ( $this->related_pressable_sites[1] as &$node ) {
				if ( false === $node['temporary'] ) {
					$return = &$node;
					break;
				}
			}
			unset( $node );
		}

		return $return;
	}

	/**
	 * Returns the new password for the production site.
	 *
	 * @return  string|null
	 */
	private function get_production_site_new_password(): ?string {
		return \reset( $this->related_pressable_sites[0] )['new_password'];
	}

	/**
	 * Rotates the WP password of the user on a given site.
	 *
	 * @param   InputInterface      $input              The input interface.
	 * @param   OutputInterface     $output             The output interface.
	 * @param   object              $pressable_site     The Pressable site.
	 * @param   string|null         $password           The new password.
	 *
	 * @return  bool|null   Whether the password was successfully set. Null means that an API attempt wasn't even made (most likely, no user found).
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function change_wp_user_password( InputInterface $input, OutputInterface $output, object $pressable_site, ?string &$password = null ): ?bool {
		$output->writeln( "<info>Changing password on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url)</info>" );

		$result = null;

		/* @noinspection PhpUnhandledExceptionInspection */
		$new_password = $password ?? generate_random_password();

		// First attempt to set the password via the WPCOM/Jetpack API.
		$wpcom_user = get_wpcom_site_user_by_email( $pressable_site->url, $this->wp_user_email );
		if ( false === \is_null( $wpcom_user ) ) { // User found on site and Jetpack connection is active.
			$output->writeln( "<comment>WP user $wpcom_user->name (ID $wpcom_user->ID, email $wpcom_user->email) found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
			$output->writeln( "<info>Setting the WP user password for $wpcom_user->name (ID $wpcom_user->ID, email $wpcom_user->email) via the WPCOM API.</info>", OutputInterface::VERBOSITY_VERBOSE );

			if ( ! $input->getOption( 'dry-run' ) ) {
				$result = set_wpcom_site_user_wp_password( $pressable_site->url, $wpcom_user->ID, $new_password );
			} else {
				$output->writeln( "<comment>Dry run: WP user password setting skipped.</comment>", OutputInterface::VERBOSITY_VERBOSE );
				$result = true;
			}
		} else {
			$output->writeln( "<comment>WP user $this->wp_user_email <fg=red;options=bold>NOT</> found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
		}

		// If the password wasn't set via the WPCOM/Jetpack API, maybe try rotating it via the Pressable API.
		if ( true !== $result && ( true === \is_null( $password ) || ! empty( $input->getOption( 'force' ) ) ) ) {
			// Pressable has special endpoints for owners vs collaborators.
			$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $this->wp_user_email );
			if ( false === \is_null( $pressable_sftp_user ) && true === $pressable_sftp_user->owner ) { // SFTP user found on site and is a site owner.
				$output->writeln( "<info>Rotating the WP user password for the Pressable site owner $pressable_sftp_user->username (ID $pressable_sftp_user->id, email $pressable_sftp_user->email) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

				if ( ! $input->getOption( 'dry-run' ) ) {
					$new_password = reset_pressable_site_owner_wp_password( $pressable_site->id );
					$result       = ( true !== \is_null( $new_password ) );
				} else {
					$output->writeln( "<comment>Dry run: WP user password rotation of Pressable site owner skipped.</comment>", OutputInterface::VERBOSITY_VERBOSE );

					/* @noinspection PhpUnhandledExceptionInspection */
					$new_password = generate_random_password();
					$result       = true;
				}
			} else {
				$pressable_collaborator = get_pressable_site_collaborator_by_email( $pressable_site->id, $this->wp_user_email );
				if ( true !== \is_null( $pressable_collaborator ) ) {
					$output->writeln( "<info>Rotating the WP user password for Pressable collaborator $pressable_collaborator->wpUsername (ID $pressable_collaborator->id, email $pressable_collaborator->email) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

					if ( ! $input->getOption( 'dry-run' ) ) {
						$new_password = reset_pressable_site_collaborator_wp_password( $pressable_site->id, $pressable_collaborator->id );
						$result       = ( true !== \is_null( $new_password ) );
					} else {
						$output->writeln( "<comment>Dry run: WP user password rotation of Pressable site collaborator skipped.</comment>", OutputInterface::VERBOSITY_VERBOSE );

						/* @noinspection PhpUnhandledExceptionInspection */
						$new_password = generate_random_password();
						$result       = true;
					}
				} else {
					$output->writeln( "<comment>WP user $this->wp_user_email <fg=red;options=bold>NOT</> found on the site $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url) via the Pressable API.</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
				}
			}
		}

		$password = $result ? $new_password : $password;
		return $result;
	}

	/**
	 * Updates the 1Password entry(-ies) for the WP user.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  void
	 */
	private function update_1password_logins( InputInterface $input, OutputInterface $output ): void {
		$output->writeln( "<info>Updating 1Password production login for $this->wp_user_email on {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}).</info>" );

		$op_items = decode_json_content( \shell_exec( 'op item list --categories login --format json' ) );
		if ( true === \is_null( $op_items ) ) {
			$output->writeln( "<error>1Password logins could not be retrieved.</error>" );
			return;
		}

		// Find main production site login.
		$prod_op_login = \array_filter(
			\array_map(
				function( object $login ) {
					if ( \property_exists( $login, 'urls' ) && \is_array( $login->urls ) ) {
						foreach ( $login->urls as $url ) {
							$url = \parse_url( $url->href, PHP_URL_HOST );
							if ( $url === $this->pressable_prod_site->url ) {
								$login = decode_json_content( \shell_exec( "op item get $login->id --format json" ) );
								if ( is_case_insensitive_match( $this->wp_user_email, $login->fields[0]->value ) ) {
									return $login;
								}

								// Sometimes, the concierge user is stored as the username.
								if ( is_case_insensitive_match( $this->wp_user_email, 'concierge@wordpress.com' ) && is_case_insensitive_match( $login->fields[0]->value, 'wpconcierge' ) ) {
									return $login;
								}
							}
						}
					}

					return false;
				},
				$op_items
			)
		);
		if ( 1 < \count( $prod_op_login ) ) {
			$output->writeln( "<error>Multiple 1Password logins found for $this->wp_user_email on {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}).</error>" );
			return;
		}
		if ( 0 === \count( $prod_op_login ) ) {
			$output->writeln( "<error>1Password login not found for $this->wp_user_email on {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}).</error>" );
			return;
		}

		// Update main production site login.
		$prod_op_login = \reset( $prod_op_login );
		$prod_password = $this->get_production_site_new_password();

		if ( ! $input->getOption( 'dry-run' ) ) {
			\shell_exec( "op item edit $prod_op_login->id password='$prod_password' --title '{$this->pressable_prod_site->displayName}'" );
		} else {
			$output->writeln( "<comment>Dry run: 1Password production login update skipped.</comment>", OutputInterface::VERBOSITY_VERBOSE );
		}

		$output->writeln( "<fg=green;options=bold>1Password production login updated.</>" );
	}

	// endregion
}
