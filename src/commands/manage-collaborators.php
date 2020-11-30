<?php

namespace Team51\Command;

use Team51\Helper\API_Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class Manage_Collaborators extends Command {
    protected static $defaultName = 'manage-collaborators';

    protected function configure() {
        $this
        ->setDescription( "Manage Pressable collaborators." )
        ->setHelp( "This command allows you to bulk-manage Pressable collaborators via CLI." )
        ->addOption( 'email', null, InputOption::VALUE_REQUIRED, "The email of the collaborator you'd like to run operations on." )
        ->addOption( 'remove', null, InputOption::VALUE_NONE, "Remove the collaborator from all sites." )
        ->addOption( 'no-prompt', null, InputOption::VALUE_NONE, "Don't prompt for confirmation before deletion." );
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $api_helper = new API_Helper;

        $collaborator_email = $input->getOption( 'email' );

        if ( empty( $collaborator_email ) ) {
            $output->writeln( '<error>Missing collaborator email (--email=user@domain.com).</error>');
            exit;
        }

        $output->writeln( "<comment>Getting collaborator data from Pressable.</comment>" );

        // Each site will have a separate collborator instance/ID for the same user/email.
		$collaborator_data = array();

        $collaborators = $api_helper->call_pressable_api(
        	"collaborators",
            'GET',
            array()
        );

        if ( empty( $collaborators->data ) ) {
            $output->writeln( "<error>Something has gone wrong while looking up the Pressable collaborators site. Aborting!</error>" );
            exit;
        }

        foreach( $collaborators->data as $collaborator ) {
        	if ( $collaborator->email === $collaborator_email ) {
        		$collaborator_data[] = $collaborator;
        	}
        }

        if ( empty( $collaborator_data ) ) {
        	$output->writeln( "<info>No collaborators found with the email $collaborator_email. Aborting!</info>" );
            exit;
        }

        $site_info = new Table( $output );
        $site_info->setStyle( 'box-double' );
        $site_info->setHeaders( array( 'Default Pressable URL', 'Site ID' ) );

        $collaborator_sites = array();

        $output->writeln( "" );
        $output->writeln( "<info>User $collaborator_email is a collaborator on the following sites:</info>" );
        foreach( $collaborator_data as $collaborator ) {
            $collaborator_sites[] = array( $collaborator->siteName . '.mystagingwebsite.com', $collaborator->siteId );
        }

        $site_info->setRows( $collaborator_sites );
        $site_info->render();

        // Don't go any farther if we only want to get a collaborator site list.
        if( empty( $input->getOption( 'remove' ) ) ) {
        	die();
        }

        $output->writeln( "<comment>Deleting collaborator $collaborator_email from the sites listed. This process is not reversible. Are you sure you want to do this? (y|n)</comment>" );

        if ( empty( $input->getOption( 'no-prompt' ) ) ) {
            $stdin = fopen ( "php://stdin","r" );
            $answer = fgets( $stdin );

            if( trim( $answer) !== 'y' ){
            	$output->writeln( "<comment>Exiting!</comment>" );
            	exit;
            }

            fclose( $stdin );
        }

        foreach( $collaborator_data as $collaborator ) {
            $output->writeln( "<info>Do you want to remove {$collaborator->email} from {$collaborator->siteName}?</info>" );
            $stdin = fopen ( "php://stdin","r" );
            $answer = fgets( $stdin );

            if( trim( $answer) !== 'y' ){
            	$output->writeln( "<comment>Skipping!</comment>" );
            	continue;
            }

            fclose( $stdin );

        	$removed_collaborator = $api_helper->call_pressable_api( "/sites/{$collaborator->siteId}/collaborators/{$collaborator->id}", 'DELETE', array() );

        	if( 'Success' === $removed_collaborator->message ) {
                $output->writeln( "<info>--  Removed {$collaborator->email} from {$collaborator->siteName}</info>" );
        	} else {
                $output->writeln( "<info>--  Failed to remove {$collaborator->email} from '{$collaborator->siteName}</info>" );
        	}
        }
    }
}
