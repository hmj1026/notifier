<?php
/**
 * DailyReportGenerator 單元測試
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\DailyReportGenerator;
use Notifier\ServiceMonitor\MonitorLogRepository;
use Notifier\Tests\Fakes\FakeClock;
use PHPUnit_Framework_TestCase;

class DailyReportGeneratorTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var string
	 */
	private $tempDir;

	protected function setUp()
	{
		$this->tempDir = sys_get_temp_dir() . '/notifier-report-test-' . uniqid('', true);
	}

	protected function tearDown()
	{
		$this->removeDirectoryRecursively($this->tempDir);
	}

	public function testNoDataForDateReturnsHasDataFalse()
	{
		$clock = new FakeClock();
		$generator = new DailyReportGenerator(new MonitorLogRepository($this->tempDir, $clock), $clock, $this->tempDir);

		$report = $generator->generate('2026-01-15');

		$this->assertFalse($report['hasData']);
		$this->assertSame([], $report['services']);
	}

	public function testAllHealthyDayReports100PercentAvailability()
	{
		$clock = new FakeClock(new \DateTime('2026-01-15 08:00:00'));
		$logs = new MonitorLogRepository($this->tempDir, $clock);

		$logs->append(['serviceKey' => 'apache', 'label' => 'Apache', 'status' => 'healthy', 'checkedAt' => '2026-01-15T08:00:00+08:00', 'message' => 'ok']);
		$logs->append(['serviceKey' => 'apache', 'label' => 'Apache', 'status' => 'healthy', 'checkedAt' => '2026-01-15T08:05:00+08:00', 'message' => 'ok']);

		$generator = new DailyReportGenerator($logs, $clock, $this->tempDir, 5);
		$report = $generator->generate('2026-01-15');

		$this->assertTrue($report['hasData']);
		$this->assertSame(100.0, $report['services']['apache']['availabilityPercent']);
		$this->assertFalse($report['services']['apache']['stillDownAtDayEnd']);
	}

	public function testMixedResultsComputeCorrectCounts()
	{
		$clock = new FakeClock(new \DateTime('2026-01-15 08:00:00'));
		$logs = new MonitorLogRepository($this->tempDir, $clock);

		$logs->append(['serviceKey' => 'mysql', 'label' => 'MySQL', 'status' => 'healthy', 'checkedAt' => '2026-01-15T08:00:00+08:00', 'message' => 'ok']);
		$logs->append(['serviceKey' => 'mysql', 'label' => 'MySQL', 'status' => 'unhealthy', 'checkedAt' => '2026-01-15T08:05:00+08:00', 'message' => 'connection refused']);
		$logs->append(['serviceKey' => 'mysql', 'label' => 'MySQL', 'status' => 'unknown', 'checkedAt' => '2026-01-15T08:10:00+08:00', 'message' => 'timeout']);

		$generator = new DailyReportGenerator($logs, $clock, $this->tempDir, 5);
		$report = $generator->generate('2026-01-15');

		$summary = $report['services']['mysql'];
		$this->assertSame(3, $summary['actualChecks']);
		$this->assertSame(1, $summary['successCount']);
		$this->assertSame(1, $summary['failureCount']);
		$this->assertSame(1, $summary['unknownCount']);
		$this->assertTrue($summary['stillDownAtDayEnd']);
		$this->assertSame('connection refused', $summary['topError']);
	}

	public function testSentMarkerRoundTrip()
	{
		$clock = new FakeClock();
		$generator = new DailyReportGenerator(new MonitorLogRepository($this->tempDir, $clock), $clock, $this->tempDir);

		$this->assertFalse($generator->isAlreadySent('2026-01-15'));

		$generator->markAsSent('2026-01-15');

		$this->assertTrue($generator->isAlreadySent('2026-01-15'));
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
