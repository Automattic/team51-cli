<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Team51\Helper\get_github_repository;
use Team51\Helper\GitHub_API_Helper;
use function Team51\Helper\maybe_define_console_verbosity;

/**
 * CLI command for listing Automattic authored WordPress 6.0+ compatible themes.
 */
class FSE_Starter_Themes extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'fse-starter-themes'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	// endregion

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Produce a list of Team 51 recommended FSE starter themes.' )
			->setHelp( 'This command produces a list of acceptable FSE themes to be used as a starter themes for no-code theme builds.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$owner      = 'Automattic';
		$repository = 'themes';

		$themes = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s/contents', $owner, $repository ) );
		if ( is_null( $themes ) ) {
			$output->writeln( '❌ Failed to retrieve themes', OutputInterface::VERBOSITY_QUIET );
			return 1;
		}

		foreach ( $themes as $theme ) {
			if ( 'dir' === $theme->type ) {
				$theme_files = GitHub_API_Helper::call_api( sprintf( 'repos/%s/%s/contents/%s', $owner, $repository, $theme->name ) );

				if ( is_null( $theme_files ) ) {
					$output->writeln(
						'❌ Failed to retrieve theme contents',
						OutputInterface::VERBOSITY_QUIET
					);
					continue;
				}

				$theme_json_exists    = false;
				$inc_patterns_exists  = false;
				$empty_template_value = true;
				foreach ( $theme_files as $file ) {
					if ( 'theme.json' === $file->name ) {
						$theme_json_exists = true;
					}
					if ( $file->path === $theme->name . '/inc/patterns' ) {
						$inc_patterns_exists = true;
					}
					if ( 'style.css' === $file->name ) {
						$css_content_response = $this->call_github_api( $file->url );

						if ( isset( $css_content_response->content ) ) {
							$css_content = base64_decode( $css_content_response->content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						}

						if ( isset( $css_content ) ) {
							// Match "Theme Name:"
							if ( preg_match( '/Theme Name:\s*(.*)/i', $css_content, $matches ) ) {
								$theme_name = trim( $matches[1] );
							}

							// Match "Theme URI:"
							if ( preg_match( '/Theme URI:\s*(.*)/i', $css_content, $matches ) ) {
								$theme_uri = trim( $matches[1] );
							}
						}

						if ( preg_match( '/Template:\s*(.*)/i', $css_content, $matches ) ) {
							$empty_template_value = trim( $matches[1] ) === '';
						}
					}
				}

				if ( $theme_json_exists && ! $inc_patterns_exists && $empty_template_value ) {
					$output->writeln( $theme->name, OutputInterface::VERBOSITY_NORMAL );
					$output->writeln( $theme_uri, OutputInterface::VERBOSITY_NORMAL );
					$output->writeln( $theme_name, OutputInterface::VERBOSITY_NORMAL );
				}
			}
		}
		return 0;
	}

	protected function call_github_api( string $url ): ?object {
		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_HTTPHEADER     => array(
					'Accept: application/vnd.github+json',
					'Content-Type: application/json',
					'Authorization: Bearer ' . GITHUB_API_TOKEN,
					'X-GitHub-Api-Version: 2022-11-28',
					'User-Agent: PHP',
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => false,
				CURLOPT_FAILONERROR    => true,
				CURLOPT_ENCODING       => '',
				CURLOPT_MAXREDIRS      => 10,
				CURLOPT_TIMEOUT        => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST  => 'GET',
			)
		);

		$response = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec

		if ( curl_errno( $ch ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
			echo 'Error:' . curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl, WordPress.Security.EscapeOutput.OutputNotEscaped_curl_error
		}

		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		return json_decode( $response ) ? json_decode( $response ) : null;
	}

}
