<?php
/**
 * ServiceMonitor（orchestrator）單元測試
 *
 * 涵蓋單一服務例外不中止整批檢查、整批時間預算耗盡時未執行的服務
 * 標記為 unknown、通知確認發送成功才更新 lastAlertAt、dry-run 不寫入。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\IncidentManager;
use Notifier\ServiceMonitor\MonitorLogRepository;
use Notifier\ServiceMonitor\MonitorStateRepository;
use Notifier\ServiceMonitor\ServiceMonitor;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeNotificationSender;
use Notifier\Tests\Fakes\FakeSleepingServiceChecker;
use Notifier\Tests\Fakes\FakeThrowingServiceChecker;
use PHPUnit_Framework_TestCase;

class ServiceMonitorTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var string
	 */
	private $tempDir;

	protected function setUp()
	{
		$this->tempDir = sys_get_temp_dir() . '/notifier-monitor-test-' . uniqid('', true);
	}

	protected function tearDown()
	{
		$this->removeDirectoryRecursively($this->tempDir);
	}

	private function makeMonitor(array $checkers, $totalBudgetSeconds = 240, FakeNotificationSender $sender = null)
	{
		$clock = new FakeClock();

		return new ServiceMonitor(
			$checkers,
			new MonitorStateRepository($this->tempDir, $clock),
			new MonitorLogRepository($this->tempDir, $clock),
			new IncidentManager($clock),
			$clock,
			'test-host',
			$totalBudgetSeconds,
			$sender
		);
	}

	public function testAllHealthyServicesReportNoUnhealthy()
	{
		$monitor = $this->makeMonitor([
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'healthy'),
			'mysql'  => new FakeSleepingServiceChecker('mysql', 0, 'healthy'),
		]);

		$outcome = $monitor->runCheck();

		$this->assertFalse($outcome['anyUnhealthy']);
		$this->assertSame('healthy', $outcome['results']['apache']['status']);
		$this->assertSame('healthy', $outcome['results']['mysql']['status']);
	}

	public function testUnhealthyServiceMarksAnyUnhealthyTrue()
	{
		$monitor = $this->makeMonitor([
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'unhealthy'),
		]);

		$outcome = $monitor->runCheck();

		$this->assertTrue($outcome['anyUnhealthy']);
	}

	public function testSingleCheckerExceptionDoesNotAbortOtherServices()
	{
		$monitor = $this->makeMonitor([
			'broken' => new FakeThrowingServiceChecker(),
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'healthy'),
		]);

		$outcome = $monitor->runCheck();

		$this->assertSame('unknown', $outcome['results']['broken']['status']);
		$this->assertSame('healthy', $outcome['results']['apache']['status']);
	}

	public function testBudgetExhaustionMarksRemainingServicesUnknownWithoutAffectingCompletedOnes()
	{
		// 第一個服務耗時 100ms，預算只有 50ms：第二、三個服務應被標記為 unknown（預算耗盡）
		$monitor = $this->makeMonitor([
			'first'  => new FakeSleepingServiceChecker('first', 100000, 'healthy'),
			'second' => new FakeSleepingServiceChecker('second', 0, 'healthy'),
			'third'  => new FakeSleepingServiceChecker('third', 0, 'healthy'),
		], 0.05);

		$outcome = $monitor->runCheck();

		$this->assertSame('healthy', $outcome['results']['first']['status']);
		$this->assertSame('unknown', $outcome['results']['second']['status']);
		$this->assertSame('unknown', $outcome['results']['third']['status']);
		$this->assertContains('預算', $outcome['results']['second']['message']);
	}

	public function testSuccessfulNotificationSendUpdatesLastAlertAt()
	{
		$sender = new FakeNotificationSender();
		$sender->queueResult(true);

		$monitor = $this->makeMonitor([
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'unhealthy'),
		], 240, $sender);

		$monitor->runCheck();

		$state = (new MonitorStateRepository($this->tempDir, new FakeClock()))->load();
		$this->assertNotNull($state['apache']['lastAlertAt']);
		$this->assertTrue($state['apache']['failureAlertSent']);
	}

	public function testFailedNotificationSendDoesNotUpdateLastAlertAt()
	{
		$sender = new FakeNotificationSender();
		$sender->queueResult(false);

		$monitor = $this->makeMonitor([
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'unhealthy'),
		], 240, $sender);

		$monitor->runCheck();

		$state = (new MonitorStateRepository($this->tempDir, new FakeClock()))->load();
		$this->assertNull($state['apache']['lastAlertAt']);
		$this->assertFalse($state['apache']['failureAlertSent']);
	}

	public function testDryRunDoesNotPersistStateOrLogs()
	{
		$monitor = $this->makeMonitor([
			'apache' => new FakeSleepingServiceChecker('apache', 0, 'unhealthy'),
		]);

		$monitor->runCheck(false);

		$this->assertFalse(file_exists($this->tempDir . '/state.json'));
		$this->assertFalse(is_dir($this->tempDir . '/logs'));
	}

	/**
	 * @param string $dir
	 *
	 * @return void
	 */
	private function removeDirectoryRecursively($dir)
	{
		if (!is_dir($dir)) {
			return;
		}

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $dir . '/' . $entry;

			if (is_dir($path)) {
				$this->removeDirectoryRecursively($path);
			} else {
				unlink($path);
			}
		}

		rmdir($dir);
	}
}
