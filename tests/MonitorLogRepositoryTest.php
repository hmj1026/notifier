<?php
/**
 * MonitorLogRepository 單元測試
 *
 * 全部操作在暫存目錄下進行，不觸碰 storage/service-monitor/。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\MonitorLogRepository;
use Notifier\Tests\Fakes\FakeClock;
use PHPUnit_Framework_TestCase;

class MonitorLogRepositoryTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var string
	 */
	private $tempDir;

	protected function setUp()
	{
		$this->tempDir = sys_get_temp_dir() . '/notifier-log-test-' . uniqid('', true);
	}

	protected function tearDown()
	{
		$this->removeDirectoryRecursively($this->tempDir);
	}

	public function testReadForDateReturnsEmptyArrayWhenFileMissing()
	{
		$repository = new MonitorLogRepository($this->tempDir, new FakeClock());

		$this->assertSame([], $repository->readForDate('2026-01-01'));
	}

	public function testAppendThenReadForDateRoundTrips()
	{
		$clock = new FakeClock(new \DateTime('2026-01-15 08:00:00'));
		$repository = new MonitorLogRepository($this->tempDir, $clock);

		$repository->append(['serviceKey' => 'apache', 'status' => 'healthy']);

		$records = $repository->readForDate('2026-01-15');
		$this->assertCount(1, $records);
		$this->assertSame('apache', $records[0]['serviceKey']);
	}

	public function testMultipleAppendsAccumulateLines()
	{
		$clock = new FakeClock(new \DateTime('2026-01-15 08:00:00'));
		$repository = new MonitorLogRepository($this->tempDir, $clock);

		$repository->append(['serviceKey' => 'apache', 'status' => 'healthy']);
		$repository->append(['serviceKey' => 'mysql', 'status' => 'unhealthy']);

		$records = $repository->readForDate('2026-01-15');
		$this->assertCount(2, $records);
		$this->assertSame('apache', $records[0]['serviceKey']);
		$this->assertSame('mysql', $records[1]['serviceKey']);
	}

	public function testPurgeExpiredRemovesOldFilesButKeepsRecentOnes()
	{
		$clock = new FakeClock(new \DateTime('2026-02-01 00:00:00'));
		$repository = new MonitorLogRepository($this->tempDir, $clock, 30);

		mkdir($this->tempDir . '/logs', 0755, true);
		file_put_contents($this->tempDir . '/logs/2025-12-01.jsonl', "{}\n"); // 62 天前，應被清除
		file_put_contents($this->tempDir . '/logs/2026-01-25.jsonl', "{}\n"); // 7 天前，應保留

		$repository->purgeExpired();

		$this->assertFalse(file_exists($this->tempDir . '/logs/2025-12-01.jsonl'));
		$this->assertTrue(file_exists($this->tempDir . '/logs/2026-01-25.jsonl'));
	}

	public function testPurgeExpiredDoesNothingWhenRetentionIsZero()
	{
		$clock = new FakeClock(new \DateTime('2026-02-01 00:00:00'));
		$repository = new MonitorLogRepository($this->tempDir, $clock, 0);

		mkdir($this->tempDir . '/logs', 0755, true);
		file_put_contents($this->tempDir . '/logs/2025-01-01.jsonl', "{}\n");

		$repository->purgeExpired();

		$this->assertTrue(file_exists($this->tempDir . '/logs/2025-01-01.jsonl'));
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
