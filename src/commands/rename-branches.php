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

        // Verify repo we're trying to create doesn't already exist.
        $output->writeln( "<comment>Retrieving all repositories from GitHub org.</comment>" );

        $repositories = $api_helper->call_github_api(
            sprintf( 'orgs/%s/repos', GITHUB_API_OWNER ),
            '',
            'GET'
        );

        $projects = $api_helper->call_deploy_hq_api( "projects", 'GET', array() );

        foreach( $projects as $project ) {
            $servers = $api_helper->call_deploy_hq_api( "projects/{$project->permalink}/servers", 'GET', array() );
            var_dump( $servers ); die();
        }

        var_dump( $projects ); die();

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
                $output->writeln( $repository->name );
                if ( 'deployhq-test' !== $repository->name ) {
                    continue;
                }

                $output->writeln( "<comment>Cloning {$repository->full_name}</comment>" );

                // Pull down the repo.
                $this->execute_command( "git clone {$repository->clone_url}", TEAM51_CLI_ROOT_DIR . '/repositories' );

                // Do the business.
                $this->execute_command( "git checkout -b $new_branch_name $old_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git push -u origin $new_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

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
                $delete_branch_protection_rules_request = $api_helper->call_github_api(
                    sprintf( "repos/%s/%s/branches/$old_branch_name/protection", GITHUB_API_OWNER, $repository->name ),
                    '',
                    'DELETE'
                );

                // Delete branch protection rules so we can delete the $old_branch_name branch.

                //var_dump( $default_branch_request, $default_branch_request_args ); die();
                $this->execute_command( "git branch -d $old_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git push --delete origin $old_branch_name", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );
                $this->execute_command( "git remote prune origin", TEAM51_CLI_ROOT_DIR . "/repositories/{$repository->name}" );

                var_dump( $repository, $default_branch_request ); die();
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
