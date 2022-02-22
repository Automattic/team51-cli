<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Front_Create_Export extends Command {
    protected static $defaultName = 'front-create-export';

    protected function configure() {
        $this
        ->setDescription( 'Creates an export request in Front for all messages or messages within a specific timeframe.' )
		->setHelp( 'This command creates an export request in Front. Exports take some time to process, use the `team51 front-list-exports` command to see all available exports and their statuses.' )
		->addOption( 'start-date', null, InputOption::VALUE_OPTIONAL, 'The Start Date in YYYY-MM-DD format' )
		->addOption( 'end-date', null, InputOption::VALUE_OPTIONAL, 'The End Date in YYYY-MM-DD format' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
		$start_date = $input->getOption( 'start-date' );

		// Start date.
		if ( ! empty( $start_date ) ) {
			$start_date = strtotime( $start_date );
		} else {
			$start_date = strtotime( '2015-01-01' ); // A date before we started using Front.
		}

		if ( empty( $start_date ) ) {
			$output->writeln( '<error>Invalid Start Date.</error>' );
			exit;
		}

		// End date.
		$end_date = $input->getOption( 'end-date' );

		if ( ! empty( $end_date ) ) {
			$end_date = strtotime( $end_date );
		} else {
			$end_date = time();
		}

		if ( empty( $end_date ) ) {
			$output->writeln( '<error>Invalid End Date.</error>' );
			exit;
		}

		if ( $start_date >= $end_date ) {
			$output->writeln( '<error>The End Date must come after the Start Date.</error>' );
			exit;
		}

		$api_helper = new API_Helper;

		$data = array(
			'start' => $start_date,
			'end'   => $end_date,
			'type'  => 'messages',
		);

		$output->writeln( 'Asking Front to generate a new export...' );

		$result = $api_helper->call_front_api( 'analytics/exports', $data, 'POST' );

		if ( empty( $result->_links->self ) || empty( $result->status ) ) {
			$output->writeln( '<error>Oh no, something went wrong!</error>' );
			exit;
		}

		$id = str_replace( 'https://wordpress-concierge.api.frontapp.com/analytics/exports/', '', $result->_links->self );

		$output->writeln( sprintf( '<info>A new export request was created with the ID %s. Current status is %s.</info>', $id, ucfirst( $result->status ) ) );
		$output->writeln( sprintf( '<comment>Use the `team51 front-get-export --export-id=%s` command to check on the export status and get a download link.</comment>', $id ) );
    }
}
