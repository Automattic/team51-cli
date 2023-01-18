<?php
declare( strict_types=1 );

namespace Team51\Test;

use PHPUnit\Framework\TestCase;

use Team51\Command\Site_List;

/**
 * Tests for the Site_List class.
 */
class Site_List_Test extends T51TestCase {

	/**
	 * Site_List instance.
	 *
	 * @var Site_List
	 */
	public $site_list;

	public function __construct() {
		parent::__construct();
		$this->site_list = new Site_List();
	}

	/**
	 * Checks test for 'is_coming_soon' property in API response.
	 *
	 * @covers Site_List::eval_is_coming_soon()
	 */
	public function test_coming_soon_site_returns_is_coming_soon() {
		$site = (object) array(
			'name'           => 'test_name',
			'host'           => 'test_host',
			'is_coming_soon' => true,
		);

		$expected = 'is_coming_soon';
		$actual   = $this->invoke_method( $this->site_list, 'eval_is_coming_soon', array( $site ) );
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Checks test for 'is_coming_soon' property in API response.
	 *
	 * @covers Site_List::eval_is_coming_soon()
	 */
	public function test_non_coming_soon_site_returns_empty_string() {
		$site = (object) array(
			'name'           => 'test_name',
			'host'           => 'test_host',
			'is_coming_soon' => false,
		);

		$expected = '';
		$actual   = $this->invoke_method( $this->site_list, 'eval_is_coming_soon', array( $site ) );
		$this->assertEquals( $expected, $actual );
	}
}
