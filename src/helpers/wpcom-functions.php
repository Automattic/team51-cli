<?php

namespace Team51\Helper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Get the complete list of WordPress.com sites for the a8cteam51 account, including WPORG sites with an active Jetpack
 * connection and including P2 blogs (or any other site the user is merely subscribed to).
 *
 * By default, the parameter `include_domain_only` is false, but for a more complete list of sites, set it to true. Also by default,
 * the list contains both active and inactive sites. Make sure to read the API documentation for a complete list of defaults and options.
 *
 * @param   array   $params    Optional. Additional parameters to pass to the API call.
 *                             It's recommended to pass the `fields` parameter otherwise the response is likely to time out.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/me/sites/
 *
 * @return  object[]|null
 */
function get_wpcom_sites( array $params = array() ): ?array {
	$query = \http_build_query( $params );
	$sites = WPCOM_API_Helper::call_api( 'me/sites' . ( empty( $query ) ? '' : "?$query" ) );
	if ( ! \is_null( $sites ) && \property_exists( $sites, 'error' ) ) {
		console_writeln( $sites->message );
		return null;
	}

	return \array_combine(
		\array_column( $sites->sites, 'ID' ),
		$sites->sites
	);
}

/**
 * Get the list of active, Jetpack-enabled WordPress.com sites for the a8cteam51 account. This includes WPORG sites with an active
 * Jetpack connection, but excludes things like WPCOM Simple sites, P2 blogs, and sites subscribed to.
 *
 * @return  object[]|null
 */
function get_wpcom_jetpack_sites(): ?array {
	$sites = WPCOM_API_Helper::call_api( 'jetpack-blogs' );
	if ( \is_null( $sites ) || ! \property_exists( $sites, 'success' ) || ! $sites->success ) {
		return null;
	}

	return \array_combine(
		\array_column( $sites->blogs->blogs, 'userblog_id' ),
		$sites->blogs->blogs
	);
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
 * Deletes or removes a user of a site.
 *
 * @param   string  $site_id_or_url     The site URL or WordPress.com site ID.
 * @param   string  $user_id            The WP user ID.
 *
 * @return  bool|null
 */
function delete_wpcom_site_user_by_id( string $site_id_or_url, string $user_id ): ?bool {
	$result = WPCOM_API_Helper::call_api( "sites/$site_id_or_url/users/$user_id/delete", 'POST' );
	if ( \is_null( $result ) || ! \property_exists( $result, 'success' ) ) {
		return null;
	}

	return $result->success;
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

/**
 * Grabs a value from the console input and tries to retrieve a WPCOM site based on it.
 *
 * @param InputInterface  $input         The console input.
 * @param OutputInterface $output        The console output.
 * @param callable|null   $no_input_func The function to call if no input is given.
 * @param string          $name          The name of the value to grab.
 *
 * @return object|null
 */
function get_wpcom_site_from_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): ?object {
	$site_id_or_url = get_site_input( $input, $output, $no_input_func, $name );
	$wpcom_site     = get_wpcom_site( $site_id_or_url );

	if ( \is_null( $wpcom_site ) ) {
		$output->writeln( "<error>WPCOM site $site_id_or_url not found.</error>" );
	} else {
		$output->writeln( "<comment>WPCOM site found: $wpcom_site->name (ID $wpcom_site->ID, URL $wpcom_site->URL).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
	}

	return $wpcom_site;
}
