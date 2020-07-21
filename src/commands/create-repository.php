<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Create_Repository extends Command {
    protected static $defaultName = 'create-repository';

    protected function configure() {
        $this
        ->setDescription( "Creates a new GitHub repository on github.com in the organization specified with GITHUB_API_OWNER." )
        ->setHelp( "This command allows you to create a new Github repository." )
        ->addOption( 'issue-repo-only', null, InputOption::VALUE_NONE, "Is this a repository to track project issues only with no associated code?" )
        ->addOption( 'repo-slug', null, InputOption::VALUE_REQUIRED, "Repository name in slug form (e.g. client-name)?" )
        ->addOption( 'production-url', null, InputOption::VALUE_REQUIRED, "The hostname of the intended production site (do not include http/https, e.g. example.com)." )
        ->addOption( 'development-url', null, InputOption::VALUE_REQUIRED, "The hostname of the intended development site (do not include http/https, e.g. development-example.com)." )
        ->addOption( 'repo-description', null, InputOption::VALUE_REQUIRED, "A short, human-friendly description for this project." )
        ->addOption( 'reset', null, InputOption::VALUE_NONE, "Something go sideways? Start over from scratch and clean up any prior runs." )
        ->addOption( 'custom-theme-slug', null, InputOption::VALUE_REQUIRED, "If this project involves us building a custom WordPress theme, pass the theme-slug with --custom-theme-slug=theme-slug." )
        ->addOption( 'custom-plugin-slug', null, InputOption::VALUE_REQUIRED, "If this project involves us building a custom WordPress plugin, pass the plugin-slug with --custom-plugin-slug=plugin-slug." )
        ->addOption( 'create-production-site', null, InputOption::VALUE_NONE, "This script can optionally create a new Pressable production site and configure it in DeployHQ by passing --create-production-site." );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $filesystem = new Filesystem;

        if( ! empty( $input->getOption( 'reset' ) ) ) {

        }

        $api_helper = new API_Helper;

        if( empty( $input->getOption( 'repo-slug') ) ) {
            $output->writeln( "<error>You must pass a repository slug with --repo-slug.</error>" );
            exit;
        } else {
            $slug = $input->getOption( 'repo-slug');
        }

        // Verify repo we're trying to create doesn't already exist.
        $output->writeln( "<comment>Verifying $slug doesn't exist in GitHub org.</comment>" );

        $repository_exists = $api_helper->call_github_api(
            sprintf( 'repos/%s/%s', GITHUB_API_OWNER, $slug ),
            '',
            'GET'
        );

        if ( ! empty( $repository_exists->id ) ) {
            $output->writeln( "<error>Repository $slug already exists in GitHub org. Please choose a different repository name. Aborting!</error>" );
            exit;
        }

        $output->writeln( "<comment>Creating scaffold/$slug directory.</comment>" );
        $filesystem->mkdir( TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );

        $output->writeln( "<comment>Copying scaffold/templates/github directory to scaffold/$slug/.github.</comment>" );
        $filesystem->mirror( TEAM51_CLI_ROOT_DIR . "/scaffold/templates/github", TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/.github" );

        if( empty( $input->getOption( 'issue-repo-only' ) ) ) {
            $output->writeln( "<comment>Copying scaffold/templates/gitignore file to scaffold/$slug/.gitignore.</comment>" );
            $filesystem->copy( TEAM51_CLI_ROOT_DIR . '/scaffold/templates/gitignore', TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/.gitignore" );

            $output->writeln( "<comment>Copying scaffold/templates/.phpcs.xml file to scaffold/$slug/.phpcs.xml.</comment>" );
            $filesystem->copy( TEAM51_CLI_ROOT_DIR . '/scaffold/templates/.phpcs.xml', TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/.phpcs.xml" );

            $output->writeln( "<comment>Copying scaffold/templates/EXAMPLE-README.md file to scaffold/$slug/README.md.</comment>" );
            $filesystem->copy( TEAM51_CLI_ROOT_DIR . '/scaffold/templates/EXAMPLE-README.md', TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/README.md" );

            $readme = file_get_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/README.md" );

            if( empty( $readme ) ) {
                $output->writeln( "<error>Failed to read contents of README.md. Does it exist?</error>" );
                exit;
            }

            $readme = str_replace( array( 'EXAMPLE_REPO_PROD_URL', 'EXAMPLE_REPO_DEV_URL', 'EXAMPLE_REPO_NAME' ), array( $input->getOption( 'production-url'), $input->getOption( 'development-url'), $slug ), $readme );

            $output->writeln( "<comment>Creating repository README.</comment>" );
            file_put_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/README.md", $readme );

            $gitignore = file_get_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/.gitignore" );

            if( ! empty( $input->getOption( 'custom-theme-slug' ) ) ) {
                $output->writeln( "<comment>Adding custom theme slug {$input->getOption( 'custom-theme-slug' )} to .gitignore.</comment>" );
                $gitignore = str_replace( 'EXAMPLE_THEME_NAME', $input->getOption( 'custom-theme-slug' ), $gitignore );
            } else {
                $gitignore = str_replace( '!themes/EXAMPLE_THEME_NAME', '', $gitignore );
            }

            if( ! empty( $input->getOption( 'custom-plugin-slug' ) ) ) {
                $output->writeln( "<comment>Adding custom plugin slug {$input->getOption( 'custom-plugin-slug' )} to .gitignore.</comment>" );
                $gitignore = str_replace( 'EXAMPLE_PLUGIN_NAME', $input->getOption( 'custom-plugin-slug' ), $gitignore );
            } else {
                $gitignore = str_replace( '!plugins/EXAMPLE_PLUGIN_NAME', '', $gitignore );
            }

            file_put_contents( TEAM51_CLI_ROOT_DIR . "/scaffold/$slug/.gitignore", $gitignore );
        }

        $output->writeln( "<info>Local setup complete! Now we need to create and populate the repository on GitHub.</info>" );

        $output->writeln( "<comment>Creating GitHub repository.</comment>" );
        if( empty( $input->getOption( 'issue-repo-only' ) ) ) {
            $response = $api_helper->call_github_api( 'orgs/' . GITHUB_API_OWNER . '/repos', array(
                'name' => $slug,
                'description' => ! empty( $input->getOption( 'repo-description' ) ) ? $input->getOption( 'repo-description' ) : '',
                'homepage' => $input->getOption( 'production-url' ),
                'private' => true,
                'has_issue' => true,
                'has_projects' => true,
                'has_wiki' => true,
                'license_template' => 'gpl-3.0',
                'team_id' => GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY,
            ));
        } else {
            $response = $api_helper->call_github_api( 'orgs/' . GITHUB_API_OWNER . '/repos', array(
                'name' => $slug,
                'description' => ! empty( $input->getOption( 'repo-description' ) ) ? $input->getOption( 'repo-description' ) : '',
                'homepage' => '',
                'private' => true,
                'has_issue' => true,
                'has_projects' => true,
                'has_wiki' => true,
                'auto_init' => true,
                'license_template' => 'gpl-3.0',
                'team_id' => GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY,
            ));
        }

        if( ! empty( $response->id ) ) {
            $output->writeln( "<info>Successfully created repository on GitHub (" . GITHUB_API_OWNER . "/$slug).</info>" );
        } else {
            $output->writeln( "<error>Failed to create GitHub repository $slug. Aborting!</error>");
            //exit;
        }

        $ssh_url = $response->ssh_url;
        $html_url = $response->html_url;

        $output->writeln( "<comment>Adding, committing, and pushing files to GitHub.</comment>" );

        $progress_bar = new ProgressBar( $output, 7 );
        $progress_bar->start();

        $this->execute_command( array( "git", "init", TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" ) );
        $progress_bar->advance();

        $this->execute_command( array( "git", "remote", "add", "origin", "$ssh_url" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $this->execute_command( array( "git", "pull", "origin", "master" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $this->execute_command( array( "git", "add", "." ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $this->execute_command( array( "git", "commit", "-m 'Added project files from scaffold'" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $this->execute_command( array( "git", "branch", "develop" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $this->execute_command( array( "git", "push", "-u", "origin", "--all" ), TEAM51_CLI_ROOT_DIR . "/scaffold/$slug" );
        $progress_bar->advance();

        $progress_bar->finish();
        $output->writeln( "" );

	$output->writeln( "<comment>Cleaning up scaffold files.</comment>" );
        $this->execute_command( array( "rm", "-rf", "$slug" ), TEAM51_CLI_ROOT_DIR . "/scaffold" );

        $output->writeln( "<comment>Configuring GitHub repository labels.</comment>" );

        $progress_bar = new ProgressBar( $output, 17 );
        $progress_bar->start();

        // TODO: Add error checking on label operations and loop the labels to delete.

        $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/labels/good%20first%20issue", '', 'DELETE' );
        $progress_bar->advance();

        $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/labels/help%20wanted", '', 'DELETE' );
        $progress_bar->advance();

        $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/labels/invalid", '', 'DELETE' );
        $progress_bar->advance();

        $new_labels = array(
            array(
                'name' => 'content',
                'description' => 'Any cms tasks not handled in code',
                'color' => '006b75',
            ),
            array(
                'name' => 'design',
                'description' => 'Design-related tasks',
                'color' => 'd4c5f9',
            ),
            array(
                'name' => 'in progress',
                'description' => 'A work-in-progress - not ready for merge!',
                'color' => 'f9c581',
            ),
            array(
                'name' => 'high priority',
                'color' => 'd93f0b',
            ),
            array(
                'name' => 'launch task',
                'description' => 'To be completed on launch day',
                'color' => 'c2e0c6',
            ),
            array(
                'name' => 'low priority',
                'color' => 'f9d0c4',
            ),
            array(
              'name' => 'medium priority',
              'color' => 'fbca04',
            ),
            array(
              'name' => 'needs review',
              'description' => 'Pre-merge sanity check',
              'color' => 'ff9515',
            ),
            array(
              'name' => 'pending confirmation',
              'description' => 'Waiting for approval from client or partner',
              'color' => 'f799c9',
            ),
            array(
              'name' => 'plugin functionality',
              'color' => 'eb6420',
            ),
            array(
              'name' => 'ready to close',
              'description' => 'No further action needed.',
              'color' => '128a0c',
            ),
            array(
              'name' => 'ready to merge',
              'description' => 'Approved and ready to launch!',
              'color' => '70ea76',
            ),
            array(
              'name' => 'ready to revert',
              'description' => 'Feature abandoned. Remove from code base',
              'color' => 'cc317c',
            ),
            array(
              'name' => 'theme functionality',
              'color' => 'f7c6c7',
            ),
        );

        foreach( $new_labels as $label ) {
            $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/labels", $label );
            $progress_bar->advance();
        }

        $progress_bar->finish();
        $output->writeln( "" );

        $branch_protection_rules = array (
            'required_status_checks' => array (
                'strict' => true,
                'contexts' => array (
                    'Run PHPCS inspection',
                ),
            ),
            'enforce_admins' => null,
            'required_pull_request_reviews' => null,
            'restrictions' => null,
        );

        $output->writeln( "<comment>Adding branch protection rules.</comment>" );
        $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/branches/master/protection", $branch_protection_rules, 'PUT' );

        $output->writeln( "<comment>Logging GitHub init script completion to Slack.</comment>" );
        $api_helper->log_to_slack( "INFO: GitHub repo init run for $html_url." );

	// Copy issues from default issue repository if one is configured.
	if ( defined( 'GITHUB_DEFAULT_ISSUES_REPOSITORY' ) && ! empty( GITHUB_DEFAULT_ISSUES_REPOSITORY ) ) {
		$output->writeln( "<comment>Copying issues from " . GITHUB_DEFAULT_ISSUES_REPOSITORY . " to $slug</comment>" );
		$issues = $api_helper->call_github_api(
			sprintf( 'repos/%s/%s/issues', GITHUB_API_OWNER, GITHUB_DEFAULT_ISSUES_REPOSITORY ),
			'',
			'GET'
		);

		foreach( $issues as $issue ) {
			$new_issue = $api_helper->call_github_api(
				sprintf( 'repos/%s/%s/issues', GITHUB_API_OWNER, $slug ),
				array(
					'title' => $issue->title,
					'body' => $issue->body,
					'labels' => $issue->labels,
				),
				'POST'
			);

			if ( ! empty( $new_issue->id ) ) {
				$output->writeln( "<info>Copying issue '{$issue->title}' into $slug.</info>" );
			} else {
				$output->writeln( "<error>Failed to copy issue '{$issue->title}' into $slug.</info>" );
			}
		}
	}

        $output->writeln( "<info>GitHub repository creation and setup is complete! $html_url</info>" );

        if( $input->getOption( 'create-production-site' ) ) {
            $command = $this->getApplication()->find( 'create-production-site' );

            $arguments = array(
                'command' => 'create-production-site',
                '--site-name' => $input->getOption( 'repo-slug' ),
                '--connect-to-repo' => $input->getOption( 'repo-slug' ),
            );

            $command_input = new ArrayInput( $arguments );
            $output->writeln( "<comment>Creating and configuring new Pressable site.</comment>" );
            $command->run( $command_input, $output );
        }
    }

    protected function execute_command( $command, $working_directory = '.' ) {
        $process = new Process( $command );
        $process->setWorkingDirectory( $working_directory );
        $process->run();

        if( ! $process->isSuccessful() ) {
            throw new ProcessFailedException( $process );
        }

        return $process->getOutput();
    }
}
