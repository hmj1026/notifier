<?php
/**
 * MySqlServiceChecker 單元測試
 *
 * ping/query 兩種模式皆透過 FakeCommandRunner 注入，不觸碰真實
 * mysqladmin/mysql client。query 模式的權限檢查則針對真實建立的
 * 暫存檔案（僅測試檔案系統權限判斷，不實際執行查詢）。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\MySqlServiceChecker;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeCommandRunner;
use PHPUnit_Framework_TestCase;

class MySqlServiceCheckerTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var string|null
	 */
	private $tempFile;

	protected function tearDown()
	{
		if ($this->tempFile !== null && file_exists($this->tempFile)) {
			unlink($this->tempFile);
		}
		$this->tempFile = null;
	}

	public function testPingSuccessIsHealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 0, 'stdout' => 'mysqld is alive', 'stderr' => '', 'timedOut' => false, 'error' => null, 'latencyMs' => 4]);

		$checker = new MySqlServiceChecker('mysql', ['mode' => 'ping', 'mysqladminPath' => '/opt/lampp/bin/mysqladmin'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);
		$this->assertSame('mysql', $result['method']);
	}

	public function testPingFailureIsUnhealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 1, 'stdout' => '', 'stderr' => "Can't connect", 'timedOut' => false, 'error' => null, 'latencyMs' => 4]);

		$checker = new MySqlServiceChecker('mysql', ['mode' => 'ping', 'mysqladminPath' => '/opt/lampp/bin/mysqladmin'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
	}

	public function testPingMissingBinaryIsUnknownConfigError()
	{
		$runner = new FakeCommandRunner();

		$checker = new MySqlServiceChecker('mysql', ['mode' => 'ping'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
		$this->assertEmpty($runner->getCalls());
	}

	public function testQuerySuccessIsHealthy()
	{
		$this->tempFile = $this->createSecureCredentialsFile();

		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 0, 'stdout' => "1\n", 'stderr' => '', 'timedOut' => false, 'error' => null, 'latencyMs' => 6]);

		$checker = new MySqlServiceChecker('mysql', [
			'mode'              => 'query',
			'mysqlClientPath'   => '/opt/lampp/bin/mysql',
			'defaultsExtraFile' => $this->tempFile,
		], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);

		// 密碼路徑本身不含明文密碼，只確認參數是憑證檔路徑而非密碼字串
		$calls = $runner->getCalls();
		$this->assertContains('--defaults-extra-file=' . $this->tempFile, $calls[0]['args']);
	}

	public function testQueryUnexpectedResultIsUnhealthy()
	{
		$this->tempFile = $this->createSecureCredentialsFile();

		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 0, 'stdout' => "0\n", 'stderr' => '', 'timedOut' => false, 'error' => null, 'latencyMs' => 6]);

		$checker = new MySqlServiceChecker('mysql', [
			'mode'              => 'query',
			'mysqlClientPath'   => '/opt/lampp/bin/mysql',
			'defaultsExtraFile' => $this->tempFile,
		], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
	}

	public function testQueryMissingCredentialsFileIsUnknownConfigError()
	{
		$runner = new FakeCommandRunner();

		$checker = new MySqlServiceChecker('mysql', [
			'mode'              => 'query',
			'mysqlClientPath'   => '/opt/lampp/bin/mysql',
			'defaultsExtraFile' => '/no/such/credentials-file',
		], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
		$this->assertEmpty($runner->getCalls());
	}

	public function testQueryInsecurePermissionsIsUnknownNotUnhealthy()
	{
		$this->tempFile = $this->createSecureCredentialsFile();
		chmod($this->tempFile, 0644);

		$runner = new FakeCommandRunner();

		$checker = new MySqlServiceChecker('mysql', [
			'mode'              => 'query',
			'mysqlClientPath'   => '/opt/lampp/bin/mysql',
			'defaultsExtraFile' => $this->tempFile,
		], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
		$this->assertEmpty($runner->getCalls());
	}

	/**
	 * @return string
	 */
	private function createSecureCredentialsFile()
	{
		$path = tempnam(sys_get_temp_dir(), 'notifier-mysql-test-');
		file_put_contents($path, "[client]\npassword=dummy\n");
		chmod($path, 0600);

		return $path;
	}
}
