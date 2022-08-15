<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use function Team51\Helpers\get_email_input;
use function Team51\Helpers\get_pressable_site_by_id;
use function Team51\Helpers\get_pressable_site_collaborator_by_email;
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_sites;

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

		// Retrieve the WP user email and make sure it exists.
		$wp_email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );

		$pressable_collaborator = get_pressable_site_collaborator_by_email( $pressable_site->id, $wp_email );
		if ( \is_null( $pressable_collaborator ) ) {
			$output->writeln( "<comment>The WP user $wp_email is not a collaborator on $pressable_site->name ($pressable_site->url).</comment>" );
			return 1;
		}

		$output->writeln( "<comment>Pressable site collaborator $pressable_collaborator->name ($pressable_collaborator->email) found on $pressable_site->name ($pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );

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
