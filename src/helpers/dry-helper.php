<?php

namespace Team51\Helper;

class DRY_Helper {
	private $api_helper;

	function __construct() {
		$this->api_helper = new API_Helper();
	}

	public function populate_team_with_repos( $repo_names, $team_slug, $team_permission ) {
		echo "Adding repos to team '{$team_slug}'. This might take a while...\n";
		foreach( $repo_names as $repo_name ) {
			$github_response = $this->api_helper->call_github_api(
				sprintf( 'orgs/%s/teams/%s/repos/%s/%s', GITHUB_API_OWNER, $team_slug, GITHUB_API_OWNER, $repo_name ),
				array(
					'permission' => $team_permission
				),
				'PUT'
			);
			if ( ! empty($github_response->message) ) {
				echo "Something went wrong when adding the repo '{$repo_name}' to the team '{$team_slug}'. Message: {$github_response->message}\n";
			}
		}
	}

}
