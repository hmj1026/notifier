<?php
/**
 * CommandServiceChecker 單元測試
 *
 * 全部透過 FakeCommandRunner 注入，不觸碰真實子行程。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\CommandServiceChecker;
use Notifier\Tests\Fakes\FakeClock;
use Notifier\Tests\Fakes\FakeCommandRunner;
use PHPUnit_Framework_TestCase;

class CommandServiceCheckerTest extends PHPUnit_Framework_TestCase
{
	public function testExpectedExitCodeIsHealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 0, 'stdout' => 'PONG', 'stderr' => '', 'timedOut' => false, 'error' => null, 'latencyMs' => 3]);

		$checker = new CommandServiceChecker('redis', ['binaryPath' => '/usr/bin/redis-cli', 'args' => ['ping']], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('healthy', $result['status']);
		$this->assertSame('command', $result['method']);
	}

	public function testUnexpectedExitCodeIsUnhealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 1, 'stdout' => '', 'stderr' => 'error', 'timedOut' => false, 'error' => null, 'latencyMs' => 3]);

		$checker = new CommandServiceChecker('redis', ['binaryPath' => '/usr/bin/redis-cli'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
		$this->assertSame(1, $result['details']['exitCode']);
	}

	public function testOutputMismatchIsUnhealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => 0, 'stdout' => 'unexpected', 'stderr' => '', 'timedOut' => false, 'error' => null, 'latencyMs' => 3]);

		$checker = new CommandServiceChecker('redis', ['binaryPath' => '/usr/bin/redis-cli', 'expectedOutputContains' => 'PONG'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unhealthy', $result['status']);
	}

	public function testTimeoutIsUnknownNotUnhealthy()
	{
		$runner = new FakeCommandRunner();
		$runner->queueResponse(['exitCode' => null, 'stdout' => '', 'stderr' => '', 'timedOut' => true, 'error' => null, 'latencyMs' => 10000]);

		$checker = new CommandServiceChecker('redis', ['binaryPath' => '/usr/bin/redis-cli'], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
	}

	public function testMissingBinaryPathIsUnknownConfigError()
	{
		$runner = new FakeCommandRunner();

		$checker = new CommandServiceChecker('redis', [], $runner, new FakeClock());
		$result = $checker->check();

		$this->assertSame('unknown', $result['status']);
		$this->assertEmpty($runner->getCalls());
	}
}
