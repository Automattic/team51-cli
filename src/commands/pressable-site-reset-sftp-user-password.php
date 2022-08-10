<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\get_deployhq_project_by_permalink;
use function Team51\Helpers\get_deployhq_project_permalink_from_pressable_site;
use function Team51\Helpers\get_deployhq_project_servers;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_by_url;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\reset_pressable_site_sftp_user_password;
use function Team51\Helpers\update_deployhq_project_server;

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
		$pressable_site = \is_numeric( $site_id_or_url ) ? get_pressable_site_by_id( $site_id_or_url ) : get_pressable_site_by_url( $site_id_or_url );
		if ( \is_null( $pressable_site ) ) {
			$output->writeln( '<error>Pressable site not found.</error>' );
			return 1;
		}

		$output->writeln( "<comment>Pressable site found: $pressable_site->name ($pressable_site->url)</comment>" );

		// Confirm the SFTP user exists on the site.
		$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $sftp_email );
		if ( \is_null( $pressable_sftp_user ) ) {
			$output->writeln( '<error>Pressable site SFTP user not found.</error>' );
			return 1;
		}

		$output->writeln( "<comment>Pressable site SFTP user found: $pressable_sftp_user->username ($pressable_sftp_user->email)</comment>" );

		// Maybe let the user confirm the action.
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$question = new Question( "Reset the SFTP password of $pressable_sftp_user->username ($pressable_sftp_user->email) on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url)? (y/n) ", 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' !== $answer ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit;
			}
		}

		$output->writeln( "<info>Resetting the SFTP password of $pressable_sftp_user->username ($pressable_sftp_user->email) on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</info>" );

		// Reset SFTP password.
		$new_pressable_sftp_password = reset_pressable_site_sftp_user_password( $pressable_site->id, $pressable_sftp_user->username );
		if ( \is_null( $new_pressable_sftp_password ) ) {
			$output->writeln( '<error>Failed to reset SFTP password.</error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>Pressable SFTP password reset.</>' );

		// Update the DeployHQ configuration, if required.
		if ( true === $pressable_sftp_user->owner ) { // The owner account is the one that is used to deploy the site.
			$output->writeln( '<info>Updating DeployHQ configuration.</info>' );

			// Retrieve the DeployHQ project for the site.
			$deployhq_project_permalink = get_deployhq_project_permalink_from_pressable_site( $pressable_site );
			$output->writeln( "<comment>DeployHQ project permalink: $deployhq_project_permalink</comment>" );

			$deployhq_project = get_deployhq_project_by_permalink( $deployhq_project_permalink );
			while ( \is_null( $deployhq_project ) ) {
				$output->writeln( '<error>Failed to retrieve DeployHQ project.</error>' );
				if ( $input->getOption( 'no-interaction' ) ) {
					return $this->fail_deployhq( $output, $new_pressable_sftp_password );
				}

				// Prompt the user to input the DeployHQ project permalink.
				$question = new Question( 'Enter the DeployHQ project permalink or leave empty to exit gracefully: ', 'pP3uZb0b5s' );
				$deployhq_project_permalink = $this->getHelper( 'question' )->ask( $input, $output, $question );
				if ( 'pP3uZb0b5s' === $deployhq_project_permalink ) {
					return $this->fail_deployhq( $output, $new_pressable_sftp_password );
				}

				$output->writeln( "<comment>DeployHQ project permalink: $deployhq_project_permalink</comment>" );
				$deployhq_project = get_deployhq_project_by_permalink( $deployhq_project_permalink );
			}

			$output->writeln( "<comment>DeployHQ project found: $deployhq_project->name ($deployhq_project->permalink)</comment>" );

			// Find the correct DeployHQ server config for the site.
			$deployhq_project_servers = get_deployhq_project_servers( $deployhq_project->permalink );
			if ( empty( $deployhq_project_servers ) ) { // Covers the case where the project has no servers.
				$output->writeln( '<error>Failed to retrieve DeployHQ servers or no servers configured.</error>' );
				return $this->fail_deployhq( $output, $new_pressable_sftp_password );
			}

			$deployhq_server = null;
			foreach ( $deployhq_project_servers as $deployhq_project_server ) {
				// Match the DeployHQ server config username with the Pressable SFTP username.
				// This is the least error-prone way to find the correct server.
				if ( $deployhq_project_server->username === $pressable_sftp_user->username ) {
					$deployhq_server = $deployhq_project_server;
					break;
				}
			}

			if ( \is_null( $deployhq_server ) ) {
				$output->writeln( '<error>Failed to find DeployHQ server.</error>' );
				return $this->fail_deployhq( $output, $new_pressable_sftp_password );
			}

			$output->writeln( "<comment>DeployHQ server found: $deployhq_server->name ($deployhq_server->identifier)</comment>" );

			// Update the DeployHQ server config password.
			$deployhq_server = update_deployhq_project_server( $deployhq_project->permalink, $deployhq_server->identifier, array( 'password' => $new_pressable_sftp_password ) );
			if ( \is_null( $deployhq_server ) ) {
				$output->writeln( '<error>Failed to update DeployHQ server.</error>' );
				return $this->fail_deployhq( $output, $new_pressable_sftp_password );
			}

			$output->writeln( '<fg=green;options=bold>DeployHQ configuration updated.</>' );
		}

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

	/**
	 * Outputs relevant information to the user if updating the DeployHQ configuration fails.
	 *
	 * @param   OutputInterface     $output         The output interface.
	 * @param   string              $sftp_password  The new SFTP password.
	 *
	 * @return  int     Status code to fail the command with.
	 */
	private function fail_deployhq( OutputInterface $output, string $sftp_password ): int {
		$output->writeln( "<info>If needed, please update the DeployHQ server password manually to: $sftp_password</info>" );
		return 1;
	}

	// endregion
}
