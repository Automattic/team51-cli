<?php
/*
 * Plugin Name: Anonymizer
 * Description: Modifies personally identifiable information, resulting in anonymized data.
 * Version: 1.0.0
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: anonymizer
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/anonymize.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/utilities.php';