<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\WPCOM_API_Helper;

use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\get_wpcom_site_from_input;

final class WPCOM_Get_Stickers extends Command {
	use \Team51\Helper\Autocomplete;

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'wpcom:get-stickers';

	/**
	 * The site object.
	 *
	 * @link https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/
	 *
	 * @var object|null
	 */
	protected ?object $wpcom_site = null;

	/**
	 * {@inheritdoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Get a list of a site\'s stickers.' )
			->setHelp( 'This command allows you get a list of stickers associated with a site given a site ID or URL.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site for which get stickers associated.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->wpcom_site = get_wpcom_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );

		if ( \is_null( $this->wpcom_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->wpcom_site->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$stickers = WPCOM_API_Helper::call_api(
			sprintf( WPCOM_GET_STICKERS_URL, $this->wpcom_site->ID )
		);

		if ( empty( $stickers ) ) {
			$output->writeln( '<comment>Site has no stickers associated.<comment>' );
			exit;
		}

		$sticker_table = new Table( $output );
		$sticker_table->setStyle( 'box-double' )
			->setHeaderTitle( 'Stickers' )
			->setHeaders( array( "ID: {$this->wpcom_site->ID} ({$this->wpcom_site->URL})" ) )
			->setRows( array_map( fn ( $sticker ) => array( $sticker ), $stickers ) )
			->render();
	}

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site ID or URL to query for stickers:</question> ' );
			$site     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}
}
