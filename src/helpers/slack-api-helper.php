<?php

namespace Team51\Helper;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Performs the calls to the SLACK API and parses the responses.
 */
final class Slack_API_Helper {
	// region METHODS

	/**
	 * Logs a message to Slack
	 *
	 * @param $message
	 *
	 * @return mixed
	 */
	public function log_to_slack( $message ) {
		if ( ! defined( 'SLACK_WEBHOOK_URL' ) || empty( SLACK_WEBHOOK_URL ) ) {
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

	// endregion
}
