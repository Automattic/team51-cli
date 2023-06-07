<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Triage_GraphQL extends Command {
	protected static $defaultName = 'triage';

	const TRIAGE_STATUS           = '🆕 Needs Triaged';
	const IN_PROGRESS_STATUS      = '🏗 In Progress';
	const WAITING_FEEDBACK_STATUS = '⌛ Waiting Feedback';

	protected function configure() {
		$this->setDescription( 'Generates a Digest Post of what upcoming Triage issues we have.' )
			->setHelp( 'Scans the triage column to find due dates in the near future.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		if ( ! defined( 'GITHUB_DEVQUEUE_PROJECT_ID' ) ) {
			$output->writeln( '<error>GITHUB_DEVQUEUE_PROJECT_ID not set in config.</error>' );
			return;
		}

		$api_helper = new API_Helper();

		$output->writeln( '<info>Grabbing Triage Column Items...</info>' );

		$response  = $api_helper->call_github_graphql_api( $this->query( GITHUB_DEVQUEUE_PROJECT_ID ) );
		$all_items = array( $response );

		while ( false !== $response->data->node->items->pageInfo->hasNextPage ) {
			$response = $api_helper->call_github_graphql_api(
				$this->query( GITHUB_DEVQUEUE_PROJECT_ID, $response->data->node->items->pageInfo->endCursor )
			);

			$all_items[] = $response;
		}

		// Merge all nodes from each response.
		$nodes = array_merge( ...array_map( fn ( $response ) => $response->data->node->items->nodes, $all_items ) );

		$triage_nodes   = array_filter( $nodes, fn ( $node ) => array_search( self::TRIAGE_STATUS, array_column( $node->fieldValues->nodes, 'name' ) ) !== false );
		$progress_nodes = array_filter( $nodes, fn ( $node ) => array_search( self::IN_PROGRESS_STATUS, array_column( $node->fieldValues->nodes, 'name' ) ) !== false );
		$feedback_nodes = array_filter( $nodes, fn ( $node ) => array_search( self::WAITING_FEEDBACK_STATUS, array_column( $node->fieldValues->nodes, 'name' ) ) !== false );

		$output->writeln( sprintf( '<comment>"Triage" currently has %d cards.</comment>', count( $triage_nodes ) ) );
		$output->writeln( sprintf( '<comment>"In Progress" currently has %d cards.</comment>', count( $progress_nodes ) ) );
		$output->writeln( sprintf( '<comment>"Waiting Feedback" currently has %d cards.</comment>', count( $feedback_nodes ) ) );

		$issues = array();

		foreach ( $triage_nodes as $node ) {
			$due_date = array_column( $node->fieldValues->nodes, 'date' )[0] ?? 9999;

			$issues[] = array(
				'due_in' => $due_date === 9999 ? $due_date : ( strtotime( $due_date ) - strtotime( 'today' ) ) / ( 24 * 60 * 60 ),
				'title'  => $node->content->title,
				'number' => $node->content->number,
				'url'    => $node->content->url,
				'labels' => array_column( $node->content->labels->nodes, 'name' ),
			);
		}

		$output->writeln( '' );
		$output->writeln( '' );

		usort( $issues, fn ( $a, $b ) => $a['due_in'] - $b['due_in'] );

		foreach ( $issues as $issue ) {
			$type = 'comment';
			if ( $issue['due_in'] <= 0 ) {
				$type = 'error';
			}

			switch ( $issue['due_in'] ) {
				case -1:
					$how_long = 'Due YESTERDAY!';
					break;
				case '0':
					$how_long = 'Due TODAY';
					break;
				case '1':
					$how_long = 'Due Tomorrow';
					break;
				case 9999:
					$how_long = 'No Due Date Specified';
					break;
				default:
					$how_long = "Due in {$issue['due_in']} days";
					break;
			}

			$tags = '';
			if ( sizeof( $issue['labels'] ) ) {
				foreach ( $issue['labels'] as $tag_name ) {
					$tags .= "*{$tag_name}* ";
				}
			}

			$output->writeln(
				sprintf(
					'<%1$s>* %2$d: %6$s[%3$s](%5$s) (%4$s)</%1$s>',
					$type,
					$issue['number'],
					$issue['title'],
					$how_long,
					$issue['url'],
					$tags
				)
			);
		}
	}

	private function query( string $project_id, string $after = '', int $per_page = 100 ) {
		$query = 'query {
			node(id: "%s") {
				... on ProjectV2 {
					items(first: %d, after: "%s") {
						totalCount
						pageInfo {
							hasNextPage
							hasPreviousPage
							endCursor
							startCursor
						}
						nodes {
							fieldValues(first: 10) {
								nodes {
									... on ProjectV2ItemFieldDateValue {
										date
									}
									... on ProjectV2ItemFieldSingleSelectValue {
										name
										field {
											... on ProjectV2FieldCommon {
												name
											}
										}
									}
								}
							}
							content {
								... on Issue {
									title
									number
									url
									labels(first: 10) {
										nodes {
											name
										}
									}
								}
							}
						}
					}
				}
			}
		}';

		return sprintf( $query, $project_id, $per_page, $after );
	}
}
