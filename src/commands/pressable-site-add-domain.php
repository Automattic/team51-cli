<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use function Team51\Helper\add_pressable_site_domain;
use function Team51\Helper\convert_pressable_site;
use function Team51\Helper\get_pressable_site_by_id;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\is_1password_item_url_match;
use function Team51\Helper\is_case_insensitive_match;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\run_app_command;
use function Team51\Helper\search_1password_items;
use function Team51\Helper\set_pressable_site_primary_domain;
use function Team51\Helper\update_1password_item;

/**
 * CLI command for adding a domain to a Pressable site.
 */
final class Pressable_Site_Add_Domain extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:add-site-domain'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The new domain for the site.
	 *
	 * @var string|null
	 */
	protected ?string $new_domain = null;

	/**
	 * Whether the new domain should also be set as primay or not.
	 *
	 * @var bool|null
	 */
	protected ?bool $primary = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Adds a given domain to a given Pressable site and optionally sets it as primary.' )
			->setHelp( 'This command allows you to add a new domain to a Pressable site. If the given domain is to also be set as primary, then any 1Password entries using the old URL will be updated as well.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to add the domain to.' )
			->addArgument( 'domain', InputArgument::REQUIRED, 'The domain to add to the site.' )
			->addOption( 'primary', null, InputOption::VALUE_NONE, 'Set the given domain as the primary one.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->primary = (bool) $input->getOption( 'primary' );

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		// Retrieve the given domain. If none is provided, maybe prompt for it.
		$this->new_domain = $input->getArgument( 'domain' );
		if ( empty( $this->new_domain ) && $input->isInteractive() ) {
			$question         = new Question( '<question>Enter the domain to add to the site:</question> ' );
			$this->new_domain = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		// Perform a few sanity checks on the new primary domain.
		if ( ! empty( $this->new_domain ) && false !== \strpos( $this->new_domain, 'http' ) ) {
			$this->new_domain = \parse_url( $this->new_domain, PHP_URL_HOST );
		}
		if ( false === \strpos( $this->new_domain, '.', 1 ) ) {
			$this->new_domain = null;
		}

		// If we don't have a valid primary domain, we can't continue.
		if ( empty( $this->new_domain ) ) {
			$output->writeln( '<error>No domain was provided or domain invalid. Aborting!</error>' );
			exit( 1 );
		}

		// Store the domain in the argument field.
		$this->new_domain = \strtolower( $this->new_domain );
		$input->setArgument( 'domain', $this->new_domain );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the domain $this->new_domain to {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})? [Y/n]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		// If the site is a staging one, confirm that it's ok to convert it to a live one.
		if ( $this->pressable_site->staging ) {
			$question = new ConfirmationQuestion( "<question>{$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) is a staging site. Adding a domain will first convert it to a live site. Continue? [Y/n]</question> ", false );
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
		$output->writeln( "<fg=magenta;options=bold>Adding domain $this->new_domain to {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}).</>" );

		// First convert the site to a live site, if needed.
		if ( $this->pressable_site->staging ) {
			$output->writeln( '<comment>Converting to live site.</comment>', OutputInterface::VERBOSITY_VERBOSE );

			$result = convert_pressable_site( $this->pressable_site->id );
			if ( \is_null( $result ) ) {
				$output->writeln( '<error>Failed to convert staging site to live site.</error>' );
				return 1;
			}

			$output->writeln( '<fg=green;options=bold>Converted staging site to live site.</>' );
		} else {
			$output->writeln( '<comment>Given site already supports domains. No conversion from staging site to live site required.</comment>', OutputInterface::VERBOSITY_VERY_VERBOSE );
		}

		// Add the new domain to the site.
		$output->writeln( '<comment>Adding domain to site.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$site_domains = add_pressable_site_domain( $this->pressable_site->id, $this->new_domain );
		if ( \is_null( $site_domains ) ) {
			$output->writeln( '<error>Failed to add domain to site.</error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>Domain added to site.</>' );

		// If the new domain is the primary domain, set it as such and update 1Password URLs.
		if ( $this->primary ) {
			// Set the new domain as the primary domain.
			$primary_domain = null;
			foreach ( $site_domains as $site_domain ) {
				if ( ! is_case_insensitive_match( $this->new_domain, $site_domain->domainName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				if ( ! $site_domain->primary ) {
					$output->writeln( '<comment>Setting domain as primary.</comment>', OutputInterface::VERBOSITY_VERBOSE );

					$primary_domain = set_pressable_site_primary_domain( $this->pressable_site->id, $site_domain->id );
					if ( \is_null( $primary_domain ) ) {
						$output->writeln( '<error>Failed to set primary domain.</error>' );
						return 1;
					}

					$output->writeln( '<fg=green;options=bold>Domain set as primary.</>' );
				} else {
					$output->writeln( '<fg=green;options=bold>Domain already set as primary.</>' );
					$primary_domain = $site_domain;
				}

				// Run a search-replace on the database for completion/correction.
				if ( $this->pressable_site->url !== $site_domain->domainName ) {
					$output->writeln( '<comment>Running search-replace via WP-CLI.</comment>', OutputInterface::VERBOSITY_VERBOSE );

					/* @noinspection PhpUnhandledExceptionInspection */
					$search_replace_result = run_app_command(
						$this->getApplication(),
						Pressable_Site_Run_WP_CLI_Command::getDefaultName(),
						array(
							'site'           => $this->pressable_site->id,
							'wp-cli-command' => "search-replace {$this->pressable_site->url} $site_domain->domainName",
						),
						$output
					);
					if ( 0 !== $search_replace_result ) {
						$output->writeln( '<error>Failed to run search-replace via WP-CLI. Please run it manually!</error>' );
					} else {
						$output->writeln( '<fg=green;options=bold>Search-replace via WP-CLI completed.</>' );

						/* @noinspection PhpUnhandledExceptionInspection */
						$cache_flush_result = run_app_command(
							$this->getApplication(),
							Pressable_Site_Run_WP_CLI_Command::getDefaultName(),
							\array_filter(
								array(
									'site'           => $this->pressable_site->id,
									'wp-cli-command' => 'cache flush',
								)
							),
							$output
						);
						if ( 0 !== $cache_flush_result ) {
							$output->writeln( '<error>Failed to flush object cache via WP-CLI. Please flush it manually!</error>' );
						} else {
							$output->writeln( '<fg=green;options=bold>Object cache flushed via WP-CLI.</>' );
						}
					}
				}

				break;
			}

			// Update 1Password URLs.
			$output->writeln( '<comment>Updating site URL in 1Password.</comment>', OutputInterface::VERBOSITY_VERBOSE );

			$op_login_entries = search_1password_items(
				fn( object $op_item ) => is_1password_item_url_match( $op_item, $this->pressable_site->url ),
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
						'url'   => "https://$primary_domain->domainName",
					)
				);
				if ( \is_null( $result ) ) {
					$output->writeln( "<error>Failed to update 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id). Please update manually!</error>" );
				}
			}

			$output->writeln( '<fg=green;options=bold>Relevant 1Password login entries have been updated.</>' );
		}

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
			$question = new Question( '<question>Enter the site ID or URL to add the domain to:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
