<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\WPCOM_API_Helper;

use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\get_wpcom_site_from_input;
use function Team51\Helper\get_string_input;

final class WPCOM_Add_Sticker extends Command {
	use \Team51\Helper\Autocomplete;

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'wpcom:add-sticker';


	/**
	 * The sticker to add.
	 *
	 * @var string|null
	 */
	protected ?string $sticker = null;

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
		$this->setDescription( 'Add the specified sticker to the site.' )
			->setHelp( 'This command allows you add a blog sticker to a site given a site ID or URL.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to add a sticker.' )
			->addArgument( 'sticker', InputArgument::REQUIRED, 'Sticker to add to the site.' );
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

		$this->sticker = get_string_input( $input, $output, 'sticker', fn() => $this->prompt_sticker_input( $input, $output ) );

		if ( empty( $this->sticker ) ) {
			$output->writeln( '<error>Sticker not provided.</error>' );
			exit( 1 );
		}

		$input->setArgument( 'sticker', $this->sticker );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$response = WPCOM_API_Helper::call_api(
			sprintf( WPCOM_ADD_STICKER_URL, $this->wpcom_site->ID, $this->sticker ),
			'POST'
		);

		if ( is_object( $response ) && $response->success ) {
			$output->writeln( "<info>Successfully added `{$this->sticker}` on site `{$this->wpcom_site->ID}`<info>" );
		} else {
			$output->writeln( "<comment>Couldn't add `{$this->sticker}` on site `{$this->wpcom_site->ID}`<comment>" );
		}

		return Command::SUCCESS;
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
			$question = new Question( '<question>Enter the site ID or URL to add a sticker:</question> ' );
			$site     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Prompts the user for a sticker if in interactive mode.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return string|null
	 */
	private function prompt_sticker_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the sticker to add to the site:</question> ' );
			$sticker  = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $sticker ?? null;
	}
}
