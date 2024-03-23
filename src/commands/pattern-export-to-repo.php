<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\run_system_command;

class Pattern_Export_To_Repo extends Command {
	protected static $defaultName = 'pattern-export-to-repo';

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The name of the pattern to export.
	 *
	 * @var string|null
	 */
	protected ?string $pattern_name = null;

	/**
	 * The slug of the category under which the pattern will be exported.
	 *
	 * @var string|null
	 */
	protected ?string $category_slug = null;

	/**
	 * {@inheritDoc}
	 */
	protected function configure() {
		$this
			->setDescription( 'Exports a block pattern from a site to a GitHub.' )
			->setHelp( 'This command exports a specified block pattern into a category within a GitHub repository.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the Pressable site to run the command on.' )
			->addArgument( 'pattern-name', InputArgument::REQUIRED, 'The unique identifier of the block pattern to export (e.g., "namespace/pattern-name").' )
			->addArgument( 'category-slug', InputArgument::REQUIRED, 'The slug of the category under which the pattern should be exported. It should be lowercase with hyphens instead of spaces (e.g., "featured-patterns").' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		// Check if the pattern name was already provided as an argument. If not, prompt the user for it.
		if ( ! $input->getArgument( 'pattern-name' ) ) {
			$this->pattern_name = $this->prompt_pattern_name_input( $input, $output );
			$input->setArgument('pattern-name', $this->pattern_name );
		}

		// Check if the category slug was already provided as an argument. If not, prompt the user for it.
		if ( ! $input->getArgument( 'category-slug' ) ) {
			$this->category_slug = $this->prompt_category_slug_input( $input, $output );
			$input->setArgument( 'category-slug', $this->category_slug );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$ssh_connection = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh_connection ) ) {
			$output->writeln( "<error>Failed to connect via SSH for {$this->pressable_site->url}. Aborting!</error>" );
			return 1;
		}

		$command = sprintf(
			'wp eval "if (!empty(\$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered(\'%s\'))) { echo json_encode([\'__file\' => \'wp_block\', \'title\' => \$pattern[\'title\'], \'content\' => \$pattern[\'content\'], \'syncStatus\' => \'\']); }"',
			addslashes( $this->pattern_name )
		);

		$result = $ssh_connection->exec( $command );

		if ( ! empty( $result ) ) {

			// Temporary directory to clone the repository
			$temp_dir = sys_get_temp_dir() . '/special-projects-patterns';
			$repo_url = 'git@github.com:a8cteam51/special-projects-patterns.git';

			// Clone the repository
			run_system_command( [ 'git', 'clone', $repo_url, $temp_dir ], sys_get_temp_dir() );

			// Additional setup for category directory and metadata.json handling.
			$category_dir = $temp_dir . '/' . $this->category_slug;
			$metadata_path = $category_dir . '/metadata.json';

			// Ensure the category directory exists.
			run_system_command( [ 'mkdir', '-p', $category_dir ], $temp_dir );

			// Check if metadata.json exists before creating or overwriting
			if ( ! file_exists( $metadata_path ) ) {
				$metadata = [ 'title' => $this->category_slug ];
				file_put_contents( $metadata_path, json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				// Add metadata.json to the repository
				run_system_command( [ 'git', 'add', $metadata_path ], $temp_dir );
			}

			// Path to the JSON file for the pattern.
			$pattern_file_base = $this->_slugify( $this->pattern_name );
			$pattern_file_name = $pattern_file_base . '.json';
			$json_file_path = $category_dir . '/' . $pattern_file_name;

			// Check for existing files with the same name and append a number if necessary.
			$count = 1;
			while ( file_exists( $json_file_path ) ) {
				$count++;
				$pattern_file_name = $pattern_file_base . '-' . $count . '.json';
				$json_file_path = $category_dir . '/' . $pattern_file_name;
			}

			// Save the pattern result to the file. Re-enconded to save as pretty JSON.
			$result = json_decode( $result, true );
			$result = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			file_put_contents( $json_file_path, $result );

			// Add, commit, and push the change.
			run_system_command( [ 'git', 'add', $json_file_path ], $temp_dir );
			run_system_command( [ 'git', 'commit', '-m', 'New pattern: ' . $pattern_file_base ], $temp_dir );
			run_system_command( [ 'git', 'push', 'origin', 'trunk' ], $temp_dir );

			// Clean up by removing the cloned repository directory, if desired
			run_system_command( [ 'rm', '-rf', $temp_dir ], sys_get_temp_dir() );
		} else {
			$output->writeln( "<error>Pattern not found. Aborting!</error>" );
			return 1;
		}

		$output->writeln( '<comment>Done!</comment>' );
	}

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to add the domain to:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Prompts the user for a pattern name in interactive mode.
	 *
	 * @param InputInterface $input The input object.
	 * @param OutputInterface $output The output object.
	 * @return string|null
	 */
	private function prompt_pattern_name_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {

			// Ask for the pattern name, providing an example as a hint
			$question_text = '<question>Enter the pattern name (e.g., "twentytwentyfour/banner-hero"):</question> ';
			$question = new Question( $question_text );

			// Retrieve the user's input
			$pattern_name = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $pattern_name ?? null;
	}

	/**
	 * Prompts the user for a category slug in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_category_slug_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {

			// Provide guidance on the expected format for the category slug.
			$question = new Question( '<question>Enter the category slug (lowercase, hyphens for spaces, e.g., "hero"):</question> ' );

			// Ask the question and retrieve the user's input.
			$category_slug = $this->getHelper( 'question' )->ask( $input, $output, $question );

			// Ensure the input matches the expected format.
			$category_slug = $this->_slugify( $category_slug );
		}

		return $category_slug ?? null;
	}

	/**
	 * Convert a text string to something ready to be used as a unique, machine-friendly identifier.s
	 *
	 * @param string $_text The input text to be slugified.
	 * @return string The slugified version of the input text.
	 */
	protected function _slugify( $_text ) {
		$_slug = strtolower( $_text ); // convert to lowercase
		$_slug = preg_replace( '/\s+/', '-', $_slug ); // convert all contiguous whitespace to a single hyphen
		$_slug = preg_replace( '/[^a-z0-9\-]/', '', $_slug ); // Lowercase alphanumeric characters and dashes are allowed.
		$_slug = preg_replace( '/-+/', '-', $_slug ); // convert multiple contiguous hyphens to a single hyphen

		return $_slug;
	}
}
