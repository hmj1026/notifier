<?php
/**
 * ShellCommandRunner 單元測試
 *
 * 針對真實子行程執行的行為做基本驗證：exit code 擷取、
 * binary 不存在時的錯誤、以及逾時終止不讓測試卡住。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\ShellCommandRunner;
use PHPUnit_Framework_TestCase;

class ShellCommandRunnerTest extends PHPUnit_Framework_TestCase
{
	/**
	 * 測試成功執行並擷取標準輸出
	 */
	public function testRunCapturesStdoutAndExitCode()
	{
		$runner = new ShellCommandRunner();

		$result = $runner->run('/bin/echo', ['hello-service-monitor']);

		$this->assertFalse($result['timedOut']);
		$this->assertSame(0, $result['exitCode']);
		$this->assertContains('hello-service-monitor', $result['stdout']);
		$this->assertNull($result['error']);
	}

	/**
	 * 測試非 0 exit code 正確回傳
	 */
	public function testRunCapturesNonZeroExitCode()
	{
		$runner = new ShellCommandRunner();

		$result = $runner->run('/bin/false');

		$this->assertFalse($result['timedOut']);
		$this->assertSame(1, $result['exitCode']);
	}

	/**
	 * 測試 binary 不存在時回傳明確錯誤，而非拋出例外
	 */
	public function testRunReturnsErrorWhenBinaryMissing()
	{
		$runner = new ShellCommandRunner();

		$result = $runner->run('/no/such/binary-xyz');

		$this->assertNull($result['exitCode']);
		$this->assertFalse($result['timedOut']);
		$this->assertNotEmpty($result['error']);
	}

	/**
	 * 測試逾時會終止命令並回傳 timedOut，而非卡住測試
	 */
	public function testRunTimesOutWithoutHanging()
	{
		$runner = new ShellCommandRunner();

		$startedAt = microtime(true);
		$result = $runner->run('/bin/sleep', ['5'], ['timeoutSeconds' => 1]);
		$elapsedSeconds = microtime(true) - $startedAt;

		$this->assertTrue($result['timedOut']);
		$this->assertNull($result['exitCode']);
		$this->assertLessThan(3, $elapsedSeconds);
	}

	/**
	 * 測試輸出長度受 maxOutputBytes 上限保護
	 */
	public function testRunTruncatesOutputToMaxOutputBytes()
	{
		$runner = new ShellCommandRunner();

		$result = $runner->run('/bin/echo', [str_repeat('a', 200)], ['maxOutputBytes' => 10]);

		$this->assertLessThanOrEqual(10, strlen($result['stdout']));
	}
}
