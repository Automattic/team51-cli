<?php
namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Descriptor\JsonDescriptor;
use Symfony\Component\Console\Descriptor\XmlDescriptor;

class Testing extends Command {

	protected static $defaultName = 'testing';

	protected function configure() {
		$this
			->setName( 'testing' )
			->setDescription( 'Testing auto-complete' )
			// TODO: Update link to point to an actual doc
			->setHelp( "Testing..." )
			->addOption( 'format', 'f', InputOption::VALUE_REQUIRED, 'The format to use (md, txt, json, xml)', 'md' )
			->addOption( 'save', '', InputOption::VALUE_NONE, 'Save the output to a file' )
			->addOption( 'destination', 'd', InputOption::VALUE_REQUIRED, "The path to save the output to (Only applies if --save option is set)\nIf an extension isn't specified, it will be added automatically based on the format (e.g., 'dump-commands --format=json --destination=myfile' will output to myfile.json", getcwd() . '/team51-commands' );

	}

	public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(['json', 'xml']);
        }
        if ($input->mustSuggestOptionValuesFor('destination')) {
            $suggestions->suggestValues(['test1', 'test2', 'something-else']);
        }
    }

	protected function execute( InputInterface $input, OutputInterface $output ) {

		$output->writeln( '<info> It works! </info>' );

		return 1;


	}

}
