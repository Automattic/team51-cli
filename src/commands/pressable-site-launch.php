<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for launching a given Pressable site.
 */
final class Pressable_Site_Launch extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:launch-site'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The new primary domain for the site.
	 *
	 * @var string|null
	 */
	protected ?string $primary_domain = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Performs various automated actions to ease the launch process of a Pressable site.' )
			->setHelp( 'This command allows you convert a given Pressable staging site into a live site and update its main URL. If any 1Password entries use the old URL, those are updated as well to use the new one.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site which is being launched.' )
			->addArgument( 'domain', InputArgument::REQUIRED, 'The new primary domain of the site.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->pressable_site->id ); // Store the ID of the site in the argument field.

		// Retrieve the given domain. If none is provided, maybe prompt for it.
		$this->primary_domain = $input->getArgument( 'domain' );
		if ( empty( $this->primary_domain ) && $input->isInteractive() ) {
			$question             = new Question( '<question>Enter the new primary domain of the site:</question> ' );
			$this->primary_domain = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		// Perform a few sanity checks on the new primary domain.
		if ( ! empty( $this->primary_domain ) && false !== \strpos( $this->primary_domain, 'http' ) ) {
			$this->primary_domain = \parse_url( $this->primary_domain, PHP_URL_HOST );
		}
		if ( false === \strpos( $this->primary_domain, '.', 1 ) ) {
			$this->primary_domain = null;
		}

		// If we don't have a valid primary domain, we can't continue.
		if ( empty( $this->primary_domain ) ) {
			$output->writeln( '<error>No domain was provided or domain invalid. Aborting!</error>' );
			exit( 1 );
		}

		$input->setArgument( 'domain', $this->primary_domain ); // Store the domain in the argument field.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to launch {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) with primary domain $this->primary_domain? [Y/n]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		// Confirm in case the website doesn't look like a production site.
		if ( false === \strpos( $this->pressable_site->url, '-production' ) ) {
			$question = new ConfirmationQuestion( "<question>The website's current URL, {$this->pressable_site->url}, does not contain the string \"-production\". Continue anyway? [Y/n] </question>", false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to launch:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
