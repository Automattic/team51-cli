<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class Front_Get_Export extends Command {
	protected static $defaultName = 'front-get-export';

	protected function configure() {
		$this
		->setDescription( 'Get the status and download link of a Front export.' )
		->setHelp( 'Use this command to get an export from Front. Use Command + Click on the filename to download the export.' )
		->addOption( 'export-id', null, InputOption::VALUE_REQUIRED, 'The export id.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		if ( empty( $input->getOption( 'export-id' ) ) ) {
			$output->writeln( '<error>You must pass an export id with --export-id.</error>' );
			exit;
		} else {
			$id = $input->getOption( 'export-id' );
		}

		$api_helper = new API_Helper;
		$export     = $api_helper->call_front_api( "analytics/exports/{$id}" );

		if ( empty( $export->status ) || empty( $export->progress ) ) {
			$output->writeln( '<error>Oh no, something went wrong!</error>' );
			$output->writeln( '<comment>Are you sure an export request with this id has been initiated?</comment>' );
		}

		$table_data = array();

		$url = '';

		if ( ! empty( $export->url ) ) {
			$url = sprintf( '<href=%s>%s</>', $export->url, $export->url );
		}

		$table_data[] = array(
			$id,
			ucfirst( $export->status ),
			$export->progress . '%',
			$url
		);

		$table = new Table( $output );

		$table
			->setHeaders( array( 'ID', 'Status', 'Progress', 'Download' ) )
			->setRows( $table_data );

		$table->render();
	}
}
