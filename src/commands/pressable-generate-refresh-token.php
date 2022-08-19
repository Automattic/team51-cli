<?php

namespace Team51\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Team51\Helpers\Pressable_API_Helper;
use function Team51\Helpers\define_console_verbosity;

/**
 * CLI command for generating a Pressable refresh token.
 */
class Pressable_Generate_Refresh_Token extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:generate-refresh-token';

	/**
	 * The Pressable API Application Client ID.
	 *
	 * @var string|null
	 */
	protected ?string $client_id = null;

	/**
	 * The Pressable API Application Client Secret.
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
		$this->setDescription( 'Generates a Pressable refresh token for a given API Application.' )
			->setHelp( 'This command allows you to generate a Pressable refresh token which is required for outside contractors for using this CLI tool.' );

		$this->addOption( 'client-id', null, InputOption::VALUE_REQUIRED, 'The Pressable API Application Client ID.' )
			->addOption( 'client-secret', null, InputOption::VALUE_REQUIRED, 'The Pressable API Application Client Secret.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		define_console_verbosity( $output->getVerbosity() );

		$this->client_id = $this->get_client_id_input( $input, $output );
		$input->setOption( 'client-id', $this->client_id );

		$this->client_secret = $this->get_client_secret_input( $input, $output );
		$input->setOption( 'client-secret', $this->client_secret );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Generating refresh token for client ID $this->client_id.</>" );

		$tokens = Pressable_API_Helper::call_auth_api( $this->client_id, $this->client_secret );
		$output->writeln( '<fg=green;options=bold>Success!</> Please provide the following data to the outside contractor to add to their 1Password entry:' );

		$table = new Table( $output );

		$table->setHeaders( array( 'Field', 'Value' ) );
		$table->setRows(
			array(
				array( 'API app client id', $this->client_id ),
				array( 'API app client secret', $this->client_secret ),
				array( 'API refresh token', $tokens->refresh_token ),
			)
		);

		$table->setStyle( 'box-double' )->render();

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Retrieves the client ID input from the user.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function get_client_id_input( InputInterface $input, OutputInterface $output ): string {
		$client_id = $input->getOption( 'client-id' );

		// If the client ID is not provided, ask for it.
		if ( empty( $client_id ) && ! $input->getOption( 'no-interaction' ) ) {
			$question  = new Question( '<question>Enter the Pressable API Application Client ID:</question> ' );
			$client_id = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		// If we still don't have a client id, abort.
		if ( empty( $client_id ) ) {
			$output->writeln( '<error>No client id was provided. Aborting!</error>' );
			exit;
		}

		return $client_id;
	}

	/**
	 * Retrieves the client secret input from the user.
	 *
	 * @param   InputInterface      $input      The input interface.
	 * @param   OutputInterface     $output     The output interface.
	 *
	 * @return  string
	 */
	private function get_client_secret_input( InputInterface $input, OutputInterface $output ): string {
		$client_secret = $input->getOption( 'client-secret' );

		// If the client ID is not provided, ask for it.
		if ( empty( $client_secret ) && ! $input->getOption( 'no-interaction' ) ) {
			$question      = new Question( '<question>Enter the Pressable API Application Client Secret:</question> ' );
			$client_secret = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		// If we still don't have a client id, abort.
		if ( empty( $client_secret ) ) {
			$output->writeln( '<error>No client secret was provided. Aborting!</error>' );
			exit;
		}

		return $client_secret;
	}

	// endregion
}
