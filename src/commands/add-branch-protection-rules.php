<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Add_Branch_Protection_Rules extends Command {
    protected static $defaultName = 'add-branch-protection-rules';

    protected function configure() {
        $this
        ->setDescription( "Adds predefined branch protection rules to a given GitHub repository." )
        ->setHelp( "Allows adding branch protection rules to a GitHub repository.." )
        ->addArgument( 'repo-slug', InputArgument::REQUIRED, "Repository name in slug form (e.g. client-name)?" );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $api_helper = new API_Helper;

	$slug = $input->getArgument( 'repo-slug' );

	// TODO: Allow these rules to be managed via the config.json file.

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

        $output->writeln( "<comment>Adding branch protection rules to $slug.</comment>" );
        $branch_protection_rules = $api_helper->call_github_api( "repos/" . GITHUB_API_OWNER . "/$slug/branches/trunk/protection", $branch_protection_rules, 'PUT' );

	if ( ! empty( $branch_protection_rules->required_status_checks->contexts ) ) {
		$output->writeln( "<info>Done. Added branch protection rules to $slug.</info>" );
	} else {
		$output->writeln( "<info>Failed to add branch protection rules to $slug.</info>" );
	}
    }
}
