<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;

class Jetpack extends Command {
    protected static $defaultName = 'jetpack';

    protected function configure() {
        $this
		->setDescription( "Lists all Jetpack commands available." )
		->setHelp( "This command allows you to see all the available Jetpack commands." );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
		$command = $this->getApplication()->find( 'list' );

		$arguments = array(
			'namespace' => 'jetpack',
		);

		$command_input = new ArrayInput( $arguments );
		$command->run( $command_input, $output );
    }
}
