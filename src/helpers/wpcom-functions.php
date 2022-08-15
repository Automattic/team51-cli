<?php

namespace Team51\Helpers;

/**
 * Gets the WordPress.com site information by site URL or WordPress.com ID (requires active Jetpack connection for WPORG sites).
 *
 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
 *
 * @return  object|null
 */
function get_wpcom_site( string $site_id_or_url ): ?object {
	$site = WPCOM_API_Helper::call_api( "sites/$site_id_or_url" );
	if ( empty( $site ) ) {
		return null;
	}

	return $site;
}

/**
 * Gets the list of users for a site by site URL or WordPress.com ID (requires active Jetpack connection for WPORG sites).
 *
 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
 * @param   array   $params             Optional. Additional parameters to pass to the API call.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/users/
 *
 * @return  array|null
 */
function get_wpcom_site_users( string $site_id_or_url, array $params = array() ): ?array {
	$users = WPCOM_API_Helper::call_api( "sites/$site_id_or_url/users?" . \http_build_query( $params ) );
	if ( empty( $users ) ) {
		return null;
	}

	return $users->users;
}

/**
 * Gets a site user for a site by their login email address (requires active Jetpack connection for WPORG sites).
 *
 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
 * @param   string  $email			    The email address of the user.
 *
 * @return  object|null
 */
function get_wpcom_site_user_by_email( string $site_id_or_url, string $email ): ?object {
	$users = get_wpcom_site_users( $site_id_or_url );
	if ( empty( $users ) ) {
		return null;
	}

	foreach ( $users as $user ) {
		if ( true === is_case_insensitive_match( $email, $user->email ) ) {
			return $user;
		}
	}

	return null;
}
