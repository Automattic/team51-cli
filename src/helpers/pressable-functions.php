<?php

namespace Team51\Helpers;

/**
 * Get a list of sites belonging to your account. Sites can be filtered by tag name.
 * Site listing is a full list of sites attached to your account, unless pagination is requested.
 *
 * @param   array   $params    The query parameters to send with the request.
 *
 * @link    https://my.pressable.com/documentation/api/v1#sites
 *
 * @return  object[]|null
 */
function get_pressable_sites( array $params = array() ): ?array {
	if ( $params['paginate'] ?? false ) {
		$params['paginate'] = true;
		$params['per_page'] = clamp( (int) ( $params['per_page'] ?? 20 ), 1, 50 ); // Clamp to 1-50 with a default of 20.
		$params['page']	    = \max( 1, (int) ( $params['page'] ?? 1 ) ); // Page number starts at 1.
	} else {
		unset( $params['paginate'], $params['per_page'], $params['page'] );
	}

	$sites = Pressable_API_Helper::call_api( 'sites?' . \http_build_query( $params ) );
	if ( \is_null( $sites ) || empty( $sites->data ) ) {
		return null;
	}

	return $sites->data;
}

/**
 * Get site information and settings for all sites that contain a given search term in their name or URL.
 *
 * @param   string  $search_term	The search term.
 * @param   array   $params         The query parameters for filtering the results.
 *
 * @return  object[]|null
 */
function get_pressable_sites_by_search_term( string $search_term, array $params = array() ): ?array {
	$sites = get_pressable_sites( $params );
	if ( \is_null( $sites ) ) {
		return null;
	}

	$checker = static fn( object $site ): bool => (
		false !== \strpos( $site->name, $search_term ) ||
		false !== \strpos( $site->url, $search_term )
	);
	return \array_filter( $sites, $checker );
}

/**
 * Get site information and settings by Pressable site ID.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-site
 *
 * @return  object|null
 */
function get_pressable_site_by_id( string $site_id ): ?object {
	$site = Pressable_API_Helper::call_api( "sites/$site_id" );
	if ( \is_null( $site ) || empty( $site->data ) ) {
		return null;
	}

	return $site->data;
}

/**
 * Get site information and settings by main site URL.
 *
 * @param   string  $site_url   The site URL.
 * @param   bool    $exact      Whether to match the exact URL.
 *
 * @return  object|null
 */
function get_pressable_site_by_url( string $site_url, bool $exact = true ): ?object {
	$sites = get_pressable_sites();
	if ( \is_null( $sites ) ) {
		return null;
	}

	foreach ( $sites as $site ) {
		if ( true === $exact ) {
			if ( 0 === \strcasecmp( $site->url, $site_url ) ) {
				return $site;
			}
		} else if ( \substr( $site->url, -1 * \strlen( $site_url ) ) === $site_url ) {
			// If $site_url is the ending of the site URL, return the site.
			// This will thus return the site with URL www.domain.com for a URL input of domain.com.
			return $site;
		}
	}

	return null;
}

/**
 * Get a list of FTP users for the specified site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#sites-ftp
 *
 * @return  object[]|null
 */
function get_pressable_site_sftp_users( string $site_id ): ?array {
	$sftp_users = Pressable_API_Helper::call_api( "sites/$site_id/ftp" );
	if ( \is_null( $sftp_users ) || empty( $sftp_users->data ) ) {
		return null;
	}

	return $sftp_users->data;
}

/**
 * Get SFTP user by username for the specified site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $username   The username of the site SFTP user.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-sftp-user
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_username( string $site_id, string $username ): ?object {
	$sftp_user = Pressable_API_Helper::call_api( "sites/$site_id/ftp/$username" );
	if ( \is_null( $sftp_user ) || empty( $sftp_user->data ) ) {
		return null;
	}

	return $sftp_user->data;
}

/**
 * Get SFTP user by email for the specified site.
 *
 * @param   string  $site_id        The site ID.
 * @param   string  $user_email     The email of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_email( string $site_id, string $user_email ): ?object {
	$sftp_users = get_pressable_site_sftp_users( $site_id );

	foreach ( $sftp_users as $sftp_user ) {
		if ( 0 === \strcasecmp( $sftp_user->email, $user_email ) ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Resets an SFTP user password for the specified user and site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $username   The username of the site SFTP user.
 *
 * @link    https://my.pressable.com/documentation/api/v1#reset-sftp-user-password
 *
 * @return  string|null     The new password or null if the password could not be reset.
 */
function reset_pressable_site_sftp_user_password( string $site_id, string $username ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/ftp/password/$username" );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}
