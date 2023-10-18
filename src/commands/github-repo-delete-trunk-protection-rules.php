<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Delete_Branch_Protection_Rules extends Command {
    protected static $defaultName = 'github:delete-trunk-protection-rules';

    protected function configure() {
        $this
        ->setDescription( "Delete branch protection rules for a given GitHub repository." )
        ->setHelp( "Allows deleting branch protection rules for a GitHub repository.." )
        ->addArgument( 'repo-slug', InputArgument::REQUIRED, "Repository name in slug form (e.g. client-name)?" );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $api_helper = new API_Helper;

        $slug = $input->getArgument( 'repo-slug' );

        $output->writeln( "<comment>Adding branch protection rules to $slug.</comment>" );
        $delete_branch_protection_rules = $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/branches/trunk/protection", array(), 'DELETE' );

        if ( ! empty( '' === $delete_branch_protection_rules ) ) {
            $output->writeln( "<info>Done. Deleted branch protection rules for $slug.</info>" );
        } else {
            $output->writeln( "<info>Failed to delete branch protection rules for $slug: {$delete_branch_protection_rules->message}</info>" );
        }
    }
}
