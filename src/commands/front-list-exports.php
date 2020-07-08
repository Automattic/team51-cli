<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class Front_List_Exports extends Command {
	protected static $defaultName = 'front-list-exports';

	protected function configure() {
		$this
		->setDescription( 'Lists the most recent Front exports and their respective download links.' )
		->setHelp( 'Use this command to get the most recent exports from Front. Use Command + Click on the filename to download the export.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$api_helper = new API_Helper;
		$exports    = $api_helper->call_front_api( 'exports' );

		if ( empty( $exports->_results ) ) {
			$output->writeln( '<error>Oh no, something went wrong!</error>' );
			$output->writeln( '<comment>Are you sure an export request has been initiated?</comment>' );
		}

		$table_data = array();

		foreach ( $exports->_results as $export ) {
			$url = '';

			if ( ! empty( $export->url ) ) {
				preg_match( '/export-messages-wordpress_concierge-(.*)/', $export->url, $matches );
				$url = sprintf( '<href=%s>%s</>', $export->url, $matches[1] );
			}

			$table_data[] = array(
				$export->id,
				date( 'Y-m-d h:i:s A', $export->created_at ),
				ucfirst( $export->status ),
				$url
			);
		}

		$table = new Table( $output );

		$table
			->setHeaders( array( 'ID', 'Date', 'Status', 'Download' ) )
			->setRows( $table_data );

		$table->render();
	}
}
