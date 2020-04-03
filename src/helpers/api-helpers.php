<?php

namespace Team51\Helper;

class API_Helper {
	
	public function call_pressable_api( $query, $method, $data ) {
		$api_request_url = PRESSABLE_API_ENDPOINT . $query;

		$data = json_encode( $data );

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: Bearer '. $this->get_pressable_api_token(),
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => $method,
				'content' => $data
			)
		);

		$context  = stream_context_create( $options );
		$result = @file_get_contents( $api_request_url, false, $context );

		return json_decode( $result );
	}

	public function get_pressable_api_token() {
		static $access_token = '';

		if( ! empty( $access_token ) ) {
			return $access_token;
		}

		$api_request_url = PRESSABLE_API_TOKEN_ENDPOINT;

		$data = array(
			'grant_type' => 'password',
			'client_id' => PRESSABLE_API_APP_CLIENT_ID,
			'client_secret' => PRESSABLE_API_APP_CLIENT_SECRET,
			'email' => PRESSABLE_ACCOUNT_EMAIL,
			'password' => PRESSABLE_ACCOUNT_PASSWORD,
		);

		$data = http_build_query( $data );

		$headers = array(
			'Content-Type: application/x-www-form-urlencoded',
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => 'POST',
				'content' => $data
			)
		);

		$context  = stream_context_create( $options );
		$result = @file_get_contents( $api_request_url, false, $context );

		$result = json_decode( $result );

		if( empty( $result->access_token ) ) {
			die( "Pressable API token could not be retrieved. Aborting!\n" );
		}

		$access_token = $result->access_token;

		return $access_token;
	}

	public function call_github_api( $query, $data, $method = 'POST' ) {
		$api_request_url = GITHUB_API_ENDPOINT . $query;

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: token '. GITHUB_API_TOKEN,
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => $method,
			)
		);

		if( in_array( $method, array( 'POST', 'PUT' ) ) ) {
			$data = json_encode( $data );
			$options['http']['content'] = $data;
		}

		$context  = stream_context_create( $options );
		$result = @file_get_contents( $api_request_url, false, $context );

		return json_decode( $result );
	}

	public function call_deploy_hq_api( $query, $method, $data ) {
		$api_request_url = DEPLOY_HQ_API_ENDPOINT . $query;

		$data = json_encode( $data );

		$headers = array(
			'Accept: application/json',
			'Content-type: application/json',
			'Authorization: Basic '. base64_encode( DEPLOY_HQ_USERNAME . ':' . DEPLOY_HQ_API_KEY ),
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => $method,
				'content' => $data,
				'timeout' => 60,
				'ignore_errors' => true,
			)
		);

		$context  = stream_context_create( $options );
		$result = @file_get_contents( $api_request_url, false, $context );

		return json_decode( $result );
	}


	public function log_to_slack( $message ) {
		if( ! defined( 'SLACK_WEBHOOK_URL' ) || empty( SLACK_WEBHOOK_URL ) ) {
			echo "Note: log_to_slack() won't work while SLACK_WEBHOOK_URL is undefined in config.json." . PHP_EOL;
		}

		$message = json_encode( array( 'message' => $message ) );

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'User-Agent: PHP',
		);

		$options = array(
			'http' => array(
				'header'  => $headers,
				'method'  => 'POST',
				'content' => $message,
			),
		);

		$context = stream_context_create( $options );
		$result  = @file_get_contents( SLACK_WEBHOOK_URL, false, $context );

		return json_decode( $result );
	}
}
