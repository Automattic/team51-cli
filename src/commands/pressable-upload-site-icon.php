<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Team51\Helper\Pressable_Connection_Helper;
use function Team51\Helper\get_pressable_site_from_input;
use function Team51\Helper\get_pressable_sites;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for uploading the site icon as apple-touch-icon.png on a Pressable site.
 */
final class Pressable_Upload_Site_Icon extends Command {

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'pressable:upload-site-icon'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Uploads the site icon as apple-touch-icon.png on a Pressable site.' );
		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to upload the icon to.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_from_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );
	}

	/**
	 * {@inheritDoc}
     */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=green;options=bold>Uploading apple-touch-icon.png to {$this->pressable_site->url}</>" );

		// First, check if the site already has an `apple-touch-icon.png` in its root.
		$sftp = Pressable_Connection_Helper::get_sftp_connection( $this->pressable_site->id );
		if ( \is_null( $sftp ) ) {
			$output->writeln( '<error>Could not connect to the SFTP server.</error>' );
			return 1;
		}

		$output->writeln( '<fg=green;options=bold>SFTP connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

		if ( $sftp->file_exists( 'apple-touch-icon.png' ) ) {
			$output->writeln( '<comment>apple-touch-icon.png already exists. Aborting.</comment>' );
			return 1;
		}

		// If the site doesn't have one, grab the site icon URL and download it.
		$ssh = Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return 1;
		}
		$output->writeln( '<fg=green;options=bold>SSH connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

		$output->writeln( '<info>Getting site icon URL...</info>' );

		$url = $ssh->exec( "wp --skip-themes --skip-plugins eval 'echo get_site_icon_url(180);'" );
		$ssh->disconnect();

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$output->writeln( '<error>Site has no icon set. Aborting.</error>' );
			$output->writeln( "<error>Error with URL: $url</error>", OutputInterface::VERBOSITY_VERY_VERBOSE );
			return 1;
		}

		$output->writeln( "<fg=green;options=bold>Site icon URL: $url</>", OutputInterface::VERBOSITY_VERBOSE );

		$output->writeln( '<info>Downloading site icon...</info>' );
		$file_data = \file_get_contents( $url );
		if ( false === $file_data ) {
			$output->writeln( '<error>Could not download the site icon. Aborting.</error>' );
			return 1;
		}

		$image = $this->process_image( $file_data );
		if ( false === $image ) {
			$output->writeln( '<error>Could not process the site icon. Aborting.</error>' );
			return 1;
		}

		// If site icon is set and downloaded successfully, upload it to the site.
		$output->writeln( '<info>Uploading site icon through SFTP...</info>' );
		$result = $sftp->put( 'apple-touch-icon.png', $image );
		$sftp->disconnect();

		if ( false === $result ) {
			$output->writeln( '<error>Could not upload the site icon.</error>' );
			return 1;
		}

		$output->writeln( '<info>Site icon uploaded successfully.</info>' );
		$output->writeln( "<info>URL: https://{$this->pressable_site->url}/apple-touch-icon.png</info>", OutputInterface::VERBOSITY_VERBOSE );

		return 0;
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
			$question = new Question( '<question>Enter the site ID or URL to rotate the passwords on:</question> ' );
			$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

			$site = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	/**
	 * Processes the image data so that images are converted to PNGs if needed.
	 *
	 * @param string $data The image data.
	 *
	 * @return string|false
	 */
	private function process_image( string $data ) {
		$image = imagecreatefromstring( $data );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		ob_start();
		imagepng( $image );
		$image_data = ob_get_contents();
		ob_end_clean();

		return $image_data;
	}
}
