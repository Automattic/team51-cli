<?php

namespace Team51\Helper;

$config = json_decode( file_get_contents( TEAM51_CLI_ROOT_DIR . '/config.json' ) );

if( empty( $config ) ) {
	echo "Config file couldn't be read. Aborting!\n";
	die();
}

if( ! empty( $config->DEPLOY_HQ_API_KEY ) ) {
	define( 'DEPLOY_HQ_API_KEY', $config->DEPLOY_HQ_API_KEY );
} else {
	echo "DEPLOY_HQ_API_KEY could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->DEPLOY_HQ_USERNAME ) ) {
	define( 'DEPLOY_HQ_USERNAME', $config->DEPLOY_HQ_USERNAME );
} else {
	echo "DEPLOY_HQ_USERNAME could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->DEPLOY_HQ_API_ENDPOINT ) ) {
	define( 'DEPLOY_HQ_API_ENDPOINT', $config->DEPLOY_HQ_API_ENDPOINT );
} else {
	echo "DEPLOY_HQ_API_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->GITHUB_API_OWNER ) ) {
	define( 'GITHUB_API_OWNER', $config->GITHUB_API_OWNER );
} else {
	echo "GITHUB_API_OWNER could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->GITHUB_API_ENDPOINT ) ) {
	define( 'GITHUB_API_ENDPOINT', $config->GITHUB_API_ENDPOINT );
} else {
	echo "GITHUB_API_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->GITHUB_API_TOKEN ) ) {
	define( 'GITHUB_API_TOKEN', $config->GITHUB_API_TOKEN );
} else {
	echo "GITHUB_API_TOKEN could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_SFTP_HOSTNAME ) ) {
	define( 'PRESSABLE_SFTP_HOSTNAME', $config->PRESSABLE_SFTP_HOSTNAME );
} else {
	echo "PRESSABLE_SFTP_HOSTNAME could not be set. Aborting!\n";
	die();
}
if( ! empty( $config->PRESSABLE_SFTP_USERNAME ) ) {
	define( 'PRESSABLE_SFTP_USERNAME', $config->PRESSABLE_SFTP_USERNAME );
} else {
	echo "PRESSABLE_SFTP_USERNAME could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_SFTP_PASSWORD ) ) {
	define( 'PRESSABLE_SFTP_PASSWORD', $config->PRESSABLE_SFTP_PASSWORD );
} else {
	echo "PRESSABLE_SFTP_PASSWORD could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_API_ENDPOINT ) ) {
	define( 'PRESSABLE_API_ENDPOINT', $config->PRESSABLE_API_ENDPOINT );
} else {
	echo "PRESSABLE_API_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_API_TOKEN_ENDPOINT ) ) {
	define( 'PRESSABLE_API_TOKEN_ENDPOINT', $config->PRESSABLE_API_TOKEN_ENDPOINT );
} else {
	echo "PRESSABLE_API_TOKEN_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_API_APP_CLIENT_ID ) ) {
	define( 'PRESSABLE_API_APP_CLIENT_ID', $config->PRESSABLE_API_APP_CLIENT_ID );
} else {
	echo "PRESSABLE_API_APP_CLIENT_ID could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_API_APP_CLIENT_SECRET ) ) {
	define( 'PRESSABLE_API_APP_CLIENT_SECRET', $config->PRESSABLE_API_APP_CLIENT_SECRET );
} else {
	echo "PRESSABLE_API_APP_CLIENT_SECRET could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_ACCOUNT_EMAIL ) ) {
	define( 'PRESSABLE_ACCOUNT_EMAIL', $config->PRESSABLE_ACCOUNT_EMAIL );
} else {
	echo "PRESSABLE_ACCOUNT_EMAIL could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->PRESSABLE_ACCOUNT_PASSWORD ) ) {
	define( 'PRESSABLE_ACCOUNT_PASSWORD', $config->PRESSABLE_ACCOUNT_PASSWORD );
} else {
	echo "PRESSABLE_ACCOUNT_PASSWORD could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->WPCOM_API_ENDPOINT ) ) {
	define( 'WPCOM_API_ENDPOINT', $config->WPCOM_API_ENDPOINT );
} else {
	echo "Warning: WPCOM_API_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->WPCOM_API_ACCOUNT_TOKEN ) ) {
	define( 'WPCOM_API_ACCOUNT_TOKEN', $config->WPCOM_API_ACCOUNT_TOKEN );
} else {
	echo "Warning: WPCOM_API_ACCOUNT_TOKEN could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->FRONT_API_ENDPOINT ) ) {
	define( 'FRONT_API_ENDPOINT', $config->FRONT_API_ENDPOINT );
} else {
	echo "Warning: FRONT_API_ENDPOINT could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->FRONT_API_TOKEN ) ) {
	define( 'FRONT_API_TOKEN', $config->FRONT_API_TOKEN );
} else {
	echo "Warning: FRONT_API_TOKEN could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->SLACK_WEBHOOK_URL ) ) {
	define( 'SLACK_WEBHOOK_URL', $config->SLACK_WEBHOOK_URL );
} else {
	echo "Warning: SLACK_WEBHOOK_URL could not be set.\n";
}

if( ! empty( $config->GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY ) ) {
	define( 'GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY', $config->GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY );
} else {
	echo "Warning: GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY could not be set.\n";
}

if( ! empty( $config->ASCII_WELCOME_ART ) ) {
	define( 'ASCII_WELCOME_ART', $config->ASCII_WELCOME_ART );
} else {
	echo "Warning: ASCII_WELCOME_ART could not be set.\n";
}

if( ! empty( $config->GITHUB_DEFAULT_ISSUES_REPOSITORY ) ) {
	define( 'GITHUB_DEFAULT_ISSUES_REPOSITORY', $config->GITHUB_DEFAULT_ISSUES_REPOSITORY );
} else {
	echo "Warning: GITHUB_DEFAULT_ISSUES_REPOSITORY could not be set.\n";
}

if( ! empty( $config->PRESSABLE_BOT_COLLABORATOR_EMAIL ) ) {
	define( 'PRESSABLE_BOT_COLLABORATOR_EMAIL', $config->PRESSABLE_BOT_COLLABORATOR_EMAIL );
} else {
	echo "Warning: PRESSABLE_BOT_COLLABORATOR_EMAIL could not be set.\n";
}
