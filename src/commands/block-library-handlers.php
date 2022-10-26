<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class Block_Library_Handlers extends Command {

	/**
	 * The command name, e.g `team51 block-library`
	 *
	 * @var string
	 */
	protected static $defaultName = 'block-library';

	/**
	 * The API helper class for github connections.
	 *
	 * @var API_Helper
	 */
	protected $api_helper;

	protected function configure(): void {

		// Setup input vars and descriptions for the command.
		$this
		->setDescription( 'Functions for updating/modifying the team51 block-library.' )
		->setHelp( 'Coming soon.' )
		->addOption( 'list', null, InputOption::VALUE_NONE, 'You can view a dump of missing blocks by passing --list' )
		->addOption( 'list-issue', null, InputOption::VALUE_NONE, 'You can create a GH issue of missing blocks using --list-issue' )
		->addOption( 'update-library-from-issue', null, InputOption::VALUE_REQUIRED, 'Update the block library from items in an issue list --update-library-from-issue={$ISSUE_ID}' );

		// Give all methods access to the API Helper.
		$this->api_helper = new API_Helper();
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		// Ensure we're picking up some flags
		if (
			empty( $input->getOption( 'list' ) ) &&
			empty( $input->getOption( 'list-issue' ) ) &&
			empty( $input->getOption( 'update-library-from-issue' ) )
		) {
			$output->writeln( '<error>Please set a flag so the command has a purpose, use -h for a list of tasks.</error>' );
			exit;
		}

		// Ensure an API token is available.
		if ( ! defined( 'GITHUB_API_TOKEN' ) ) {
			$output->writeln( '<error>GITHUB_API_TOKEN not set in config.</error>' );
			return;
		}

		// Updating the block library.
		if ( ! empty( $input->getOption( 'update-library-from-issue' ) ) ) {
			$missing_blocks_json = $this->get_data_from_issue( $input->getOption( 'update-library-from-issue' ), $output );

			if ( empty( $missing_blocks_json ) ) {
				$output->writeln( '<error>Issue not found or no missing blocks found.</error>' );
				return;
			}

			print_r( json_encode( $missing_blocks_json ) );

			return;
		}

		$existing_blocks = $this->get_existing_blocks_array();

		// Tell the user we're starting.
		$output->writeln( '<info>Beginning search for block.json files in our repos...</info>' );

		// Construct Github API query vars.
		$github_endpoint    = 'search/code';
		$github_query       = '?q=' . urlencode( 'filename:block extension:json org:a8cteam51' );
		$github_per_page    = 100;
		$github_search_page = 0;
		$total_items        = 0;
		$github_total_items = 1;
		$found_blocks_paths = array();

		// Loop the API query for paged results (100 max per page)
		while ( $total_items < $github_total_items ) {

			$github_search_page++;

			// Tell the user which search page we're on.
			$output->writeln( "<info>Searching results page {$github_search_page}...</info>" );

			$data = $this->api_helper->call_github_api(
				sprintf(
					'%s%s&per_page=%d&page=%d',
					$github_endpoint,
					$github_query,
					$github_per_page,
					$github_search_page
				),
				null,
				'GET'
			);

			// If nothing was found, exit the command.
			if ( ! isset( $data->items ) ) {
				$output->writeln( '<error>No block.json files were found in our repos.</error>' );
				return;
			}

			// Update vars for loop.
			$github_total_items = $data->total_count;
			$total_items       += $github_per_page;

			// Update our stored data.
			foreach ( $data->items as $result ) {

				// Don't include if this is in a build folder.
				if ( false !== strpos( $result->path, '/build/' ) ) {
					continue;
				}

				$found_blocks_paths[] = $result->repository->html_url . '/blob/trunk/' . $result->path;
			}
		}

		$output->writeln( "<info>Search found a total of {$github_total_items} block.json files in our repos...</info>" );

		// Show the user an error if the blocks didn't return paths.
		if ( empty( $found_blocks_paths ) ) {
			$output->writeln( '<error>Error: No block.json paths found.</error>' );
			return;
		}

		// $found_blocks_paths now contains an array of the paths to block.json files in our repos.

		// Compare $existing_blocks_paths with $found_blocks
		$diff = array_diff( $found_blocks_paths, $existing_blocks );

		// Show the diff the the user if the --list flag is set.
		if ( ! empty( $input->getOption( 'list' ) ) ) {
			$output->writeln( '<info>The following files have not been added into the block library.</info>' );
			$output->writeln( print_r( $diff, true ) );
		}

		// Post an issue to Github.
		if ( ! empty( $input->getOption( 'list-issue' ) ) ) {

			$issue = $this->api_helper->call_github_api(
				'repos/a8cteam51/team51-block-directory/issues',
				array(
					'owner' => 'a8cteam51',
					'repo'  => 'team51-block-directory',
					'title' => 'Missing blocks as of ' . date( 'l jS \of F Y h:i:s A' ),
					'body'  => implode(
						"\r\n",
						array_map(
							function( $item ) {
								return '- [ ] [' . $item . '](' . $item . ')';
							},
							$diff
						)
					),
				),
				'POST'
			);

			if ( isset( $issue->html_url ) ) {
				$output->writeln( "<info>New issue created at {$issue->html_url}</info>" );
			}
		}

	}

	/**
	 * Get the current existing blocks data from the block library.
	 *
	 * @return array Existing blocks data.
	 */
	protected function get_existing_blocks_array(): array {

		// Next we need to get the contents of our blocks JSON file.
		$json_data = $this->api_helper->call_github_api(
			'repos/a8cteam51/team51-block-directory/contents/blocks-array.json',
			null,
			'GET'
		);

		// Show user an error if the blocks JSON file could not be downloaded.
		if ( ! isset( $json_data->content ) || empty( $json_data->content ) ) {
			return array();
		}

		$existing_blocks = json_decode( base64_decode( $json_data->content ), true );

		// Map existing blocks to match the format of our captured blocks.
		foreach ( $existing_blocks as $cat ) {

			if ( ! is_array( $cat ) ) {
				continue;
			}

			foreach ( $cat as $block ) {

				if ( ! is_array( $block ) || ! isset( $block['link'] ) ) {
					continue;
				}

				$existing_blocks_paths[] = $block['link'];
			}
		}

		return ( isset( $existing_blocks_paths ) ) ? $existing_blocks_paths : array();

	}

	protected function get_data_from_issue( $issue_id, $output ) {

		$blocks_data = array();
		$output->writeln( '<info>Fetching issue data...</info>' );

		$issue_data = $this->api_helper->call_github_api(
			'repos/a8cteam51/team51-block-directory/issues/' . $issue_id,
			null,
			'GET'
		);

		// Exit early if nothing was found.
		if ( ! isset( $issue_data->body ) || empty( $issue_data->body ) ) {
			return $blocks_data;
		}

		// Extract block.json URLs
		preg_match_all( '#\(.*?\)#', $issue_data->body, $matches );

		foreach ( $matches[0] as $index => $item ) {

			$output->writeln( '<info>Fetching data for block ' . $index . '...</info>' );

			// Clean up block.json URL
			$item = str_replace(
				array(
					'(',
					')',
					'https://github.com/a8cteam51/',
					'/blob/trunk/',
				),
				array(
					'',
					'',
					'repos/a8cteam51/',
					'/contents/',
				),
				$item
			);

			$json_data = $this->api_helper->call_github_api( $item, null, 'GET' );

			// Show user an error if the blocks JSON file could not be downloaded.
			if ( ! isset( $json_data->content ) || empty( $json_data->content ) ) {
				return array();
			}

			$blocks_data[] = array_merge(
				json_decode( base64_decode( $json_data->content ), true ),
				array(
					'link' => $item,
				)
			);

		}

		return $blocks_data;
	}
}
