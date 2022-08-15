<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\get_email_input;
use function Team51\Helpers\get_wpcom_site;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\get_wpcom_site_user_by_email;
use function Team51\Helpers\reset_pressable_site_collaborator_wp_password;
use function Team51\Helpers\reset_pressable_site_owner_wp_password;
use function Team51\Helpers\reset_wpcom_site_user_wp_password;

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
		     ->addOption( 'email', null, InputOption::VALUE_OPTIONAL, 'Email of the site WP user for which to reset the password. Default is concierge@wordpress.com.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		// Retrieve the site and make sure it exists.
		$pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $pressable_site ) ) {
			return 1;
		}

		// If this is a development site, prompt the user to confirm.
		if ( ! empty( $pressable_site->clonedFromId ) && ! $input->getOption( 'no-interaction' ) ) {
			$production_site = get_pressable_site_by_id( $pressable_site->clonedFromId );
			if ( \is_null( $production_site ) ) {
				$output->writeln( "<comment>The website $pressable_site->name ($pressable_site->url) looks like a clone of site $pressable_site->clonedFromId which could not be found.</comment>" );
			} else {
				$output->writeln( "<comment>The website $pressable_site->name ($pressable_site->url) is a development site of $production_site->name ($production_site->url).</comment>" );
			}

			$question = new Question( "Do you wish to continue resetting the WP user password for this website? (y/n) ", 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' !== $answer ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit;
			}
		}

		// Retrieve the WP user email.
		$wp_email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );

		// Try resetting the WP user password.
		// There are a few different ways to do this based on what the user is and whether Jetpack is connected or not.
		$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $wp_email );
		if ( true !== \is_null( $pressable_sftp_user ) && true === $pressable_sftp_user->owner ) { // The email belongs to the Pressable site owner.
			$output->writeln( "<info>Resetting the WP user password for the Pressable site owner $wp_email.</info>" );

			// Pressable has a special endpoint for owners where, if the WP user does not exist, it will be created instead of the request failing.
			$new_password = reset_pressable_site_owner_wp_password( $pressable_site->id );
		} else { // The email does not belong to the Pressable site owner, so we first need to validate that a WP user account even exists.
			$output->writeln( "<comment>Checking if the WP user $wp_email exists on the site $pressable_site->name ($pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERBOSE );

			// Try WPCOM first. If there is a Jetpack connection, this is the best way to check existing WP users.
			$wpcom_user = get_wpcom_site_user_by_email( $pressable_site->url, $wp_email );
			if ( false === \is_null( $wpcom_user ) ) { // User found. Reset using Jetpack!
				$output->writeln( "<comment>WP user $wpcom_user->name ($wpcom_user->email) found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERBOSE );
				$output->writeln( "<info>Resetting the WP user password for $wpcom_user->name ($wpcom_user->email) on the site $pressable_site->name ($pressable_site->url) via the Jetpack API.</info>", OutputInterface::VERBOSITY_VERBOSE );

				$wpcom_site   = get_wpcom_site( $pressable_site->url );
				$new_password = reset_wpcom_site_user_wp_password( $wpcom_site->ID, $wpcom_user->ID );
			} elseif ( false === \is_null( $pressable_sftp_user ) ) { // User not found, but they are a collaborator on the site. Attempt Pressable API reset.
				$output->writeln( "<comment>WP user $pressable_sftp_user->email not found via the WPCOM API.</comment>", OutputInterface::VERBOSITY_VERBOSE );
				$output->writeln( "<info>Attempting a WP user password reset for Pressable collaborator $pressable_sftp_user->username ($pressable_sftp_user->email) on the site $pressable_site->name ($pressable_site->url) via the Pressable API.</info>", OutputInterface::VERBOSITY_VERBOSE );

				$new_password = reset_pressable_site_collaborator_wp_password( $pressable_site->id, $pressable_sftp_user->id );
			} else { // User not found.
				$output->writeln( "<error>The WP user $wp_email could not be found on the site $pressable_site->name ($pressable_site->url).</error>" );
				return 1;
			}
		}

		if ( true === \is_null( $new_password ) ) {
			$output->writeln( "<error>The WP user password could not be reset.</error>" );
			return 1;
		}

		$output->writeln( "<info>The WP user password for $wp_email on the site $pressable_site->name ($pressable_site->url) has been reset to $new_password.</info>" );

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

	// endregion
}
