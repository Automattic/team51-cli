<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_by_url;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\reset_pressable_site_sftp_user_password;

/**
 * CLI command for resetting the SFTP password of collaborators on Pressable sites.
 */
final class Pressable_Site_Reset_SFTP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:reset-site-sftp-user-password';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Resets the SFTP password of the concierge user or that of a given user for a given site.' )
	        ->setHelp( 'This command allows you to reset the SFTP password of collaborators on a given Pressable site. If the collaborator is the concierge@wordpress.com user (default), then the DeployHQ configuration is also updated.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the SFTP user password.' )
			->addOption( 'email', null, InputOption::VALUE_OPTIONAL, 'Email of the site SFTP user for which to reset the password. Default is concierge@wordpress.com.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$sftp_email     = $this->get_email_input( $input, $output );
		$site_id_or_url = $this->get_site_input( $input, $output );

		// Retrieve the site to make sure it exists.
		$site = \is_numeric( $site_id_or_url ) ? get_pressable_site_by_id( $site_id_or_url ) : get_pressable_site_by_url( $site_id_or_url );
		if ( \is_null( $site ) ) {
			$output->writeln( '<error>Site not found.</error>' );
			return 1;
		}

		$output->writeln( "<comment>Site found: $site->name ($site->url)</comment>" );

		// Confirm the SFTP user exists on the site.
		$sftp_user = get_pressable_site_sftp_user_by_email( $site->id, $sftp_email );
		if ( \is_null( $sftp_user ) ) {
			$output->writeln( '<error>SFTP user not found.</error>' );
			return 1;
		}

		$output->writeln( "<comment>SFTP user found: $sftp_user->username ($sftp_user->email)</comment>" );

		// Maybe let the user confirm the action.
		if ( $input->getOption( 'no-interaction' ) ) {
			$output->writeln( "<info>Resetting the SFTP password of $sftp_user->username ($sftp_user->email) on $site->displayName (ID $site->id, URL $site->url)</info>" );
		} else {
			$question = new Question( "Reset the SFTP password of $sftp_user->username ($sftp_user->email) on $site->displayName (ID $site->id, URL $site->url)? (y/n) ", 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' !== $answer ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit;
			}
		}

		// Reset SFTP password.
		$new_password = reset_pressable_site_sftp_user_password( $site->id, $sftp_user->username );
		if ( \is_null( $new_password ) ) {
			$output->writeln( '<error>Failed to reset SFTP password.</error>' );
			return 1;
		}

		$output->writeln( '<success>SFTP password reset.</success>' );
		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Retrieves the username from the input or prompts the user for it.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function get_email_input( InputInterface $input, OutputInterface $output ): string {
		$email = $input->getOption( 'email' );

		// If we don't have an email, prompt for one.
		if ( empty( $email ) ) {
			if ( $input->getOption( 'no-interaction' ) ) {
				$email = 'concierge@wordpress.com';
			} else {
				$question = new Question( 'No email was provided. Do you wish to continue with the default concierge email? (y/n) ', 'n' );
				$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
				if ( 'y' === $answer ) {
					$email = 'concierge@wordpress.com';
				} else {
					$question = new Question( 'Enter the email to reset the SFTP password for: ' );
					$email    = $this->getHelper( 'question' )->ask( $input, $output, $question );
				}
			}
		}

		// If we still don't have an email, abort.
		if ( empty( $email ) ) {
			$output->writeln( '<error>No email was provided. Aborting!</error>' );
			exit;
		}

		return $email;
	}

	/**
	 * Retrieves the site from the input or prompts the user for it.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function get_site_input( InputInterface $input, OutputInterface $output ): string {
		$site_id_or_url = $input->getArgument( 'site' );

		// If we don't have a site, prompt for one.
		if ( empty( $site_id_or_url ) && ! $input->getOption( 'no-interaction' ) ) {
			$question       = new Question( 'Enter the site ID or URL to reset the SFTP password for: ' );
			$site_id_or_url = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		// If we still don't have a site, abort.
		if ( empty( $site_id_or_url ) ) {
			$output->writeln( '<error>No site was provided. Aborting!</error>' );
			exit;
		}

		// Strip out everything but the hostname if we have a URL.
		if ( false !== \strpos( $site_id_or_url, 'http' ) ) {
			$site_id_or_url = \parse_url( $site_id_or_url, PHP_URL_HOST );
			if ( false === $site_id_or_url ) {
				$output->writeln( '<error>Invalid URL provided. Aborting!</error>' );
				exit;
			}
		}

		return $site_id_or_url;
	}

	// endregion
}
