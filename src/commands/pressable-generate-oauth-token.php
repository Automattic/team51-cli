<?php

namespace Team51\Command;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Helper\Pressable_API_Helper;
use function Team51\Helper\encode_json_content;
use function Team51\Helper\get_string_input;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for generating an OAuth token for the Pressable API.
 */
class Pressable_Generate_OAuth_Token extends Command {
	use \Team51\Helper\Autocomplete;

	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:generate-oauth-token'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The client ID of the OAuth app to use.
	 *
	 * @var string|null
	 */
	protected ?string $client_id = null;

	/**
	 * The client secret of the OAuth app to use.
	 *
	 * @var string|null
	 */
	protected ?string $client_secret = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Generates a Pressable OAuth refresh token for a given API application.' )
			->setHelp( 'This command requires a Pressable API application client ID and client secret, which it uses to generate a refresh token that outside collaborators can use to gain access to the Pressable API via this CLI tool.' );

		$this->addOption( 'client-id', null, InputOption::VALUE_REQUIRED, 'The Pressable API application client ID.' )
			->addOption( 'client-secret', null, InputOption::VALUE_REQUIRED, 'The Pressable API application client secret.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->client_id     = get_string_input( $input, $output, 'client-id', fn() => $this->prompt_client_id_input( $input, $output ) );
		$this->client_secret = get_string_input( $input, $output, 'client-secret', fn() => $this->prompt_client_secret_input( $input, $output ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Generating a new OAuth refresh token for client ID $this->client_id.</>" );

		$api_tokens = Pressable_API_Helper::call_auth_api( $this->client_id, $this->client_secret );

		$output->writeln( '<info>Please provide the following output to the outside collaborator. These lines should be placed in their <fg=magenta;options=bold>config__contractors.json</> file:</info>' . PHP_EOL );
		$output->writeln(
			encode_json_content(
				array(
					'api_app_client_id'     => $this->client_id,
					'api_app_client_secret' => $this->client_secret,
					'api_refresh_token'     => $api_tokens->refresh_token,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . PHP_EOL
		);

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a client ID and returns the value.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_client_id_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question  = new Question( '<question>Enter the Pressable API application client ID:</question> ' );
			$client_id = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $client_id ?? null;
	}

	/**
	 * Prompts the user for a client secret and returns the value.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_client_secret_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question      = new Question( '<question>Enter the Pressable API application client secret:</question> ' );
			$client_secret = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $client_secret ?? null;
	}

	// endregion
}
