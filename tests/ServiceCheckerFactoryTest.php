<?php
/**
 * ServiceCheckerFactory 單元測試
 *
 * 涵蓋 Config-Driven Service Registry 需求：MONITOR_SERVICES 缺漏視為
 * 頂層設定錯誤、單一服務設定錯誤降級為 unknown 而不拖垮整批、
 * apache/mysql 扁平命名 fallback、MONITOR_SERVICE_<KEY>_CLASS escape hatch。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\AlwaysUnknownServiceChecker;
use Notifier\ServiceMonitor\ApacheServiceChecker;
use Notifier\ServiceMonitor\CommandServiceChecker;
use Notifier\ServiceMonitor\HttpServiceChecker;
use Notifier\ServiceMonitor\MonitorConfigurationException;
use Notifier\ServiceMonitor\MySqlServiceChecker;
use Notifier\ServiceMonitor\ServiceCheckerFactory;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeCommandRunner;
use Notifier\Tests\Fakes\FakeHttpClient;
use PHPUnit_Framework_TestCase;

class ServiceCheckerFactoryTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @expectedException \Notifier\ServiceMonitor\MonitorConfigurationException
	 */
	public function testMissingMonitorServicesThrowsConfigurationException()
	{
		ServiceCheckerFactory::createAll([], new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());
	}

	/**
	 * @expectedException \Notifier\ServiceMonitor\MonitorConfigurationException
	 */
	public function testEmptyMonitorServicesThrowsConfigurationException()
	{
		ServiceCheckerFactory::createAll(['MONITOR_SERVICES' => '   '], new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());
	}

	public function testNamespacedCommandTypeIsCreated()
	{
		$rawConfig = [
			'MONITOR_SERVICES'                 => 'redis',
			'MONITOR_SERVICE_REDIS_TYPE'       => 'command',
			'MONITOR_SERVICE_REDIS_BINARY_PATH' => '/usr/bin/redis-cli',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertArrayHasKey('redis', $checkers);
		$this->assertInstanceOf(CommandServiceChecker::class, $checkers['redis']);
	}

	public function testApacheKeyDefaultsToHttpTypeViaFlatFallback()
	{
		$rawConfig = [
			'MONITOR_SERVICES'        => 'apache',
			'APACHE_HEALTHCHECK_URL'  => 'http://127.0.0.1/status',
			'APACHE_HTTP_MIN_STATUS'  => '200',
			'APACHE_HTTP_MAX_STATUS'  => '403',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertInstanceOf(ApacheServiceChecker::class, $checkers['apache']);
	}

	public function testMysqlKeyDefaultsToMysqlTypeViaFlatFallback()
	{
		$rawConfig = [
			'MONITOR_SERVICES' => 'mysql',
			'MYSQLADMIN_PATH'  => '/opt/lampp/bin/mysqladmin',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertInstanceOf(MySqlServiceChecker::class, $checkers['mysql']);
	}

	public function testSingleBadServiceDegradesToUnknownWithoutAbortingBatch()
	{
		$rawConfig = [
			'MONITOR_SERVICES'                 => 'apache,mysql,redis',
			'APACHE_HEALTHCHECK_URL'           => 'http://127.0.0.1/',
			'MYSQLADMIN_PATH'                  => '/opt/lampp/bin/mysqladmin',
			// redis 缺少必要參數 MONITOR_SERVICE_REDIS_BINARY_PATH
			'MONITOR_SERVICE_REDIS_TYPE'       => 'command',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertCount(3, $checkers);
		$this->assertInstanceOf(ApacheServiceChecker::class, $checkers['apache']);
		$this->assertInstanceOf(MySqlServiceChecker::class, $checkers['mysql']);
		$this->assertInstanceOf(AlwaysUnknownServiceChecker::class, $checkers['redis']);

		$redisResult = $checkers['redis']->check();
		$this->assertSame('unknown', $redisResult['status']);
	}

	public function testUnsupportedTypeDegradesToUnknown()
	{
		$rawConfig = [
			'MONITOR_SERVICES'          => 'weird',
			'MONITOR_SERVICE_WEIRD_TYPE' => 'not-a-real-type',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertInstanceOf(AlwaysUnknownServiceChecker::class, $checkers['weird']);
	}

	public function testMissingTypeForNonPresetKeyDegradesToUnknown()
	{
		$rawConfig = [
			'MONITOR_SERVICES' => 'redis',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertInstanceOf(AlwaysUnknownServiceChecker::class, $checkers['redis']);
	}

	public function testCustomClassEscapeHatch()
	{
		$rawConfig = [
			'MONITOR_SERVICES'                  => 'custom_svc',
			'MONITOR_SERVICE_CUSTOM_SVC_CLASS'  => 'Notifier\\Tests\\Fakes\\FakeCustomServiceChecker',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertArrayHasKey('custom_svc', $checkers);
		$result = $checkers['custom_svc']->check();
		$this->assertSame('healthy', $result['status']);
	}

	public function testUnknownCustomClassDegradesToUnknown()
	{
		$rawConfig = [
			'MONITOR_SERVICES'                  => 'custom_svc',
			'MONITOR_SERVICE_CUSTOM_SVC_CLASS'  => 'Notifier\\ServiceMonitor\\NoSuchClass',
		];

		$checkers = ServiceCheckerFactory::createAll($rawConfig, new FakeHttpClient(), new FakeCommandRunner(), new FakeClock());

		$this->assertInstanceOf(AlwaysUnknownServiceChecker::class, $checkers['custom_svc']);
	}
}
