<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
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

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription('Resets the WP password of the concierge user or that of a given user for a given site.')
			->setHelp( 'This command allows you to reset the WP password of users on a given Pressable site. If it\'s a production site, then it attempts to update all of its development clones too. Finally, it attempts to update the 1Password value as well.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the WP user password.' )
	        ->addOption( 'email', null, InputOption::VALUE_OPTIONAL, 'Email of the site WP user for which to reset the password. Default is concierge@wordpress.com.' )
		    ->addOption( 'force', null, InputOption::VALUE_NONE, 'Force the reset of the WP user password on all development sites even if out-of-sync with the other sites.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		// Retrieve the WP user email.
		$wp_user_email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );

		// Retrieve the site and make sure it exists.
		$pressable_production_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $pressable_production_site ) ) {
			return 1;
		}

		// Retrieve all related Pressable sites and ask the user to confirm.
		$related_pressable_sites   = $this->compile_sites_tree( $pressable_production_site );
		$pressable_production_site = \reset( $related_pressable_sites[0] );

		$this->output_sites_tree( $output, $related_pressable_sites, false );
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$question = new Question( '<question>Do you want to proceed with resetting the WP user password on all the sites listed above? (y/n)</question> ', 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' !== $answer ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit;
			}
		}

		// Reset the WP user password on the main dev site.
		$pressable_main_dev_site = &$this->find_main_dev_site_node( $related_pressable_sites );
		if ( ! \is_null( $pressable_main_dev_site ) ) {
			$result = $this->reset_wp_user_password( $input, $output, $pressable_main_dev_site['site'], $wp_user_email, $new_wp_user_password );
			if ( true === $result ) {
				$pressable_main_dev_site['new_password'] = $new_wp_user_password;
			}
		} else {
			$output->writeln( '<comment>No main dev site found.</comment>' );
		}

		// Reset the WP user password on the production site.
		$result = $this->reset_wp_user_password( $input, $output, $pressable_production_site['site'], $wp_user_email, $new_wp_user_password );
		if ( true === $result ) {
			$pressable_production_site['new_password'] = $new_wp_user_password;
			$related_pressable_sites[0][ $pressable_production_site['site']->id ] = $pressable_production_site;
		}

		$this->output_sites_tree( $output, $related_pressable_sites, true );
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
	private function prompt_email_input( InputInterface $input, OutputInterface $output ): string {
		if ( $input->getOption( 'no-interaction' ) ) {
			$email = 'concierge@wordpress.com';
		} else {
			$question = new Question( 'No email was provided. Do you wish to continue with the default concierge email? (y/n) ', 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' === $answer ) {
				$email = 'concierge@wordpress.com';
			} else {
				$question = new Question( 'Enter the email to reset the WP password for: ' );
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
			$question = new Question( 'Enter the site ID or URL to reset the WP password for: ' );
			$site     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Compiles a tree-like structure of cloned Pressable sites for a given site node.
	 *
	 * @param   object  $site   The Pressable site.
	 *
	 * @return  array
	 */
	private function compile_sites_tree( object $site ): array {
		$tree = array();

		// Identify the root production site first.
		$production_site = $site;
		while ( ! empty( $production_site->clonedFromId ) ) {
			$production_site = get_pressable_site_by_id( $production_site->clonedFromId );
		}

		$tree[0] = array(
			$production_site->id => array(
				'site'          => $production_site,
				'new_password'  => null,
			)
		);

		// Identify the related sites by level.
		$all_sites = get_pressable_sites();

		do {
			$has_next_level = false;
			$current_level  = count( $tree );

			foreach ( \array_keys( $tree[ $current_level - 1 ] ) as $parent_site_id ) {
				foreach ( $all_sites as $maybe_clone_site ) {
					if ( $maybe_clone_site->clonedFromId === $parent_site_id ) {
						$site_node = array( 'site' => $maybe_clone_site, 'temporary' => true, 'new_password' => null, );
						if ( 1 === $current_level && '-development' === \substr( $maybe_clone_site->name, -1 * \strlen( '-development' ) ) ) {
							$site_node['temporary'] = false;
						}

						$tree[ $current_level ][ $maybe_clone_site->id ] = $site_node;
						$has_next_level = true;
					}
				}
			}
		} while ( $has_next_level );

		return $tree;
	}

	/**
	 * Outputs the related sites in a table format.
	 *
	 * @param   OutputInterface $output                 The output interface.
	 * @param   array           $tree                   The tree of related sites.
	 * @param   bool            $include_new_password   Whether to include the new password in the table.
	 *
	 * @return  void
	 */
	private function output_sites_tree( OutputInterface $output, array $tree, bool $include_new_password ): void {
		$table = new Table( $output );

		$headers = array( 'ID', 'Name', 'URL', 'Level', 'Parent ID' );
		if ( true === $include_new_password ) {
			$headers[]     = 'New password';
			$main_password = \reset( $tree[0] )['new_password'] ?? '--';
		}

		$table->setHeaderTitle( 'Related Pressable sites' );
		$table->setHeaders( $headers );
		foreach ( $tree as $level => $nodes ) {
			foreach ( $nodes as $node ) {
				$is_main_dev_site = ( 1 === $level && false === $node['temporary'] );

				$site_row = array(
					$node['site']->id,
					$node['site']->name . ( $is_main_dev_site ? ' <info>(main dev)</info>' : '' ),
					$node['site']->url,
					$level,
					$node['site']->clonedFromId ?: '--',
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

			if ( $level < \count( $tree ) - 1 ) {
				$table->addRow( new TableSeparator() );
			}
		}

		$table->setStyle('box-double');
		$table->render();
	}

	/**
	 * Returns the main dev site for a given related sites tree.
	 *
	 * @param   array   $tree   The tree of related sites.
	 *
	 * @return  object|null
	 */
	private function &find_main_dev_site_node( array &$tree ): ?array {
		$return = null;

		if ( 1 < \count( $tree ) ) {
			foreach ( $tree[1] as &$node ) {
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
	 * @param   string              $wp_user_email      The WP user email.
	 * @param   string|null         $password           The new password.
	 *
	 * @return  bool|null   Whether the password was successfully set. Null means that an API attempt wasn't even made (most likely, no user found).
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function reset_wp_user_password( InputInterface $input, OutputInterface $output, object $pressable_site, string $wp_user_email, ?string &$password = null ): ?bool {
		$result = null;

		/* @noinspection PhpUnhandledExceptionInspection */
		$new_password = $password ?? generate_random_password();

		// First attempt to set the password via the WPCOM/Jetpack API.
		$wpcom_user = get_wpcom_site_user_by_email( $pressable_site->url, $wp_user_email );
		if ( false === \is_null( $wpcom_user ) ) { // User found on site and Jetpack connection is active.
			$output->writeln( "<comment>WP user $wpcom_user->name ($wpcom_user->email) found via the WPCOM API on the site $pressable_site->name ($pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERBOSE );
			$output->writeln( "<info>Setting the WP user password for $wpcom_user->name ($wpcom_user->email) on the site $pressable_site->name ($pressable_site->url) via the Jetpack API.</info>", OutputInterface::VERBOSITY_VERBOSE );

			$result = set_wpcom_site_user_wp_password( $pressable_site->url, $wpcom_user->ID, $new_password );
		}

		// If the password wasn't set via the WPCOM/Jetpack API, try the Pressable API.
		if ( true !== $result && ( true === \is_null( $password ) || ! empty( $input->getOption( 'force' ) ) ) ) {
			$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $wp_user_email );
			if ( false === \is_null( $pressable_sftp_user ) && true === $pressable_sftp_user->owner ) { // SFTP user found on site and is a site owner.
				$output->writeln( "<info>Resetting the WP user password for the owner $wp_user_email of the site $pressable_site->name ($pressable_site->url).</info>" );

				// Pressable has a special endpoint for owners where, if the WP user does not exist, it will be created instead of the request failing.
				$new_password = reset_pressable_site_owner_wp_password( $pressable_site->id );
				$result       = ( true !== \is_null( $new_password ) );
			} else {
				$pressable_collaborator = get_pressable_site_collaborator_by_email( $pressable_site->id, $wp_user_email );
				if ( true !== \is_null( $pressable_collaborator ) ) {
					$output->writeln( "<info>Attempting a WP user password reset for Pressable collaborator $pressable_collaborator->wpUsername ($pressable_collaborator->email) on the site $pressable_site->name ($pressable_site->url) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

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
