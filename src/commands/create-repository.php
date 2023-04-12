<?php

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;
use Team51\Helper\API_Helper;
use function Team51\Helper\create_github_repository_from_template;
use function Team51\Helper\create_github_repository_label;
use function Team51\Helper\delete_github_repository_label;
use function Team51\Helper\get_enum_input;
use function Team51\Helper\get_github_repository;
use function Team51\Helper\get_string_input;
use function Team51\Helper\maybe_define_console_verbosity;
use function Team51\Helper\run_app_command;
use function Team51\Helper\run_system_command;
use function Team51\Helper\update_github_repository;

/**
 * CLI command for creating a new GitHub repository.
 */
class Create_Repository extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	protected static $defaultName = 'create-repository'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The new repository's slug.
	 *
	 * @var string|null
	 */
	protected ?string $repo_slug = null;

	/**
	 * The new repository's type. One of either `project` or `issues`.
	 *
	 * @var string|null
	 */
	protected ?string $repo_type = null;

	/**
	 * The new repository's description.
	 *
	 * @var string|null
	 */
	protected ?string $repo_description = null;

	/**
	 * The production URL of the new repository's site.
	 *
	 * @var string|null
	 */
	protected ?string $site_production_url = null;

	/**
	 * The development URL of the new repository's site.
	 *
	 * @var string|null
	 */
	protected ?string $site_development_url = null;

	/**
	 * The long prefix for global PHP variables inside the project.
	 *
	 * @var string|null
	 */
	protected ?string $php_globals_long_prefix = null;

	/**
	 * The short prefix for global PHP variables inside the project.
	 *
	 * @var string|null
	 */
	protected ?string $php_globals_short_prefix = null;

	/**
	 * Whether to create a new Pressable production site and configure it in DeployHQ.
	 *
	 * @var bool|null
	 */
	protected ?bool $create_production_site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new GitHub repository on github.com in the organization specified by the GITHUB_API_OWNER constant.' )
			->setHelp( 'This command allows you to create a new Github repository.' );

		$this->addArgument( 'repo-slug', InputArgument::REQUIRED, 'Repository name in slug form (e.g. client-name).' )
			->addOption( 'repo-description', null, InputOption::VALUE_REQUIRED, 'A short, human-friendly description for this project.' )
			->addOption( 'repo-type', null, InputOption::VALUE_REQUIRED, 'The type of repository to create. One of either `project` or `issues`.', 'project' );

		$this->addOption( 'site-production-url', null, InputArgument::REQUIRED, 'The hostname of the intended production site (do not include http/https, e.g. example.com).' )
			->addOption( 'site-development-url', null, InputArgument::REQUIRED, 'The hostname of the intended development site (do not include http/https, e.g. development-example.com).' )
			->addOption( 'php-long-prefix', null, InputOption::VALUE_REQUIRED, 'The long prefix for global PHP variables inside the project.' )
			->addOption( 'php-short-prefix', null, InputOption::VALUE_REQUIRED, 'The short prefix for global PHP variables inside the project.' )
			->addOption( 'create-production-site', null, InputOption::VALUE_NONE, 'This script can optionally create a new Pressable production site and configure it in DeployHQ by passing --create-production-site.' );

		// region LEGACY OPTIONS
		$this->addOption( 'issue-repo-only', null, InputOption::VALUE_NONE, 'Is this a repository to track project issues only with no associated code?' )
			->addOption( 'production-url', null, InputOption::VALUE_REQUIRED, 'The hostname of the intended production site (do not include http/https, e.g. example.com).' )
			->addOption( 'development-url', null, InputOption::VALUE_REQUIRED, 'The hostname of the intended development site (do not include http/https, e.g. development-example.com).' )
			->addOption( 'custom-theme-slug', null, InputOption::VALUE_REQUIRED, 'If this project involves us building a custom WordPress theme, pass the theme-slug with --custom-theme-slug=theme-slug.' )
			->addOption( 'custom-plugin-slug', null, InputOption::VALUE_REQUIRED, 'If this project involves us building a custom WordPress plugin, pass the plugin-slug with --custom-plugin-slug=plugin-slug.' );
		// endregion
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		// Retrieve and validate the modifier options.
		$this->repo_type = get_enum_input( $input, $output, 'repo-type', array( 'project', 'issues' ), 'project' );
		if ( ! empty( $input->getOption( 'issue-repo-only' ) ) ) { // Legacy option.
			$this->repo_type = 'issues';
			$input->setOption( 'repo-type', 'issues' );
		}

		// Retrieve and validate the repo slug.
		$this->repo_slug = get_string_input( $input, $output, 'repo-slug', fn() => $this->prompt_slug_input( $input, $output ) );
		if ( empty( $this->repo_slug ) ) {
			$output->writeln( '<error>A repository slug is required. Aborting!</error>' );
			exit( 1 );
		}

		$this->repo_slug = strtolower( $this->repo_slug );
		if ( ! \is_null( get_github_repository( GITHUB_API_OWNER, $this->repo_slug ) ) ) {
			$output->writeln( "<error>Repository $this->repo_slug already exists in GitHub org. Please choose a different repository name. Aborting!</error>" );
			exit( 1 );
		}

		$input->setArgument( 'repo-slug', $this->repo_slug );

		// Retrieve and validate project-specific options.
		if ( 'project' === $this->repo_type ) {
			$this->site_production_url = get_string_input( $input, $output, 'site-production-url', fn() => $this->prompt_production_url_input( $input, $output ) );
			if ( false !== strpos( $this->site_production_url, 'http' ) ) {
				$this->site_production_url = parse_url( $this->site_production_url, PHP_URL_HOST );
			}

			$this->site_development_url = get_string_input( $input, $output, 'site-development-url', fn() => $this->prompt_development_url_input( $input, $output ) );
			if ( false !== strpos( $this->site_development_url, 'http' ) ) {
				$this->site_development_url = parse_url( $this->site_development_url, PHP_URL_HOST );
			}

			$this->php_globals_long_prefix  = $input->getOption( 'php-long-prefix' ) ?: str_replace( '-', '_', $this->repo_slug );
			$this->php_globals_short_prefix = $input->getOption( 'php-short-prefix' ) ?: $this->php_globals_long_prefix;
			if ( $this->php_globals_long_prefix === $this->php_globals_short_prefix ) {
				if ( 2 <= substr_count( $this->php_globals_long_prefix, '_' ) ) {
					$this->php_globals_short_prefix = '';
					foreach ( explode( '_', $this->php_globals_long_prefix ) as $part ) {
						$this->php_globals_short_prefix .= $part[0];
					}
				} else {
					$this->php_globals_short_prefix = explode( '_', $this->php_globals_long_prefix )[0];
				}
			}
		}

		// Retrieve any other options.
		$this->repo_description = $input->getOption( 'repo-description' ) ?: '';
		if ( 'project' === $this->repo_type ) {
			$this->create_production_site = $input->getOption( 'create-production-site' ) ?: false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating a new GitHub $this->repo_type repository with the slug $this->repo_slug</>" );

		// Create the repository from template.
		$output->writeln( "<comment>Creating $this->repo_slug repository from template.</comment>" );

		$repository = create_github_repository_from_template( GITHUB_API_OWNER, $this->repo_slug, GITHUB_API_OWNER, "team51-$this->repo_type-scaffold", $this->repo_description );
		if ( \is_null( $repository ) ) {
			$output->writeln( "<error>Failed to create $this->repo_slug repository from template. Aborting!</error>" );
			return 1;
		}

		$repository = update_github_repository(
			GITHUB_API_OWNER,
			$this->repo_slug,
			array_filter(
				array(
					'homepage'     => $this->site_production_url,
					'has_issue'    => true,
					'has_projects' => true,
					'has_wiki'     => true,
				)
			)
		);
		if ( \is_null( $repository ) ) {
			$output->writeln( "<error>Failed to update $this->repo_slug repository. Aborting!</error>" );
			return 1;
		}

		$output->writeln( "<fg=green;options=bold>Successfully created $this->repo_slug repository from template.</>" );

		// Clone the repository and make some tweaks.
		$output->writeln( "<comment>Cloning $this->repo_slug repository and making some tweaks.</comment>" );
		run_system_command( array( 'git', 'clone', $repository->ssh_url, TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ) ); // Clone the repository.

		run_system_command( array( 'git', 'submodule', 'init' ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Initialize the submodules.
		run_system_command( array( 'git', 'submodule', 'update' ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Fetch submodule updates.

		run_system_command( array( 'mv', './themes/build-processes-demo', "./themes/$this->repo_slug" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Rename the theme directory.
		run_system_command( array( 'mv', './mu-plugins/build-processes-demo-blocks', "./mu-plugins/$this->repo_slug-blocks" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Rename the blocks plugin directory.
		run_system_command( array( 'mv', './mu-plugins/build-processes-demo-features', "./mu-plugins/$this->repo_slug-features" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Rename the features plugin directory.

		// Replace the placeholders in the scaffold files.
		foreach ( ( new Finder() )->files()->ignoreDotFiles( false )->in( TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ) as $file ) {
			file_put_contents(
				$file->getRealPath(),
				str_replace(
					array(
						'A demo project for showcasing standardized build processes for various asset types.',
						'build-processes-demo-production.mystagingwebsite.com',
						'build-processes-demo',
						'build_processes_demo',
						'bpd',
						'BPD',
					),
					array(
						$this->repo_description,
						$this->site_production_url,
						$this->repo_slug,
						$this->php_globals_long_prefix,
						$this->php_globals_short_prefix,
						strtoupper( $this->php_globals_short_prefix ),
					),
					$file->getContents()
				)
			);
		}

		// Replace the scaffold README with the new README.
		$readme = file_get_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_type-README.md" );
		$readme = str_replace(
			array( 'EXAMPLE_REPO_NAME', 'EXAMPLE_REPO_PROD_URL', 'EXAMPLE_REPO_DEV_URL' ),
			array( $this->repo_slug, $this->site_production_url, $this->site_development_url ),
			$readme
		);
		file_put_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug/README.md", $readme );

		run_system_command( array( 'composer', 'run-script', 'packages-update' ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Ensure that the composer packages are up-to-date.

		run_system_command( array( 'git', 'add', '.' ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Stage the changes.
		run_system_command( array( 'git', 'commit', "-m 'Tweaked project files from template'" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Commit the changes.
		run_system_command( array( 'git', 'push', '-u', 'origin', '--all' ), TEAM51_CLI_ROOT_DIR . "/scaffold/$this->repo_slug" ); // Push changes to GitHub.
		run_system_command( array( 'rm', '-rf', $this->repo_slug ), TEAM51_CLI_ROOT_DIR . '/scaffold/' ); // Remove the scaffold directory.

		$output->writeln( '<comment>Configuring GitHub repository labels.</comment>' );

		$progress_bar = new ProgressBar( $output );

		foreach ( array( 'good first issue', 'help wanted', 'invalid' ) as $label ) {
			delete_github_repository_label( GITHUB_API_OWNER, $this->repo_slug, $label );
			$progress_bar->advance();
		}
		foreach ( $this->get_new_repository_labels() as $label ) {
			create_github_repository_label( GITHUB_API_OWNER, $this->repo_slug, $label['name'], $label['color'], $label['description'] ?? null );
			$progress_bar->advance();
		}

		$progress_bar->finish();
		$output->writeln( '' );

		$output->writeln( "<fg=green;options=bold>GitHub repository creation and setup is complete! Check it out here: $repository->html_url</>" );

		$output->writeln( '<comment>Logging GitHub init script completion to Slack.</comment>' );
		( new API_Helper() )->log_to_slack( "INFO: GitHub repo init run for $repository->html_url." );

		if ( true === $this->create_production_site ) {
			$output->writeln( '<comment>Creating and configuring new Pressable site.</comment>' );

			/* @noinspection PhpUnhandledExceptionInspection */
			run_app_command(
				$this->getApplication(),
				Create_Production_Site::getDefaultName(),
				array(
					'--site-name'       => $this->repo_slug,
					'--connect-to-repo' => $this->repo_slug,
				),
				$output
			);
		}

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a repository slug if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	protected function prompt_slug_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the new repository slug:</question> ' );
			$slug     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $slug ?? null;
	}

	/**
	 * Prompts the user for a site production URL if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	protected function prompt_production_url_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( ! empty( $input->getOption( 'production-url' ) ) ) { // Default to the legacy option, if set.
			$site_url = $input->getOption( 'production-url' );
		} elseif ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site production URL:</question> ' );
			$site_url = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site_url ?? null;
	}

	/**
	 * Prompts the user for a site development URL if in interactive mode.
	 *
	 * @param   InputInterface      $input      The input object.
	 * @param   OutputInterface     $output     The output object.
	 *
	 * @return  string|null
	 */
	protected function prompt_development_url_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( ! empty( $input->getOption( 'development-url' ) ) ) { // Default to the legacy option, if set.
			$site_url = $input->getOption( 'development-url' );
		} elseif ( $input->isInteractive() ) {
			$question = new Question( '<question>Enter the site development URL:</question> ' );
			$site_url = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site_url ?? null;
	}

	/**
	 * Returns the list of new repository labels.
	 *
	 * @return  array[]
	 */
	protected function get_new_repository_labels(): array {
		return array(
			array(
				'name'        => 'content',
				'description' => 'Any cms tasks not handled in code',
				'color'       => '006b75',
			),
			array(
				'name'        => 'design',
				'description' => 'Design-related tasks',
				'color'       => 'd4c5f9',
			),
			array(
				'name'        => 'in progress',
				'description' => 'A work-in-progress - not ready for merge!',
				'color'       => 'f9c581',
			),
			array(
				'name'  => 'high priority',
				'color' => 'd93f0b',
			),
			array(
				'name'        => 'launch task',
				'description' => 'To be completed on launch day',
				'color'       => 'c2e0c6',
			),
			array(
				'name'  => 'low priority',
				'color' => 'f9d0c4',
			),
			array(
				'name'  => 'medium priority',
				'color' => 'fbca04',
			),
			array(
				'name'        => 'needs review',
				'description' => 'Pre-merge sanity check',
				'color'       => 'ff9515',
			),
			array(
				'name'        => 'pending confirmation',
				'description' => 'Waiting for approval from client or partner',
				'color'       => 'f799c9',
			),
			array(
				'name'  => 'plugin functionality',
				'color' => 'eb6420',
			),
			array(
				'name'        => 'ready to close',
				'description' => 'No further action needed.',
				'color'       => '128a0c',
			),
			array(
				'name'        => 'ready to merge',
				'description' => 'Approved and ready to launch!',
				'color'       => '70ea76',
			),
			array(
				'name'        => 'ready to revert',
				'description' => 'Feature abandoned. Remove from code base',
				'color'       => 'cc317c',
			),
			array(
				'name'  => 'theme functionality',
				'color' => 'f7c6c7',
			),
		);
	}

	// endregion
}
