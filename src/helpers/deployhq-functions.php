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

	return \str_replace( array( '-production', '-development' ), array( '', '' ), $pressable_site->name );
}
