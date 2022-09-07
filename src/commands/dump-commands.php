<?php
namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Descriptor\JsonDescriptor;
use Symfony\Component\Console\Descriptor\XmlDescriptor;

class Dump_Commands extends Command {

	protected function configure() {
		$this
			->setName( 'dump-commands' )
			->setDescription( 'Dumps information about all commands' )
			// TODO: Update link to point to an actual doc
			->setHelp( "This command allows you to dump a list of all commands with their description and help.\nFor more details on using this to update the CLI documentation, check here: https://github.com/Automattic/team51-cli/wiki" )
			->addOption( 'format', 'f', InputOption::VALUE_REQUIRED, 'The format to use (md, txt, json, xml)', 'md' )
			->addOption( 'save', '', InputOption::VALUE_REQUIRED, 'Save the output to a file', false );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$commands   = $this->getApplication()->all();
		$descriptor = new MarkdownDescriptor();
		foreach ( $commands as $command ) {
			$output->writeln( '## ' . $command->getName() );
			$descriptor->describe( $output, $command );
		}
	}

	private function get_descriptor( $format ) {
		switch ( $format ) {
			case 'md':
				return new MarkdownDescriptor();
			case 'json':
				return new JsonDescriptor();
			case 'xml':
				return new XmlDescriptor();
			default:
				throw new \Exception( 'Invalid format' );
		}
	}
}
