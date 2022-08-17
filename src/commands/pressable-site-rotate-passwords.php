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
use function Team51\Helpers\get_pressable_site_from_input;
use function Team51\Helpers\get_pressable_sites;

/**
 * CLI command for rotating the SFTP and WP user passwords of the concierge user on one or all Pressable sites.
 */
final class Pressable_Site_Rotate_Passwords extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:rotate-site-passwords';

	/**
	 * The Pressable site to reset the SFTP password on.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Rotates the SFTP and WP user passwords of the concierge user on one or all Pressable sites.' )
	        ->setHelp( 'This command rotates the SFTP and WP user passwords of the concierge user on one a given Pressable site or on all sites retrievable through the Pressable API.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site for which to reset the SFTP user password.' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Force the reset of the WP user password on all development sites even if out-of-sync with the other sites.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current passwords. Useful for checking whether a given input is valid.' );
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
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the passwords on {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? (y/n)</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit;
		}
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
			$question = new Question( '<question>Enter the site ID or URL to rotate the passwords on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
