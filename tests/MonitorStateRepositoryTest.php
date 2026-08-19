<?php
/**
 * MonitorStateRepository 單元測試
 *
 * 全部操作在暫存目錄下進行，不觸碰 storage/service-monitor/。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\MonitorStateRepository;
use Notifier\Tests\Fakes\FakeClock;
use PHPUnit_Framework_TestCase;

class MonitorStateRepositoryTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var string
	 */
	private $tempDir;

	protected function setUp()
	{
		$this->tempDir = sys_get_temp_dir() . '/notifier-state-test-' . uniqid('', true);
	}

	protected function tearDown()
	{
		if (is_dir($this->tempDir)) {
			foreach (glob($this->tempDir . '/*') as $file) {
				unlink($file);
			}
			rmdir($this->tempDir);
		}
	}

	public function testLoadReturnsEmptyArrayWhenFileMissing()
	{
		$repository = new MonitorStateRepository($this->tempDir, new FakeClock());

		$this->assertSame([], $repository->load());
	}

	public function testSaveThenLoadRoundTrips()
	{
		$repository = new MonitorStateRepository($this->tempDir, new FakeClock());

		$state = ['apache' => ['currentStatus' => 'healthy', 'consecutiveFailures' => 0]];
		$repository->save($state);

		$this->assertSame($state, $repository->load());
	}

	public function testSaveCreatesStorageDirectoryWhenMissing()
	{
		$this->assertFalse(is_dir($this->tempDir));

		$repository = new MonitorStateRepository($this->tempDir, new FakeClock());
		$repository->save(['apache' => ['currentStatus' => 'healthy']]);

		$this->assertTrue(is_dir($this->tempDir));
		$this->assertTrue(file_exists($this->tempDir . '/state.json'));
	}

	public function testCorruptStateFileIsBackedUpAndLoadReturnsEmptyState()
	{
		mkdir($this->tempDir, 0755, true);
		file_put_contents($this->tempDir . '/state.json', '{not valid json');

		$repository = new MonitorStateRepository($this->tempDir, new FakeClock());
		$result = $repository->load();

		$this->assertSame([], $result);

		$backups = glob($this->tempDir . '/state.json.corrupt-*');
		$this->assertCount(1, $backups);
		$this->assertSame('{not valid json', file_get_contents($backups[0]));
	}

	public function testNoLeftoverTempFilesAfterSave()
	{
		$repository = new MonitorStateRepository($this->tempDir, new FakeClock());
		$repository->save(['apache' => ['currentStatus' => 'healthy']]);

		$tempFiles = glob($this->tempDir . '/state.json.tmp-*');
		$this->assertCount(0, $tempFiles);
	}
}
