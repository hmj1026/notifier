<?php
/**
 * ApacheServiceChecker 單元測試
 *
 * 驗證這是 HttpServiceChecker 的薄封裝（預設值 + 選填診斷組合），
 * 而非獨立的判定邏輯。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\ApacheServiceChecker;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeDiagnosticProvider;
use Notifier\Tests\Fakes\FakeHttpClient;
use PHPUnit_Framework_TestCase;

class ApacheServiceCheckerTest extends PHPUnit_Framework_TestCase
{
	public function testDefaultsToLocalhostRootUrl()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'ok', 'error' => null, 'latencyMs' => 1]);

		$checker = new ApacheServiceChecker('apache', [], $http, new FakeClock());
		$checker->check();

		$calls = $http->getCalls();
		$this->assertSame('http://127.0.0.1/', $calls[0]['url']);
	}

	public function testConfigOverridesDefaults()
	{
		// vm2 實測：預設 URL 回 403，部署端必須能覆寫成實際環境的設定
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 403, 'body' => 'forbidden', 'error' => null, 'latencyMs' => 1]);

		$checker = new ApacheServiceChecker('apache', ['url' => 'http://127.0.0.1/status', 'minStatus' => 200, 'maxStatus' => 403], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);

		$calls = $http->getCalls();
		$this->assertSame('http://127.0.0.1/status', $calls[0]['url']);
	}

	public function testDiagnosticProviderResultIsAttachedWithoutAffectingStatus()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'ok', 'error' => null, 'latencyMs' => 1]);

		$diagnosticProvider = new FakeDiagnosticProvider(['source' => 'lampp status', 'parsed' => ['mysql' => 'not_running']]);

		$checker = new ApacheServiceChecker('apache', [], $http, new FakeClock(), $diagnosticProvider);
		$result = $checker->check();

		// 獨立服務的健康判定完全依據自身 checker 結果，不受診斷附加資訊影響
		$this->assertSame('healthy', $result['status']);
		$this->assertSame(['source' => 'lampp status', 'parsed' => ['mysql' => 'not_running']], $result['diagnostic']);
	}

	public function testNoDiagnosticProviderLeavesDiagnosticEmpty()
	{
		$http = new FakeHttpClient();
		$http->queueResponse(['ok' => true, 'httpStatus' => 200, 'body' => 'ok', 'error' => null, 'latencyMs' => 1]);

		$checker = new ApacheServiceChecker('apache', [], $http, new FakeClock());
		$result = $checker->check();

		$this->assertSame([], $result['diagnostic']);
	}
}
