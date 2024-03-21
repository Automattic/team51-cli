<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_github_repositories;
use function Team51\Helper\get_github_repository;
use function Team51\Helper\get_github_repository_public_key;
use function Team51\Helper\get_github_repository_secrets;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\update_github_repository_secret;

/**
 * CLI command for updating a GitHub repository secret.
 */
class Update_Repository_Secret extends Command {
	use \Team51\Helper\Autocomplete;

	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'update-repository-secret'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Currently, can only be set to `all`, if at all.
	 *
	 * @var string|null
	 */
	protected ?string $multiple = null;

	/**
	 * The list of GitHub repositories to process.
	 *
	 * @var array|null
	 */
	protected ?array $repositories = null;

	/**
	 * The secret name to update.
	 *
	 * @var string|null $secret_name
	 */
	protected ?string $secret_name = null;

	// endregion

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Updates GitHub repository secret on github.com in the organization specified with GITHUB_API_OWNER. and project name' )
			->setHelp( 'This command allows you to update Github repository secret or create one if it is missing.' );

		$this->addArgument( 'repo-slug', InputArgument::OPTIONAL, 'The slug of the GitHub repository' )
			->addOption( 'secret-name', null, InputOption::VALUE_REQUIRED, 'Secret name in all caps (e.g., GH_BOT_TOKEN)', 'GH_BOT_TOKEN' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'repo-slug\' argument is optional or not. Accepts only \'all\' currently.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all' ) );

		// If processing a single repository, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$repo_slug = $input->getArgument( 'repo-slug' );
			if ( empty( $repo_slug ) ) {
				$output->writeln( '<error>Repository slug is required.</error>' );
				exit( 1 );
			}

			if ( \is_null( get_github_repository( GITHUB_API_OWNER, $repo_slug ) ) ) {
				$output->writeln( '<error>Repository not found.</error>' );
				exit( 1 );
			}

			// Set the repo slug as an argument for the command.
			$input->setArgument( 'repo-slug', $repo_slug );

			// Set the repo slug as the only repository to process.
			$this->repositories = array( $repo_slug );
		} else {
			$page = 1;
			do {
				$repositories_page = get_github_repositories(
					GITHUB_API_OWNER,
					array(
						'per_page' => 100,
						'page'     => $page,
					)
				);
				if ( \is_null( $repositories_page ) ) {
					$output->writeln( '<error>Failed to retrieve repositories.</error>' );
					exit( 1 );
				}

				$this->repositories = array_merge( $this->repositories ?? array(), array_column( $repositories_page, 'name' ) );
				++$page;
			} while ( ! empty( $repositories_page ) );
		}

		// Retrieve the given secret name which is always required.
		$this->secret_name = \strtoupper( $input->getOption( 'secret-name' ) );
		if ( 'GH_BOT_TOKEN' !== $this->secret_name && ! defined( $this->secret_name ) ) {
			$output->writeln( '<error>No constant with the given secret name found.</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $this->multiple ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to update the $this->secret_name secret on <fg=red;options=bold>ALL</> repositories? [y/N]</question> " );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to update the $this->secret_name secret on {$this->repositories[0]}? [y/N]</question> " );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->repositories as $repository ) {
			$secrets = get_github_repository_secrets( GITHUB_API_OWNER, $repository );

			// Check if $secrets is an array before proceeding
			if ( ! \is_array( $secrets ) ) {
				$output->writeln( "<error>Error: Unable to retrieve secrets for $repository. Skipping...</error>", OutputInterface::VERBOSITY_VERBOSE );
				continue;
			}

			if ( ! \in_array( $this->secret_name, \array_column( $secrets, 'name' ), true ) ) {
				$output->writeln( "<comment>Secret $this->secret_name not found on $repository. Skipping...</comment>", OutputInterface::VERBOSITY_VERBOSE );
				continue;
			}

			$repo_public_key = get_github_repository_public_key( GITHUB_API_OWNER, $repository );
			if ( empty( $repo_public_key ) ) {
				$output->writeln( "<error>Failed to retrieve public key for $repository. Skipping...</error>" );
				continue;
			}

			$secret_value    = \constant( 'GH_BOT_TOKEN' === $this->secret_name ? 'GITHUB_API_BOT_SECRETS_TOKEN' : $this->secret_name );
			$encrypted_value = $this->seal_secret( $secret_value, \base64_decode( $repo_public_key->key ) );

			$result = update_github_repository_secret( GITHUB_API_OWNER, $repository, $this->secret_name, $encrypted_value, $repo_public_key->key_id );
			if ( $result ) {
				$output->writeln( "<fg=green;options=bold>Successfully updated secret $this->secret_name on $repository.</>" );
			} else {
				$output->writeln( "<error>Failed to update secret $this->secret_name on $repository.</error>" );
			}
		}

		return 0;
	}

	/**
	 * Generate base64 encoded sealed box of passed secret.
	 *
	 * @throws \SodiumException
	 */
	private function seal_secret( string $secret_string, string $public_key ): string {
		return \base64_encode( \sodium_crypto_box_seal( $secret_string, $public_key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
