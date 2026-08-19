<?php
/**
 * HttpServiceChecker 單元測試
 *
 * 全部透過 FakeHttpClient 注入，不觸碰真實網路。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\HttpServiceChecker;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeHttpClient;
use PHPUnit_Framework_TestCase;

class HttpServiceCheckerTest extends PHPUnit_Framework_TestCase
{
	public function testStatusInRangeIsHealthy()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'ok', 'error' => null, 'latencyMs' => 12]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/'], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);
		$this->assertSame('web', $result['serviceKey']);
		$this->assertSame('http', $result['method']);
		$this->assertSame(200, $result['details']['httpStatus']);
	}

	public function testStatusOutsideRangeIsUnhealthy()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 500, 'body' => 'error', 'error' => null, 'latencyMs' => 8]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/'], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
	}

	public function testCustomStatusRangeAllows403()
	{
		// vm2 實測：預設 URL 回 403，部署端調整範圍後應判定健康
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 403, 'body' => 'forbidden', 'error' => null, 'latencyMs' => 5]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/', 'minStatus' => 200, 'maxStatus' => 403], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);
	}

	public function testBodyMismatchIsUnhealthy()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'unexpected content', 'error' => null, 'latencyMs' => 5]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/', 'expectedBodyContains' => 'welcome'], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
	}

	public function testConnectionFailureIsUnknownNotUnhealthy()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => false, 'httpStatus' => null, 'body' => null, 'error' => 'Connection timed out', 'latencyMs' => 5000]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/'], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
	}

	public function testMissingUrlIsUnknownConfigError()
	{
		$http = new FakeHttpClient();

		$checker = new HttpServiceChecker('web', [], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
		$this->assertEmpty($http->getCalls());
	}

	public function testCustomHostHeaderIsPassedToHttpClient()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'ok', 'error' => null, 'latencyMs' => 1]);

		$checker = new HttpServiceChecker('web', ['url' => 'http://127.0.0.1/', 'hostHeader' => 'example.local'], $http, new FakeClock());
		$checker->check();

		$calls = $http->getCalls();
		$this->assertSame('example.local', $calls[0]['options']['hostHeader']);
	}
}
