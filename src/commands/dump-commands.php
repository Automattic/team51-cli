<?php
namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;

class Dump_Commands extends Command {

	protected function configure() {
		$this
			->setName( 'dump-commands' )
			->setDescription( 'Dumps information about all commands' )
			->setHelp( 'This command allows you to dump a list of all commands with their description and help' )
			->addOption( 'format', 'f', InputOption::VALUE_REQUIRED, 'The format to use (md, txt, json)', 'md' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$commands   = $this->getApplication()->all();
		$descriptor = new MarkdownDescriptor();
		foreach ( $commands as $command ) {
			$output->writeln( '## ' . $command->getName() );
			$descriptor->describe( $output, $command );
		}
	}
}
