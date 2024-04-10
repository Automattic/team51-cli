<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Team51\Helper\encode_json_content;
use function Team51\Helper\get_flickr_comments_for_photo;
use function Team51\Helper\get_flickr_photo_sizes;
use function Team51\Helper\get_flickr_photos_for_photoset;
use function Team51\Helper\get_flickr_photos_for_user;
use function Team51\Helper\get_flickr_photosets_for_user;
use function Team51\Helper\get_flickr_user_by_username;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for scrapping a Flickr account.
 */
class Flickr_Scrap_Photostream extends Command {
	// region FIELDS AND CONSTANTS
	use \Team51\Helper\Autocomplete;

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'flickr:scrap-photostream'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The user ID of the Flickr account to scrap.
	 *
	 * @var string|null
	 */
	protected ?string $flickr_user_id = null;

	/**
	 * The maximum number of media files to scrap.
	 *
	 * @var int|null
	 */
	protected ?int $limit = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Scraps the photostream of a given Flickr account.' )
			->setHelp( 'This command downloads the photos and videos from a given Flickr account.' );

		$this->addArgument( 'username', InputArgument::REQUIRED, 'The username of the Flickr account to scrap.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of media files to scrap.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$flickr_username = $input->getArgument( 'username' );
		$flickr_user     = get_flickr_user_by_username( $flickr_username );
		if ( empty( $flickr_user ) || empty( $flickr_user->nsid ) ) {
			$output->writeln( "<error>Failed to fetch user ID from Flickr. Username error: $flickr_username</error>" );
			exit( 1 );
		}

		$this->flickr_user_id = $flickr_user->nsid;

		if ( $input->hasOption( 'limit' ) ) {
			$this->limit = (int) $input->getOption( 'limit' );
			$this->limit = abs( $this->limit );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Scraping ' . ( $this->limit ?: 'all' ) . " media from the photostream of user $this->flickr_user_id.</>" );

		// Download photosets information.
		$output->writeln( '<info>Downloading photosets information...</info>' );

		$photosets = get_flickr_photosets_for_user( $this->flickr_user_id );
		if ( \is_null( $photosets ) ) {
			$output->writeln( '<error>Failed to fetch photosets from Flickr.</error>' );
			return Command::FAILURE;
		}

		$photosets_data_directory = TEAM51_CLI_ROOT_DIR . "/flickr/$this->flickr_user_id/photosets";
		if ( ! \file_exists( $photosets_data_directory ) && ! \mkdir( $photosets_data_directory, 0777, true ) && ! \is_dir( $photosets_data_directory ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $photosets_data_directory ) );
		}

		$progress_bar = new ProgressBar( $output, \count( $photosets->photoset ) );
		$progress_bar->start();

		foreach ( $photosets->photoset as $photoset ) {
			$progress_bar->advance();

			$data_file = $photosets_data_directory . "/$photoset->id.json";
			if ( \file_exists( $data_file ) ) {
				continue;
			}

			$photos = array();

			$current_page = 1;
			do {
				$photoset_photos = get_flickr_photos_for_photoset(
					$photoset->id,
					array(
						'per_page' => 500,
						'page'     => $current_page,
					)
				);
				if ( \is_null( $photoset_photos ) ) {
					$output->writeln( "<error>Failed to fetch photos from Flickr. Photoset error: $photoset->id</error>" );
					return Command::FAILURE;
				}

				$photos[] = $photoset_photos->photo;

				++$current_page;
				$has_next_page = $photoset_photos->page < $photoset_photos->pages;
			} while ( $has_next_page );

			\file_put_contents(
				$data_file,
				encode_json_content(
					array(
						'photoset' => $photoset,
						'photos'   => \array_merge( ...$photos ),
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		$progress_bar->finish();
		$output->writeln( '' ); // Empty line for UX purposes.

		// Download photos/videos information.
		$current_page = 1;
		do {
			$output->writeln( "<info>Downloading photostream. Page: $current_page</info>" );

			$extras = array( 'url_o', 'description', 'license', 'date_upload', 'date_taken', 'original_format', 'last_update', 'geo', 'tags', 'machine_tags', 'views', 'media' );
			$extras = \implode( ',', $extras );

			$photos = get_flickr_photos_for_user(
				$this->flickr_user_id,
				array(
					'extras'   => $extras,
					'per_page' => 50,
					'page'     => $current_page,
				)
			);
			if ( \is_null( $photos ) ) {
				$output->writeln( "<error>Failed to fetch photos from Flickr. Page error: $current_page</error>" );
				return Command::FAILURE;
			}

			$progress_bar = new ProgressBar( $output, \min( $this->limit ?? PHP_INT_MAX, \count( $photos->photo ) ) );
			$progress_bar->start();

			foreach ( $photos->photo as $photo ) {
				$media_data_directory = TEAM51_CLI_ROOT_DIR . "/flickr/$this->flickr_user_id/media/$photo->media/$photo->id";
				if ( ! \file_exists( $media_data_directory ) && ! \mkdir( $media_data_directory, 0777, true ) && ! is_dir( $media_data_directory ) ) {
					throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $media_data_directory ) );
				}

				$progress_bar->advance();

				// Save photo meta.
				\file_put_contents(
					$media_data_directory . '/meta.json',
					encode_json_content( $photo, JSON_PRETTY_PRINT )
				);

				// Save photo comments.
				$comments = get_flickr_comments_for_photo( $photo->id );
				if ( \is_null( $comments ) ) {
					$output->writeln( "<error>Failed to fetch comments from Flickr. Photo error: $photo->id</error>" );
					return Command::FAILURE;
				}

				\file_put_contents(
					$media_data_directory . '/comments.json',
					encode_json_content( $comments->comment ?? array(), JSON_PRETTY_PRINT )
				);

				// Download photo/video file.
				if ( 'photo' === $photo->media ) {
					$media_url = $photo->url_o;
				} else { // Video.
					$media_sizes = get_flickr_photo_sizes( $photo->id );
					if ( \is_null( $media_sizes ) ) {
						$output->writeln( "<error>Failed to fetch file sizes. Media error: $photo->id</error>" );
						return Command::FAILURE;
					}

					foreach ( $media_sizes->size as $size ) {
						if ( 'video' === $size->media && $size->height === $photo->height_o ) {
							$media_url = $size->source;
							break;
						}
					}
				}

				$media_file = \file_get_contents( $media_url );
				if ( empty( $media_file ) ) {
					$output->writeln( "<error>Failed to download media. Meida error: $photo->id, Media URL: $media_url</error>" );
					return Command::FAILURE;
				}

				if ( false === \file_put_contents( $media_data_directory . '/media.' . $photo->originalformat, $media_file ) ) {
					$output->writeln( "<error>Failed to save media. Media error: $photo->id</error>" );
					return Command::FAILURE;
				}

				if ( $this->limit ) {
					--$this->limit;
					if ( 0 === $this->limit ) {
						break;
					}
				}
				sleep( 2 ); // Flickr API rate limit. We can make a maximum of 3600 requests per hour or 1 per second.
			}

			$progress_bar->finish();
			$output->writeln( '' ); // Empty line for UX purposes.

			++$current_page;
			$has_next_page = 0 !== $this->limit && $photos->page < $photos->pages;
		} while ( $has_next_page );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	// endregion
}
