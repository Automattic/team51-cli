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
use function Team51\Helpers\get_email_input;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_site_sftp_user_by_email;
use function Team51\Helpers\reset_pressable_site_sftp_user_password;
use function Team51\Helpers\update_deployhq_project_server;

/**
 * CLI command for resetting the SFTP password of users on Pressable sites.
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
	        ->setHelp( 'This command allows you to reset the SFTP password of users on a given Pressable site. If the user is the concierge@wordpress.com user (default), then the DeployHQ configuration is also updated.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the SFTP user password.' )
			->addOption( 'email', null, InputOption::VALUE_OPTIONAL, 'Email of the site SFTP user for which to reset the password. Default is concierge@wordpress.com.' );
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

		// Retrieve the SFTP user email and make sure it exists.
		$sftp_email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );

		$pressable_sftp_user = get_pressable_site_sftp_user_by_email( $pressable_site->id, $sftp_email );
		if ( \is_null( $pressable_sftp_user ) ) {
			$output->writeln( "<error>Pressable site SFTP user $sftp_email not found on $pressable_site->name ($pressable_site->url).</error>" );
			return 1;
		}

		$output->writeln( "<comment>Pressable site SFTP user $pressable_sftp_user->username ($pressable_sftp_user->email) found on $pressable_site->name ($pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

		// Maybe let the user confirm the action.
		if ( ! $input->getOption( 'no-interaction' ) ) {
			$question = new Question( "<question>Reset the SFTP password of $pressable_sftp_user->username ($pressable_sftp_user->email) on $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url)? (y/n)</question> ", 'n' );
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
		$output->writeln( "<comment>New password: $new_pressable_sftp_password</comment>", OutputInterface::VERBOSITY_DEBUG );

		// Update the DeployHQ configuration, if required.
		if ( true === $pressable_sftp_user->owner ) { // The owner account is the one that is used to deploy the site.
			$output->writeln( '<info>SFTP user is project owner. DeployHQ configuration update required...</info>', OutputInterface::VERBOSITY_VERBOSE );

			// Retrieve the DeployHQ project for the site.
			$deployhq_project_permalink = get_deployhq_project_permalink_from_pressable_site( $pressable_site );
			$output->writeln( "<comment>DeployHQ project permalink: $deployhq_project_permalink</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

			$deployhq_project = get_deployhq_project_by_permalink( $deployhq_project_permalink );
			while ( \is_null( $deployhq_project ) ) {
				$output->writeln( "<error>Failed to retrieve DeployHQ project $deployhq_project_permalink.</error>" );
				if ( $input->getOption( 'no-interaction' ) ) {
					return $this->fail_deployhq( $output, $new_pressable_sftp_password );
				}

				// Prompt the user to input the DeployHQ project permalink.
				$question = new Question( '<question>Enter the DeployHQ project permalink or leave empty to exit gracefully:</question> ', 'pP3uZb0b5s' );
				$deployhq_project_permalink = $this->getHelper( 'question' )->ask( $input, $output, $question );
				if ( 'pP3uZb0b5s' === $deployhq_project_permalink ) {
					return $this->fail_deployhq( $output, $new_pressable_sftp_password );
				}

				$output->writeln( "<comment>DeployHQ project permalink: $deployhq_project_permalink</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
				$deployhq_project = get_deployhq_project_by_permalink( $deployhq_project_permalink );
			}

			$output->writeln( "<comment>DeployHQ project found: $deployhq_project->name ($deployhq_project->permalink)</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

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

			$output->writeln( "<comment>DeployHQ server found: $deployhq_server->name ($deployhq_server->identifier)</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

			// Update the DeployHQ server config password.
			$deployhq_server = update_deployhq_project_server(
				$deployhq_project->permalink,
				$deployhq_server->identifier,
				array( // Sending just the 'password' parameter won't work. Experimentally, the protocol type parameter is also required.
					'protocol_type' => 'ssh',
					'password'      => $new_pressable_sftp_password,
				)
			);
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
			$question = new Question( '<question>No email was provided. Do you wish to continue with the default concierge email? (y/n)</question> ', 'n' );
			$answer   = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'y' === $answer ) {
				$email = 'concierge@wordpress.com';
			} else {
				$question = new Question( '<question>Enter the email to reset the SFTP password for:</question> ' );
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
			$question = new Question( '<question>Enter the site ID or URL to reset the SFTP password for:</question> ' );
			$site     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
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
