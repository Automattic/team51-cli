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

class Rename_Branches extends Command {
    protected static $defaultName = 'rename-branches';

    protected function configure() {
        $this
        ->setDescription( "Rename GitHub branches for repositories in an org." )
        ->setHelp( "" )
        ->addOption( 'old-branch-name', null, InputOption::VALUE_REQUIRED, "" )
        ->addOption( 'new-branch-name', null, InputOption::VALUE_REQUIRED, "" );

    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $filesystem = new Filesystem;

        $api_helper = new API_Helper;

        $old_branch_name = $input->getOption( 'old-branch-name' );
        $new_branch_name = $input->getOption( 'new-branch-name' );

        $repos_to_skip = array(

        );

        $output->writeln( "<comment>Retrieving all projects from DeployHQ.</comment>" );
        $projects = $api_helper->call_deploy_hq_api( "projects", 'GET', array() );

        // Verify repo we're trying to create doesn't already exist.
        $output->writeln( "<comment>Retrieving all repositories from GitHub org.</comment>" );

        $repositories = $api_helper->call_github_api(
            sprintf( 'orgs/%s/repos', GITHUB_API_OWNER ),
            '',
            'GET'
        );

        if ( empty( $repositories ) ) {
            $output->writeln( "<error>Failed to retrieve repositories. Aborting!</error>" );
            exit;
        }

        $page = 1;

        while( ! empty( $repositories = $api_helper->call_github_api(
            sprintf( 'orgs/%s/repos?per_page=100&page=%s', GITHUB_API_OWNER, $page ),
            '',
            'GET'
        ) ) ) {
            $page++;
            foreach( $repositories as $repository ) {

                $output->writeln( "<comment>Evaluating {$repository->full_name} to see if any DeployHQ projects use this repository.</comment>" );
                foreach( $projects as $project ) {
                    $output->writeln( "<comment>  -- Checking {$repository->full_name} for project {$project->name}.</comment>" );
                    if ( empty( $project->repository->url ) ) {
                        continue;
                    }
                    if ( $project->repository->url === $repository->ssh_url ) {
                            $servers = $api_helper->call_deploy_hq_api( "projects/{$project->permalink}/servers", 'GET', array() );
                            if ( empty( $servers[0]->id ) ) {
                                continue;
                            }

                            foreach( $servers as $server ) {
                                // Bail if there is no branch we need to update.
                                if ( $old_branch_name !== $server->branch && ! empty( $server->branch ) && 'master' !== $server->preferred_branch ) {
                                    continue;
                                }
                                $output->writeln( "<comment>    -- Updating servers that reference $old_branch_name in DeployHQ for {$repository->full_name}.</comment>" );
                                $server_update = $api_helper->call_deploy_hq_api( "projects/{$project->permalink}/servers/{$server->identifier}", 'PUT', array( 'name' => $server->name, 'branch' => $new_branch_name ) );
                                if ( empty( $server_update->id ) ) {
                                    $output->writeln( "<error>      -- Failed to update server {$server->name} to reference branch $new_branch_name in DeployHQ for {$repository->full_name}.</error>" );
                                } else {
                                    $output->writeln( "<info>      -- Successfully updated server {$server->name} to reference branch $new_branch_name in DeployHQ for {$repository->full_name}.</info>" );
                                }
                            }
                    }
                }

                if ( in_array( $repository->name, $repos_to_skip ) ) {
                    continue;
                }

                if ( ! empty( $repository->archived ) ) {
                    continue;
                }

                if ( $old_branch_name !== $repository->default_branch ) {
                    continue;
                }

                $output->writeln( $repository->name );

                $output->writeln( "<comment>Cloning {$repository->full_name}.</comment>" );

                // Pull down the repo.
                $this->execute_command( "git clone {$repository->clone_url}", TEAM51_CLI_ROOT_DIR . '/repositories' );

                // Do the business.
                $this->execute_command( "git checkout -b $new_branch_name $old_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git tag archive/default-branch $old_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git push -u origin $new_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git push origin archive/default-branch", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

                // Set default branch to '$new_branch_name'.
                $default_branch_request_args = array(
                    'name' => $repository->name,
                    'default_branch' => $new_branch_name,
                );

                $default_branch_request = $api_helper->call_github_api(
                    sprintf( 'repos/%s/%s', GITHUB_API_OWNER, $repository->name ),
                    $default_branch_request_args,
                    'PATCH'
                );

                // repos/:owner/:repo/branches/:branch/protection
                /*
                $delete_branch_protection_rules_request = $api_helper->call_github_api(
                    sprintf( "repos/%s/%s/branches/$old_branch_name/protection", GITHUB_API_OWNER, $repository->name ),
                    '',
                    'DELETE'
                );
                */

                // Protect old master branch from new commits.
                $old_master_branch_protection_rules_request = $api_helper->call_github_api(
                    sprintf( "repos/%s/%s/branches/$old_branch_name/protection", GITHUB_API_OWNER, $repository->name ),
                    array(
                        'restrictions' => array( 'users' => array( 'wpspecialprojects' ), 'teams' => array() ),
                        'enforce_admins' => null,
                        'required_pull_request_reviews' => null,
                        'required_status_checks' => null,
                    ),
                    'PUT'
                );

                // Add branch protection rules to the newly named defauly branch.

                $command = $this->getApplication()->find( 'add-branch-protection-rules' );

                $arguments = array(
                    'command' => 'add-branch-protection-rules',
                    'repo-slug' => $repository->name,
                );

                $command_input = new ArrayInput( $arguments );
                $output->writeln( "<comment>Adding branch protection rules to new default branch.</comment>" );
                $command->run( $command_input, $output );

                // Clean up local repo.
                $output->writeln( "<comment>Cleaning up local repo: repositories/{$repository->name}</comment>" );
                $this->execute_command( "git remote prune origin", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

                $output->writeln( "<comment>Adding branch protection rules to new default branch.</comment>" );
                $output->writeln( "<comment>Evaluating {$repository->full_name} to see if any DeployHQ projects use this repository.</comment>" );
                foreach( $projects as $project ) {
                    $output->writeln( "<comment>  -- Checking {$repository->full_name} for project {$project->name}.</comment>" );
                    if ( empty( $project->repository->url ) ) {
                        continue;
                    }
                    if ( $project->repository->url === $repository->ssh_url ) {
                            $servers = $api_helper->call_deploy_hq_api( "projects/{$project->permalink}/servers", 'GET', array() );
                            if ( empty( $servers[0]->id ) ) {
                                continue;
                            }

                            foreach( $servers as $server ) {
                                // Bail if there is no branch we need to update.
                                if ( $old_branch_name !== $server->branch ) {
                                    continue;
                                }
                                $output->writeln( "<comment>    -- Updating servers that reference $old_branch_name in DeployHQ for {$repository->full_name}.</comment>" );
                                $server_update = $api_helper->call_deploy_hq_api( "projects/{$project->permalink}/servers/{$server->identifier}", 'PUT', array( 'name' => $server->name, 'branch' => $new_branch_name ) );
                                if ( empty( $server_update->id ) ) {
                                    $output->writeln( "<error>      -- Failed to update server {$server->name} to reference branch $new_branch_name in DeployHQ for {$repository->full_name}.</error>" );
                                } else {
                                    $output->writeln( "<info>      -- Successfully updated server {$server->name} to reference branch $new_branch_name in DeployHQ for {$repository->full_name}.</info>" );
                                }
                            }
                    }
                }
            }
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
