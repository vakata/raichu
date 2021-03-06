<?php
class FilecacheTest extends PHPUnit_Framework_TestCase
{
	protected static $cache = null;
	
	public static function setUpBeforeClass() {
		self::$cache = new \vakata\cache\Filecache(__DIR__ . '/../cache');
		self::$cache->clear();
		self::$cache->clear('test');
	}
	public static function tearDownAfterClass() {
		self::$cache->clear();
		self::$cache->clear('test');
	}
	protected function setUp() {
	}
	protected function tearDown() {
	}

	public function testSet() {
		$this->assertEquals('v1', self::$cache->set('key', 'v1'));
		$this->assertEquals('v2', self::$cache->set('key', 'v2', 'cache'));
		$this->assertEquals('v3', self::$cache->set('expire', 'v3', 'cache', 1));
	}
	/**
	 * @depends testSet
	 */
	public function testGet() {
		$this->assertEquals('v1', self::$cache->get('key'));
		$this->assertEquals('v2', self::$cache->get('key', 'cache'));
	}
	/**
	 * @depends testSet
	 */
	public function testExpire() {
		$this->setExpectedException('\vakata\cache\CacheException');
		sleep(2);
		self::$cache->get('expire', 'cache');
	}
	/**
	 * @depends testSet
	 */
	public function testDelete() {
		$this->setExpectedException('\vakata\cache\CacheException');
		self::$cache->delete('key', 'cache');
		self::$cache->get('key', 'cache');
	}
	public function testGetSet() {
		self::$cache->getSet('getset', function () { return 'v4'; }, 'cache');
		$this->assertEquals('v4', self::$cache->get('getset', 'cache'));
	}
	/**
	 * @depends testGetSet
	 */
	public function testClear() {
		$this->setExpectedException('\vakata\cache\CacheException');
		self::$cache->clear('cache');
		self::$cache->get('getset');
	}
}