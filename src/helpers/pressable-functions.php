<?php

namespace Team51\Helpers;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

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
	$sites = Pressable_API_Helper::call_api( 'sites?' . \http_build_query( $params ) );
	if ( \is_null( $sites ) || empty( $sites->data ) ) {
		return null;
	}

	return $sites->data;
}

/**
 * Get site information and settings for all sites that contain a given search term in their name or URL.
 *
 * @param   string  $search_term    The search term.
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
			if ( true === is_case_insensitive_match( $site->url, $site_url ) ) {
				return $site;
			}
		} elseif ( \substr( $site->url, -1 * \strlen( $site_url ) ) === $site_url ) {
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
 * Get SFTP user by ID for the specified site.
 *
 * @param   string  $site_id    The site ID.
 * @param   string  $user_id    The ID of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_id( string $site_id, string $user_id ): ?object {
	$sftp_users = get_pressable_site_sftp_users( $site_id );

	foreach ( $sftp_users as $sftp_user ) {
		if ( $user_id === (string) $sftp_user->id ) {
			return $sftp_user;
		}
	}

	return null;
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
		if ( ! empty( $sftp_user->email ) && true === is_case_insensitive_match( $sftp_user->email, $user_email ) ) {
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
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/ftp/password/$username", 'POST' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

/**
 * Get a list of collaborators for the specified site.
 *
 * @param   string  $site_id    The site ID.
 *
 * @return  object[]|null
 */
function get_pressable_site_collaborators( string $site_id ): ?array {
	$collaborators = Pressable_API_Helper::call_api( "sites/$site_id/collaborators" );
	if ( \is_null( $collaborators ) || empty( $collaborators->data ) ) {
		return null;
	}

	return $collaborators->data;
}

/**
 * Get site collaborator information by collaborator ID.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_id    The collaborator ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#get-site-collaborator
 *
 * @return  object|null
 */
function get_pressable_site_collaborator_by_id( string $site_id, string $collaborator_id ): ?object {
	$collaborator = Pressable_API_Helper::call_api( "sites/$site_id/collaborators/$collaborator_id" );
	if ( \is_null( $collaborator ) || empty( $collaborator->data ) ) {
		return null;
	}

	return $collaborator->data;
}

/**
 * Get site collaborator information by collaborator email.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_email The collaborator email.
 *
 * @return  object|null
 */
function get_pressable_site_collaborator_by_email( string $site_id, string $collaborator_email ): ?object {
	$collaborators = get_pressable_site_collaborators( $site_id );
	if ( \is_null( $collaborators ) ) {
		return null;
	}

	foreach ( $collaborators as $collaborator ) {
		if ( ! empty( $collaborator->email ) && true === is_case_insensitive_match( $collaborator->email, $collaborator_email ) ) {
			return $collaborator;
		}
	}

	return null;
}

/**
 * If one of your collaborators is unable to log into the site’s WordPress dashboard because of a forgotten, or unknown, password,
 * this can be used to set their WP Admin password to a randomly generated value.
 *
 * @param   string  $site_id            The site ID.
 * @param   string  $collaborator_id    The collaborator ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#set-collaborator-wp-admin-password
 *
 * @return  string|null
 */
function reset_pressable_site_collaborator_wp_password( string $site_id, string $collaborator_id ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/collaborators/$collaborator_id/wp-password-reset", 'PUT' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

/**
 * Reset the site owner's WP-Admin password. If you (account owner) are unable to log into your site’s WordPress dashboard because of a forgotten, or unknown, password,
 * this endpoint can be used to set your WP Admin password to a randomly generated value.
 *
 * Since the site owner does NOT show up in the list of collaborators on a site, this endpoint must be used to instead
 * of the @reset_pressable_site_collaborator_wp_password endpoint.
 *
 * @param   string  $site_id    The site ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#reset-wpadmin-password
 *
 * @return  string|null
 */
function reset_pressable_site_owner_wp_password( string $site_id ): ?string {
	$new_password = Pressable_API_Helper::call_api( "sites/$site_id/wordpress/password-reset", 'PUT' );
	if ( \is_null( $new_password ) || empty( $new_password->data ) ) {
		return null;
	}

	return $new_password->data;
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and tries to retrieve a Pressable site based on it.
 *
 * @param   InputInterface  $input          The console input.
 * @param   OutputInterface $output         The console output.
 * @param   callable|null   $no_input_func  The function to call if no input is given.
 * @param   string          $name           The name of the value to grab.
 *
 * @return  object|null
 */
function get_pressable_site_from_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): ?object {
	$site_id_or_url = get_site_input( $input, $output, $no_input_func, $name );
	$pressable_site = \is_numeric( $site_id_or_url ) ? get_pressable_site_by_id( $site_id_or_url ) : get_pressable_site_by_url( $site_id_or_url );

	if ( \is_null( $pressable_site ) ) {
		$output->writeln( "<error>Pressable site $site_id_or_url not found.</error>" );
	} else {
		$output->writeln( "<comment>Pressable site found: $pressable_site->displayName (ID $pressable_site->id, URL $pressable_site->url).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
	}

	return $pressable_site;
}

/**
 * Grabs a value from the console input and tries to retrieve a Pressable SFTP user based on it.
 *
 * @param   InputInterface  $input              The console input.
 * @param   OutputInterface $output             The console output.
 * @param   string          $pressable_site_id  The Pressable site ID.
 * @param   callable|null   $no_input_func      The function to call if no input is given.
 * @param   string          $name               The name of the value to grab.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_from_input( InputInterface $input, OutputInterface $output, string $pressable_site_id, ?callable $no_input_func = null, string $name = 'user' ): ?object {
	$user_uname_or_id_or_email = get_user_input( $input, $output, $no_input_func, $name, false ); // Pressable SFTP users can also be retrieved by username so no validation is needed.
	$pressable_sftp_user       = \is_numeric( $user_uname_or_id_or_email ) ? get_pressable_site_sftp_user_by_id( $pressable_site_id, $user_uname_or_id_or_email )
		: ( get_pressable_site_sftp_user_by_username( $pressable_site_id, $user_uname_or_id_or_email ) ?? get_pressable_site_sftp_user_by_email( $pressable_site_id, $user_uname_or_id_or_email ) );

	if ( \is_null( $pressable_sftp_user ) ) {
		$output->writeln( "<error>Pressable SFTP user $user_uname_or_id_or_email not found on $pressable_site_id.</error>" );
	} else {
		$output->writeln( "<comment>Pressable SFTP user found on $pressable_site_id: $pressable_sftp_user->username (ID $pressable_sftp_user->id, email $pressable_sftp_user->email).</comment>", OutputInterface::VERBOSITY_VERY_VERBOSE );
	}

	return $pressable_sftp_user;
}

// endregion
