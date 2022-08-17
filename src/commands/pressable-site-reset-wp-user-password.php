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
use function Team51\Helpers\define_console_verbosity;
use function Team51\Helpers\generate_random_password;
use function Team51\Helpers\get_email_input;
use function Team51\Helpers\get_pressable_site_collaborator_by_email;
use function Team51\Helpers\get_pressable_sites;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\get_wpcom_site_user_by_email;
use function Team51\Helpers\reset_pressable_site_collaborator_wp_password;
use function Team51\Helpers\reset_pressable_site_owner_wp_password;
use function Team51\Helpers\set_wpcom_site_user_wp_password;

/**
 * CLI command for resetting the WP password of users on Pressable sites.
 */
final class Pressable_Site_Reset_WP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:reset-site-wp-user-password';

	/**
	 * The Pressable site to reset the WP user password on.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_prod_site = null;

	/**
	 * The email of the WP user to reset the password for.
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
		$this->setDescription('Resets the WP password of the concierge user or that of a given user for a given site and all of its development clones.')
			->setHelp( 'This command allows you to reset the WP password of users on a given Pressable site and all of its development clones. Finally, it attempts to update the 1Password value as well.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the WP user password.' )
	        ->addOption( 'user', 'u', InputOption::VALUE_OPTIONAL, 'Email of the site WP user for which to reset the password. Default is concierge@wordpress.com.' )
		    ->addOption( 'force', null, InputOption::VALUE_NONE, 'Force the reset of the WP user password on all development sites even if out-of-sync with the other sites.' );
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
		$this->output_sites_tree( $output, false );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Do you want to proceed with resetting the WP user password of $this->wp_user_email on all the sites listed above? (y/n)</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<info>Resetting the WP user password of $this->wp_user_email on {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}) and all of its development clones.</info>" );

		// Reset the WP user password on the main dev site.
		$pressable_main_dev_node = &$this->find_main_dev_site_node();
		if ( ! \is_null( $pressable_main_dev_node ) ) {
			$result = $this->reset_wp_user_password( $input, $output, $pressable_main_dev_node['site_object'], $new_wp_user_password );
			if ( true === $result ) {
				$pressable_main_dev_node['new_password'] = $new_wp_user_password;
			}
		} else {
			$output->writeln( '<comment>No main dev site found.</comment>' );
		}

		// Reset the WP user password on the production site.
		$result = $this->reset_wp_user_password( $input, $output, $this->pressable_prod_site, $new_wp_user_password );
		if ( true === $result ) {
			$this->related_pressable_sites[0][ $this->pressable_prod_site->id ]['new_password'] = $new_wp_user_password;
		} else {
			// If we fail to reset the password on the production site, then what's the point of doing it on the development sites? We definitely shouldn't update it in 1Password for now.
			$output->writeln( "<error>Failed to reset the password on the production site {$this->pressable_prod_site->displayName} (ID {$this->pressable_prod_site->id}, URL {$this->pressable_prod_site->url}). Here is a summary of resetted passwords:</error>" );
			$this->output_sites_tree( $output, true );
			return 1;
		}

		// Reset the WP user password on the rest of the development sites.
		foreach ( $this->related_pressable_sites as $level => &$nodes ) {
			if ( 0 === $level ) {
				continue; // Skip the production site.
			}

			foreach ( $nodes as &$node ) {
				if ( $pressable_main_dev_node && $pressable_main_dev_node['site_object']->id === $node['site_object']->id ) {
					continue; // Skip the main dev site.
				}

				$new_wp_user_password = $this->related_pressable_sites[0][ $this->pressable_prod_site->id ]['new_password']; // Attempt to use the same password as the production site.
				$result = $this->reset_wp_user_password( $input, $output, $node['site_object'], $new_wp_user_password );
				if ( true === $result ) {
					$node['new_password'] = $new_wp_user_password;
				}
			}
			unset( $node );
		}
		unset( $nodes );

		$this->output_sites_tree( $output,true );
		return 0;
	}

	// endregion

	// region HELPERS

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
			$question = new Question( '<question>Enter the site ID or URL to reset the WP password on:</question> ' );
			$question->setAutocompleterValues( \array_filter( \array_map( static fn( $site ) => empty( $site->clonedFromId ) ? $site->url : false, get_pressable_sites() ?? array() ) ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

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
				$question = new Question( '<question>Enter the user email to reset the WP password for:</question> ' );
				$email    = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
		}

		return $email;
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
				)
			)
		);

		// Identify the related sites by level.
		$all_sites = get_pressable_sites();

		do {
			$has_next_level = false;
			$current_level  = count( $this->related_pressable_sites );

			foreach ( \array_keys( $this->related_pressable_sites[ $current_level - 1 ] ) as $parent_site_id ) {
				foreach ( $all_sites as $maybe_clone_site ) {
					if ( $maybe_clone_site->clonedFromId === $parent_site_id ) {
						$site_node = array( 'site_object' => $maybe_clone_site, 'temporary' => true, 'new_password' => null, );
						if ( 1 === $current_level && '-development' === \substr( $maybe_clone_site->name, -1 * \strlen( '-development' ) ) ) {
							$site_node['temporary'] = false;
						}

						$this->related_pressable_sites[ $current_level ][ $maybe_clone_site->id ] = $site_node;
						$has_next_level = true;
					}
				}
			}
		} while ( $has_next_level );
	}

	/**
	 * Outputs the related sites in a table format.
	 *
	 * @param   OutputInterface $output                 The output interface.
	 * @param   bool            $include_new_password   Whether to include the new password in the table.
	 *
	 * @return  void
	 */
	private function output_sites_tree( OutputInterface $output, bool $include_new_password ): void {
		$table = new Table( $output );

		$headers = array( 'ID', 'Name', 'URL', 'Level', 'Parent ID' );
		if ( true === $include_new_password ) {
			$headers[]     = 'New password';
			$main_password = \reset( $this->related_pressable_sites[0] )['new_password'] ?? '--';
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

				if ( true === $include_new_password ) {
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
	 * Sets the WP password of the given user on the given site. The user is identified by its email.
	 *
	 * @param   InputInterface      $input              The input interface.
	 * @param   OutputInterface     $output             The output interface.
	 * @param   object              $pressable_site     The Pressable site.
	 * @param   string|null         $password           The new password.
	 *
	 * @return  bool|null   Whether the password was successfully set. Null means that an API attempt wasn't even made (most likely, no user found).
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function reset_wp_user_password( InputInterface $input, OutputInterface $output, object $pressable_site, ?string &$password = null ): ?bool {
		$result = null;

		/* @noinspection PhpUnhandledExceptionInspection */
		$new_password = $password ?? generate_random_password();

		// First attempt to set the password via the WPCOM/Jetpack API.
		$wpcom_user = get_wpcom_site_user_by_email( $pressable_site->url, $this->wp_user_email );
		if ( false === \is_null( $wpcom_user ) ) { // User found on site and Jetpack connection is active.
			$output->writeln( "<comment>WP user $wpcom_user->name (email $wpcom_user->email) found via the WPCOM API on the site $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERBOSE );
			$output->writeln( "<info>Setting the WP user password for $wpcom_user->name (email $wpcom_user->email) on the site $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url) via the Jetpack API.</info>", OutputInterface::VERBOSITY_VERBOSE );

			$result = set_wpcom_site_user_wp_password( $pressable_site->url, $wpcom_user->ID, $new_password );
		}

		// If the password wasn't set via the WPCOM/Jetpack API, try the Pressable API.
		if ( true !== $result && ( true === \is_null( $password ) || ! empty( $input->getOption( 'force' ) ) ) ) {
			$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $this->wp_user_email );
			if ( false === \is_null( $pressable_sftp_user ) && true === $pressable_sftp_user->owner ) { // SFTP user found on site and is a site owner.
				$output->writeln( "<info>Resetting the WP user password for the owner $this->wp_user_email of the site $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</info>" );

				// Pressable has a special endpoint for owners where, if the WP user does not exist, it will be created instead of the request failing.
				$new_password = reset_pressable_site_owner_wp_password( $pressable_site->id );
				$result       = ( true !== \is_null( $new_password ) );
			} else {
				$pressable_collaborator = get_pressable_site_collaborator_by_email( $pressable_site->id, $this->wp_user_email );
				if ( true !== \is_null( $pressable_collaborator ) ) {
					$output->writeln( "<info>Attempting a WP user password reset for Pressable collaborator $pressable_collaborator->wpUsername (email $pressable_collaborator->email) on the site $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

					$new_password = reset_pressable_site_collaborator_wp_password( $pressable_site->id, $pressable_collaborator->id );
					$result       = ( true !== \is_null( $new_password ) );
				}
			}
		}

		$password = $result ? $new_password : $password;
		return $result;
	}

	// endregion
}
