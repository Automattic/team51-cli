<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\define_console_verbosity;
use function Team51\Helpers\get_deployhq_project_by_permalink;
use function Team51\Helpers\get_deployhq_project_permalink_from_pressable_site;
use function Team51\Helpers\get_deployhq_project_servers;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_site_sftp_user_from_input;
use function Team51\Helpers\get_pressable_site_sftp_users;
use function Team51\Helpers\get_pressable_sites;
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

	/**
	 * The Pressable site to reset the SFTP password on.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The Pressable site SFTP user to reset the password of.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_sftp_user = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Resets the SFTP password of the concierge user or that of a given user for a given site.' )
	        ->setHelp( 'This command allows you to reset the SFTP password of users on a given Pressable site. If the user is the concierge@wordpress.com user (default), then the DeployHQ configuration is also updated.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the SFTP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_OPTIONAL, 'ID, email, or username of the site SFTP user for which to reset the password. Default is concierge@wordpress.com.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but not actually reset the password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		define_console_verbosity( $output->getVerbosity() );

		// Retrieve the site and make sure it exists.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( false !== \is_null( $this->pressable_site ) ) {
			exit; // Exit if the site does not exist.
		}

		$input->setArgument( 'site', $this->pressable_site->id ); // Store the ID of the site in the argument field.

		// Retrieve the SFTP user email and make sure it exists.
		$this->pressable_sftp_user = get_pressable_site_sftp_user_from_input( $input, $output, $this->pressable_site->id, fn() => $this->prompt_user_input( $input, $output ) );
		if ( false !== \is_null( $this->pressable_site ) ) {
			exit; // Exit if the SFTP user does not exist.
		}

		$input->setOption( 'user', $this->pressable_sftp_user->username ); // Store the username of the SFTP user in the option field.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to reset the SFTP password of {$this->pressable_sftp_user->username} (ID {$this->pressable_sftp_user->id}, email {$this->pressable_sftp_user->email}) on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? (y/n)</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<info>Resetting the SFTP password of {$this->pressable_sftp_user->username} (ID {$this->pressable_sftp_user->id}, email {$this->pressable_sftp_user->email}) on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</info>" );

		// Reset SFTP password.
		if ( !$input->getOption( 'dry-run' ) ) {
			$new_pressable_sftp_password = reset_pressable_site_sftp_user_password( $this->pressable_site->id, $this->pressable_sftp_user->username );
			if ( \is_null( $new_pressable_sftp_password ) ) {
				$output->writeln( '<error>Failed to reset SFTP password.</error>' );
				return 1;
			}
		} else {
			$new_pressable_sftp_password = '********';
		}

		$output->writeln( '<fg=green;options=bold>Pressable SFTP password reset.</>' );
		$output->writeln(
			"<comment>New password:</comment> <fg=green;options=bold>$new_pressable_sftp_password</>",
			true === $this->pressable_sftp_user->owner ? OutputInterface::VERBOSITY_DEBUG : OutputInterface::VERBOSITY_NORMAL
		);

		// Update the DeployHQ configuration, if required.
		if ( true === $this->pressable_sftp_user->owner ) { // The owner account is the one that is used to deploy the site.
			$output->writeln( '<info>SFTP user is project owner. DeployHQ configuration update required...</info>', OutputInterface::VERBOSITY_VERBOSE );

			// Retrieve the DeployHQ project for the site.
			$deployhq_project_permalink = get_deployhq_project_permalink_from_pressable_site( $this->pressable_site );
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

			$output->writeln( "<comment>DeployHQ project found: $deployhq_project->name ($deployhq_project->permalink).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

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
				if ( $deployhq_project_server->username === $this->pressable_sftp_user->username ) {
					$deployhq_server = $deployhq_project_server;
					break;
				}
			}

			if ( \is_null( $deployhq_server ) ) {
				$output->writeln( '<error>Failed to find DeployHQ server.</error>' );
				return $this->fail_deployhq( $output, $new_pressable_sftp_password );
			}

			$output->writeln( "<comment>DeployHQ server found: $deployhq_server->name ($deployhq_server->identifier).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

			// Update the DeployHQ server config password.
			if ( !$input->getOption( 'dry-run' ) ) {
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
			}

			$output->writeln( '<fg=green;options=bold>DeployHQ configuration updated.</>' );
		} else {
			$output->writeln( '<info>SFTP user is not project owner. No DeployHQ configuration update required.</info>' );
		}

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
			$question = new Question( '<question>Enter the site ID or URL to reset the SFTP password on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

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
				$question = new Question( '<question>Enter the user ID or email to reset the SFTP password for:</question> ' );
				$question->setAutocompleterValues( \array_map( static fn( object $sftp_user ) => $sftp_user->email, get_pressable_site_sftp_users( $input->getArgument( 'site' ) ) ?? array() ) );

				$email = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
		}

		return $email;
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
