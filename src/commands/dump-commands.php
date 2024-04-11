<?php
namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Descriptor\JsonDescriptor;
use Symfony\Component\Console\Descriptor\XmlDescriptor;

class Dump_Commands extends Command {
	use \Team51\Helper\Autocomplete;

	protected function configure() {
		$this
			->setName( 'dump-commands' )
			->setDescription( 'Dumps information about all commands' )
			// TODO: Update link to point to an actual doc
			->setHelp( "This command allows you to dump a list of all commands with their description and help.\nFor more details on using this to update the CLI documentation, check here: https://github.com/Automattic/team51-cli/wiki" )
			->addOption( 'format', 'f', InputOption::VALUE_REQUIRED, 'The format to use (md, txt, json, xml)', 'md' )
			->addOption( 'save', '', InputOption::VALUE_NONE, 'Save the output to a file' )
			->addOption( 'destination', 'd', InputOption::VALUE_REQUIRED, "The path to save the output to (Only applies if --save option is set)\nIf an extension isn't specified, it will be added automatically based on the format (e.g., 'dump-commands --format=json --destination=myfile' will output to myfile.json", getcwd() . '/team51-commands' );

	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {

		try {
			$descriptor = $this->get_descriptor( $input->getOption( 'format' ) );
		} catch ( \Exception $e ) {
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );
			return Command::FAILURE;
		}

		$stream = $this->set_output_stream( $input, $output );
		$descriptor->describe( $stream, $this->getApplication() );

		return Command::SUCCESS;
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
				throw new \InvalidArgumentException( 'Invalid format' );
		}
	}

	private function maybe_add_file_extension( $input ) {
		$path  = $input->getOption( 'destination' );
		$regex = '/\w+\.\w+$/'; // Checks if there is already an extension on the file
		if ( preg_match( $regex, $path ) ) {
			return $path;
		}

		return $path . '.' . $input->getOption( 'format' );
	}

	private function set_output_stream( InputInterface $input, OutputInterface $output ) {
		if ( $input->getOption( 'save' ) ) {
			$path    = $this->maybe_add_file_extension( $input );
			$outfile = new StreamOutput( fopen( $path, 'w' ) );
			if ( ! $outfile ) {
				$output->writeln( '<error>Could not open file for writing: ' . $path . '</error>' );
				return;
			}
			$output->writeln( '<info>Saving to file: ' . $path . '</info>' );
			return $outfile;
		}

		// Default to standard output stream
		return $output;
	}
}
