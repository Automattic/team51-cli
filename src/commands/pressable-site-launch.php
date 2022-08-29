<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\add_pressable_site_domain;
use function Team51\Helper\get_pressable_site_by_id;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\is_case_insensitive_match;
use function Team51\Helper\maybe_convert_pressable_site;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\search_1password_items;
use function Team51\Helper\set_pressable_site_primary_domain;
use function Team51\Helper\update_1password_item;

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
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

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

		// Store the domain in the argument field.
		$this->primary_domain = \strtolower( $this->primary_domain );
		$input->setArgument( 'domain', $this->primary_domain );
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
			$question = new ConfirmationQuestion( "<question>The website's current URL, {$this->pressable_site->url}, does not contain the string \"-production\". Continue anyway? [Y/n]</question> ", false );
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
		$output->writeln( "<fg=magenta;options=bold>Launching {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) with new primary domain $this->primary_domain.</>" );

		// First convert the site to a live site, if needed.
		$output->writeln( '<comment>Converting to live site, if needed.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$result = maybe_convert_pressable_site( $this->pressable_site->id, 'live' );
		if ( \is_null( $result ) ) {
			$output->writeln( '<error>Failed to convert site to live site.</error>' );
			return 1;
		}

		// Add the new domain to the site.
		$output->writeln( '<comment>Adding domain to site.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$site_domains = add_pressable_site_domain( $this->pressable_site->id, $this->primary_domain );
		if ( \is_null( $site_domains ) ) {
			$output->writeln( '<error>Failed to add domain to site.</error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>Domain added to site.</>' );

		// Make sure the site is set to use the new domain.
		foreach ( $site_domains as $site_domain ) {
			if ( ! $site_domain->primary && is_case_insensitive_match( $this->primary_domain, $site_domain->domainName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$output->writeln( '<comment>Setting domain as primary.</comment>', OutputInterface::VERBOSITY_VERBOSE );

				$primary_domain = set_pressable_site_primary_domain( $this->pressable_site->id, $site_domain->id );
				if ( \is_null( $primary_domain ) ) {
					$output->writeln( '<error>Failed to set primary domain.</error>' );
					return 1;
				}

				$output->writeln( '<fg=green;options=bold>Domain set as primary.</>' );
				break;
			}
		}

		// Update the site's URL in 1Password.
		$output->writeln( '<comment>Updating site URL in 1Password.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$op_login_entries = search_1password_items(
			fn( object $op_item ) => $this->match_1password_login_entry( $op_item ),
			array(
				'categories' => 'login',
				'tags'       => 'team51-cli',
			)
		);
		$output->writeln( \sprintf( '<comment>Found %d login entries in 1Password that require a URL update.</comment>', \count( $op_login_entries ) ), OutputInterface::VERBOSITY_DEBUG );

		$this->pressable_site = get_pressable_site_by_id( $this->pressable_site->id ); // Refresh the site data. The displayName field is likely to have changed.
		foreach ( $op_login_entries as $op_login_entry ) {
			$output->writeln( "<info>Updating 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id).</info>", OutputInterface::VERBOSITY_VERY_VERBOSE );
			$result = update_1password_item(
				$op_login_entry->id,
				array(
					'title' => $this->pressable_site->displayName,
					'url'   => "https://$this->primary_domain",
				)
			);
			if ( \is_null( $result ) ) {
				$output->writeln( "<error>Failed to update 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id). Please update manually!</error>" );
			}
		}

		$output->writeln( '<fg=green;options=bold>Site launched!</>' );
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

	/**
	 * Returns true if the given 1Password login entry matches the site.
	 *
	 * @param   object  $op_login   The 1Password login entry.
	 *
	 * @return  bool
	 */
	private function match_1password_login_entry( object $op_login ): bool {
		$result = false;

		$login_urls = \property_exists( $op_login, 'urls' ) ? (array) $op_login->urls : array();
		foreach ( $login_urls as $login_url ) {
			$login_url = \trim( $login_url->href );
			if ( false !== \strpos( $login_url, 'http' ) ) { // Strip away everything but the domain itself.
				$login_url = \parse_url( $login_url, PHP_URL_HOST );
			} else { // Strip away endings like /wp-admin or /wp-login.php.
				$login_url = \explode( '/', $login_url, 2 )[0];
			}

			$result = is_case_insensitive_match( $this->pressable_site->url, $login_url );
		}

		return $result;
	}

	// endregion
}
