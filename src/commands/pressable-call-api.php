<?php

namespace Team51\Command;

use stdClass;
use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Pressable_Call_Api extends Command {
	protected static $defaultName = 'pressable-call-api';
	private $api_helper;
	private $output;

	/** @inheritDoc */
	protected function configure() {
		$this
		->setDescription( 'Calls the Pressable API directly' )
		->setHelp( 'Refer to the API docs for more details: https://my.pressable.com/documentation/api/v1' )
		->addOption( 'query', null, InputOption::VALUE_REQUIRED, 'The query string for the request. This is everything after "https://my.pressable.com/v1/" in URL. (e.g., "sites/1234"' )
		->addOption( 'method', null, InputOption::VALUE_OPTIONAL, 'The query type (GET, POST, etc.). Default is GET.' )
		->addOption( 'data', null, InputOption::VALUE_OPTIONAL, 'A JSON string of the data to pass on. (e.g.: {"paginate":true}' );
	}

	/**
	 * Access to the QuestionHelper
	 *
	 * @return \Symfony\Component\Console\Helper\QuestionHelper
	 */
	private function get_question_helper(): QuestionHelper {
		return $this->getHelper( 'question' );
	}


	/**
	 * Main callback for the command.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->api_helper = new API_Helper();
		$this->output     = $output;

		$this->handle_api_call( $input, $output );

		$output->writeln( "<info>\nAll done!<info>" );
	}

	/***********************************
	 ***********************************
	 *          INPUT GETTERS          *
	 ***********************************
	 ***********************************/

	/**
	 * Gets the query string.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_query( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get the query from the Input.
		$query = $input->getOption( 'query' );

		// If not provided, fail
		if ( empty( $query ) ) {
			$output->writeln( '<error>You must supply a valid query string.</error>' );
			exit;
		}

		// Account for leading slashes, if provided.
		$query = trim( $query, '/' );
		return $query;
	}

	/**
	 * Gets the query method, defaulting to GET.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_method( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get search term from Input.
		$method = $input->getOption( 'method' );

		// If we don't have a method, default to GET.
		if ( empty( $method ) ) {
			$method = 'GET';
		}

		return $method;
	}

	/**
	 * Gets the query data from Input or prompted for.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return string
	 */
	private function get_data( InputInterface $input, OutputInterface $output ): string {
		// Attempt to get data from Input.
		$data = $input->getOption( 'data' ) ?? '';

		// If this isn't valid JSON data, fail.
		if ( ! empty( $data ) && null === json_decode( $data ) ) {
			$output->writeln( '<error>You must supply a valid JSON encoded string.</error>' );
			exit;
		}
		return $data;
	}

	/*************************************
	 *************************************
	 *             HANDLERS              *
	 *************************************
	 *************************************/

	/**
	 * Handles the call to the Pressable API.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return void
	 */
	private function handle_api_call( InputInterface $input, OutputInterface $output ): void {
		// Get the query data.
		$query = $this->get_query( $input, $output );

		$method = $this->get_method( $input, $output );

		$data = $this->get_data( $input, $output );

		// Callback for API call
		$output->writeln( sprintf( "<info>Attempting to call Pressable API at endpoint %s using %s. \nData:\n%s</info>", $query, $method, $data ) );

		$result = $this->api_helper->call_pressable_api( $query, $method, $data );

		$output->writeln( sprintf( '<info>API call result: %s</info>', print_r( $result, true ) ) );
	}

}
