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
			$output->writeln(
				'❌ Failed to retrieve themes',
				OutputInterface::VERBOSITY_QUIET
			);
			return null;
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
						$css_content = GitHub_API_Helper::call_api( $file->url, 'GET' );
						var_dump( $css_content );

						if ( preg_match( '/Template:\s*(.*)/i', $css_content, $matches ) ) {
							$empty_template_value = trim( $matches[1] ) === '';
						}
					}
				}

				if ( $theme_json_exists && ! $inc_patterns_exists && $empty_template_value ) {
					$output->writeln( $theme->name, OutputInterface::VERBOSITY_NORMAL );
				}
			}
		}
		return 0;
	}

}
