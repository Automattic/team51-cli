<?php
declare(strict_types=1);

namespace Team51\Test;

use PHPUnit\Framework\TestCase;

use Team51\Helper\WPCOM_API_Helper;

/**
 * Tests for the WPCOM_API_Helper class.
 */
class WPCOM_API_Helper_Test extends T51TestCase {

	/**
	 * WPCOM_API_Helper instance.
	 *
	 * @var WPCOM_API_Helper
	 */
	public $wpcom_api_helper;

	public function __construct() {
		parent::__construct();
		$this->wpcom_api_helper = new WPCOM_API_Helper();
	}

	/**
	 * Test assembly of request URL.
	 *
	 * @covers WPCOM_API_Helper::get_request_url()
	 */
	public function test_get_request_url_adds_prefix_with_integer() {
		$expected = 'https://public-api.wordpress.com/rest/v1.1/sites/123';
		$actual   = $this->invoke_method( $this->wpcom_api_helper, 'get_request_url', array( 'sites/123' ) );
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test assembly of request URL.
	 *
	 * @covers WPCOM_API_Helper::get_request_url()
	 */
	public function test_get_request_url_adds_prefix_with_domain() {
		$expected = 'https://public-api.wordpress.com/rest/v1.1/sites/en.blog.wordpress.com';
		$actual   = $this->invoke_method( $this->wpcom_api_helper, 'get_request_url', array( 'sites/en.blog.wordpress.com' ) );
		$this->assertEquals( $expected, $actual );
	}

}
