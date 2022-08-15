<?php

namespace Team51\Helpers;

/**
 * Gets a list of all projects.
 *
 * @link    https://www.deployhq.com/support/api/projects/listing-all-projects
 *
 * @return  object[]|null
 */
function get_deployhq_projects(): ?array {
	$projects = DeployHQ_API_Helper::call_api( 'projects' );
	if ( empty( $projects ) ) {
		return null;
	}

	return $projects;
}

/**
 * Gets a project by its permalink.
 *
 * @param   string  $permalink  The permalink of the project.
 *
 * @link    https://www.deployhq.com/support/api/projects/view-an-existing-project
 *
 * @return  object|null
 */
function get_deployhq_project_by_permalink( string $permalink ): ?object {
	$project = DeployHQ_API_Helper::call_api( "projects/$permalink" );
	if ( empty( $project ) || empty( $project->permalink ) ) {
		return null;
	}

	return $project;
}

/**
 * Gets a list of all servers which are configured for a given project.
 *
 * @param   string  $project_permalink  The permalink of the project.
 *
 * @link    https://www.deployhq.com/support/api/servers/listing-all-servers
 *
 * @return  object[]|null
 */
function get_deployhq_project_servers( string $project_permalink ): ?array {
	$servers = DeployHQ_API_Helper::call_api( "projects/$project_permalink/servers" );
	if ( empty( $servers ) ) {
		return null;
	}

	return $servers;
}

/**
 * Returns what the DeployHQ project permalink should be for a given Pressable site.
 *
 * @param   object  $pressable_site     The site object.
 *
 * @see     get_pressable_sites()
 * @see     get_pressable_site_by_id()
 *
 * @return  string|null
 */
function get_deployhq_project_permalink_from_pressable_site( object $pressable_site ): ?string {
	if ( ! \property_exists( $pressable_site, 'name' ) || empty( $pressable_site->name ) ) {
		return null;
	}

	$project_name = $pressable_site->name;
	if ( false !== \strpos( $project_name, '-development' ) ) {
		// Handles the case where the project name is "project-development-timestamp".
		$project_name = \explode( '-development', $project_name, 2 )[0];
	} elseif ( ! empty( $pressable_site->clonedFromId ) ) {
		// Handles the legacy case where a labelled temporary clone is missing the "-development" substring.
		$pressable_site = get_pressable_site_by_id( $pressable_site->clonedFromId );
		$project_name   = get_deployhq_project_permalink_from_pressable_site( $pressable_site );
	} elseif ( false !== \strpos( $pressable_site->displayName, '-development' ) ) {
		// Handles the legacy case where a labelled temporary clone is missing the "-development" substring
		// and no 'clonedFromId' is available for some reason. For this to work, the display name of the clone
		// must be updated MANUALLY to include the "-development" substring.
		$project_name = \explode( '-development', $pressable_site->displayName, 2 )[0];
	}

	return \explode( '-production', $project_name, 2 )[0];
}

/**
 * Updates the given server's configuration and returns the updated server.
 *
 * @param   string  $project_permalink  The permalink of the project.
 * @param   string  $server_id          The identifier of the server.
 * @param   array   $params             The parameters to update.
 *
 * @link    https://www.deployhq.com/support/api/servers/edit-an-existing-server
 *
 * @return  object|null
 */
function update_deployhq_project_server( string $project_permalink, string $server_id, array $params ): ?object {
	if ( ! isset( $params['server'] ) ) { // Quirk of the API. All changes must be sub-nested under 'server' for some inexplicable reason.
		$params = array(
			'server' => $params
		);
	}

	$response = DeployHQ_API_Helper::call_api( "projects/$project_permalink/servers/$server_id", 'PUT', $params );
	if ( empty( $response ) ) {
		return null;
	}

	return $response;
}
