<?php

namespace Team51\Helper;

$config = json_decode( file_get_contents( TEAM51_CLI_ROOT_DIR . '/config.json' ) );
$warning_config_keys = array();

if( empty( $config ) ) {
	echo "Config file couldn't be read. Aborting!\n";
	die();
}

if( ! empty( $config->DEPLOY_HQ_API_KEY ) ) {
	define( 'DEPLOY_HQ_API_KEY', $config->DEPLOY_HQ_API_KEY );
} else {
	$warning_config_keys[] = "DEPLOY_HQ_API_KEY";
}

if( ! empty( $config->DEPLOY_HQ_USERNAME ) ) {
	define( 'DEPLOY_HQ_USERNAME', $config->DEPLOY_HQ_USERNAME );
} else {
	$warning_config_keys[] = "DEPLOY_HQ_USERNAME";
}

if( ! empty( $config->DEPLOY_HQ_API_ENDPOINT ) ) {
	define( 'DEPLOY_HQ_API_ENDPOINT', $config->DEPLOY_HQ_API_ENDPOINT );
} else {
	$warning_config_keys[] = "DEPLOY_HQ_API_ENDPOINT";
}

if( ! empty( $config->DEPLOY_HQ_DEFAULT_PROJECT_TEMPLATE ) ) {
	define( 'DEPLOY_HQ_DEFAULT_PROJECT_TEMPLATE', $config->DEPLOY_HQ_DEFAULT_PROJECT_TEMPLATE );
} else {
	echo "DEPLOY_HQ_DEFAULT_PROJECT_TEMPLATE could not be set. Aborting!\n";
	die();
}

if( ! empty( $config->GITHUB_API_OWNER ) ) {
	define( 'GITHUB_API_OWNER', $config->GITHUB_API_OWNER );
} else {
	$warning_config_keys[] = "GITHUB_API_OWNER";
}

if( ! empty( $config->GITHUB_API_ENDPOINT ) ) {
	define( 'GITHUB_API_ENDPOINT', $config->GITHUB_API_ENDPOINT );
} else {
	$warning_config_keys[] = "GITHUB_API_ENDPOINT";
}

if ( ! empty( $config->GITHUB_DEVQUEUE_TRIAGE_COLUMN ) ) {
	define( 'GITHUB_DEVQUEUE_TRIAGE_COLUMN', $config->GITHUB_DEVQUEUE_TRIAGE_COLUMN );
} else {
	$warning_config_keys[] = "GITHUB_DEVQUEUE_TRIAGE_COLUMN";
}

if( ! empty( $config->GITHUB_API_TOKEN ) ) {
	define( 'GITHUB_API_TOKEN', $config->GITHUB_API_TOKEN );
} else {
	$warning_config_keys[] = "GITHUB_API_TOKEN";
}

if( ! empty( $config->PRESSABLE_SFTP_HOSTNAME ) ) {
	define( 'PRESSABLE_SFTP_HOSTNAME', $config->PRESSABLE_SFTP_HOSTNAME );
} else {
	$warning_config_keys[] = "PRESSABLE_SFTP_HOSTNAME";
}
if( ! empty( $config->PRESSABLE_SFTP_USERNAME ) ) {
	define( 'PRESSABLE_SFTP_USERNAME', $config->PRESSABLE_SFTP_USERNAME );
} else {
	$warning_config_keys[] = "PRESSABLE_SFTP_USERNAME";
}

if( ! empty( $config->PRESSABLE_SFTP_PASSWORD ) ) {
	define( 'PRESSABLE_SFTP_PASSWORD', $config->PRESSABLE_SFTP_PASSWORD );
} else {
	$warning_config_keys[] = "PRESSABLE_SFTP_PASSWORD";
}

if( ! empty( $config->PRESSABLE_API_ENDPOINT ) ) {
	define( 'PRESSABLE_API_ENDPOINT', $config->PRESSABLE_API_ENDPOINT );
} else {
	$warning_config_keys[] = "PRESSABLE_API_ENDPOINT";
}

if( ! empty( $config->PRESSABLE_API_TOKEN_ENDPOINT ) ) {
	define( 'PRESSABLE_API_TOKEN_ENDPOINT', $config->PRESSABLE_API_TOKEN_ENDPOINT );
} else {
	$warning_config_keys[] = "PRESSABLE_API_TOKEN_ENDPOINT";
}

if( ! empty( $config->PRESSABLE_API_APP_CLIENT_ID ) ) {
	define( 'PRESSABLE_API_APP_CLIENT_ID', $config->PRESSABLE_API_APP_CLIENT_ID );
} else {
	$warning_config_keys[] = "PRESSABLE_API_APP_CLIENT_ID";
}

if( ! empty( $config->PRESSABLE_API_APP_CLIENT_SECRET ) ) {
	define( 'PRESSABLE_API_APP_CLIENT_SECRET', $config->PRESSABLE_API_APP_CLIENT_SECRET );
} else {
	$warning_config_keys[] = "PRESSABLE_API_APP_CLIENT_SECRET";
}

if( empty( $config->PRESSABLE_ACCOUNT_EMAIL ) && empty( $config->PRESSABLE_API_REFRESH_TOKEN ) ) {
	$warning_config_keys[] = "PRESSABLE_ACCOUNT_EMAIL";
	$warning_config_keys[] = "PRESSABLE_API_REFRESH_TOKEN";
} else {
	if ( ! empty( $config->PRESSABLE_ACCOUNT_EMAIL ) ) {
		define( 'PRESSABLE_ACCOUNT_EMAIL', $config->PRESSABLE_ACCOUNT_EMAIL );
	}
	if ( ! empty( $config->PRESSABLE_API_REFRESH_TOKEN ) ) {
		define( 'PRESSABLE_API_REFRESH_TOKEN', $config->PRESSABLE_API_REFRESH_TOKEN );
	}
}

if( empty( $config->PRESSABLE_ACCOUNT_PASSWORD ) && empty( $config->PRESSABLE_API_REFRESH_TOKEN ) ) {
	$warning_config_keys[] = "PRESSABLE_ACCOUNT_PASSWORD";
	$warning_config_keys[] = "PRESSABLE_API_REFRESH_TOKEN";
} else {
	if ( ! empty( $config->PRESSABLE_ACCOUNT_PASSWORD ) ) {
		define( 'PRESSABLE_ACCOUNT_PASSWORD', $config->PRESSABLE_ACCOUNT_PASSWORD );
	}
}

if( ! empty( $config->WPCOM_API_ENDPOINT ) ) {
	define( 'WPCOM_API_ENDPOINT', $config->WPCOM_API_ENDPOINT );
} else {
	$warning_config_keys[] = "WPCOM_API_ENDPOINT";
}

if( ! empty( $config->WPCOM_API_ACCOUNT_TOKEN ) ) {
	define( 'WPCOM_API_ACCOUNT_TOKEN', $config->WPCOM_API_ACCOUNT_TOKEN );
} else {
	$warning_config_keys[] = "WPCOM_API_ACCOUNT_TOKEN";
}

if( ! empty( $config->FRONT_API_ENDPOINT ) ) {
	define( 'FRONT_API_ENDPOINT', $config->FRONT_API_ENDPOINT );
} else {
	$warning_config_keys[] = "FRONT_API_ENDPOINT";
}

if( ! empty( $config->FRONT_API_TOKEN ) ) {
	define( 'FRONT_API_TOKEN', $config->FRONT_API_TOKEN );
} else {
	$warning_config_keys[] = "FRONT_API_TOKEN";
}

if( ! empty( $config->SLACK_WEBHOOK_URL ) ) {
	define( 'SLACK_WEBHOOK_URL', $config->SLACK_WEBHOOK_URL );
} else {
	$warning_config_keys[] = "SLACK_WEBHOOK_URL";
}

if( ! empty( $config->GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY ) ) {
	define( 'GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY', $config->GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY );
} else {
	$warning_config_keys[] = "GITHUB_TEAM_TO_ADD_TO_NEW_REPOSITORY";
}

if( ! empty( $config->ASCII_WELCOME_ART ) ) {
	define( 'ASCII_WELCOME_ART', $config->ASCII_WELCOME_ART );
} else {
	$warning_config_keys[] = "ASCII_WELCOME_ART";
}

if( ! empty( $config->GITHUB_DEFAULT_ISSUES_REPOSITORY ) ) {
	define( 'GITHUB_DEFAULT_ISSUES_REPOSITORY', $config->GITHUB_DEFAULT_ISSUES_REPOSITORY );
} else {
	$warning_config_keys[] = "GITHUB_DEFAULT_ISSUES_REPOSITORY";
}

if( ! empty( $config->PRESSABLE_BOT_COLLABORATOR_EMAIL ) ) {
	define( 'PRESSABLE_BOT_COLLABORATOR_EMAIL', $config->PRESSABLE_BOT_COLLABORATOR_EMAIL );
} else {
	$warning_config_keys[] = "PRESSABLE_BOT_COLLABORATOR_EMAIL";
}

if ( ! empty( $warning_config_keys ) ) {
	echo "WARNING: The following values were not found in config.json, some commands might not work as expected: " . implode( ", ", $warning_config_keys );
}
