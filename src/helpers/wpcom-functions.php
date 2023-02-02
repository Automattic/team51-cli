<?php

namespace Team51\Helper;

/**
 * Gets the list of Jetpack sites connected to the a8cteam51 account.
 *
 * @return  object[]|null
 */
function get_wpcom_jetpack_sites(): ?array {
	$sites = WPCOM_API_Helper::call_api( 'rest/v1.1/jetpack-blogs/' );
	if ( is_null( $sites ) || ! property_exists( $sites, 'success' ) || ! $sites->success ) {
		return null;
	}

	return $sites->blogs->blogs;
}

function get_wpcom_sites(): ?array {
	$sites = WPCOM_API_Helper::call_api( 'rest/v1.1/me/sites/' );
	if ( ! is_null( $sites ) && property_exists( $sites, 'error' ) ) {
		console_writeln( $sites->message );
		return null;
	}

	return $sites->sites;
}

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
 * @param   string  $email              The email address of the user.
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

/**
 * Resets a given user's password on a site using the Jetpack API.
 *
 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
 * @param   string  $user_id            The WP user ID.
 * @param   string  $new_password       The new password to set.
 *
 * @return  bool|null
 */
function set_wpcom_site_user_wp_password( string $site_id_or_url, string $user_id, string $new_password ): ?bool {
	$result = WPCOM_API_Helper::call_site_api( $site_id_or_url, "/wp/v2/users/$user_id", array( 'password' => $new_password ) );
	if ( empty( $result ) ) {
		return false;
	}

	return true;
}
