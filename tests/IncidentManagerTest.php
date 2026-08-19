<?php
/**
 * IncidentManager 單元測試
 *
 * 涵蓋 Per-Service State Machine / Repeat-Alert Throttling /
 * Recovery Notification & Incident Close / Notification-Failure Safety
 * 四個需求的核心情境。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\IncidentManager;
use Notifier\Tests\Fakes\FakeClock;
use PHPUnit_Framework_TestCase;

class IncidentManagerTest extends PHPUnit_Framework_TestCase
{
	private function healthyResult()
	{
		return ['status' => 'healthy', 'message' => 'ok'];
	}

	private function unhealthyResult($message = 'down')
	{
		return ['status' => 'unhealthy', 'message' => $message];
	}

	private function unknownResult($message = 'timeout')
	{
		return ['status' => 'unknown', 'message' => $message];
	}

	public function testFirstEverHealthyCheckCreatesStateWithoutNotification()
	{
		$manager = new IncidentManager(new FakeClock());

		$evaluation = $manager->evaluate('apache', $this->healthyResult(), null);

		$this->assertNull($evaluation['notification']);
		$this->assertSame('healthy', $evaluation['state']['currentStatus']);
	}

	public function testFirstEverUnhealthyCheckSendsInitialAlertAtDefaultThreshold()
	{
		$manager = new IncidentManager(new FakeClock());

		$evaluation = $manager->evaluate('apache', $this->unhealthyResult(), null);

		$this->assertSame('unhealthy', $evaluation['state']['currentStatus']);
		$this->assertNotNull($evaluation['notification']);
		$this->assertSame('initial', $evaluation['notification']['type']);
	}

	public function testUnknownDoesNotAffectCountersWhenCurrentlyHealthy()
	{
		$manager = new IncidentManager(new FakeClock());

		$healthy = $manager->evaluate('apache', $this->healthyResult(), null);
		$afterUnknown = $manager->evaluate('apache', $this->unknownResult(), $healthy['state']);

		$this->assertNull($afterUnknown['notification']);
		$this->assertSame('healthy', $afterUnknown['state']['currentStatus']);
		$this->assertSame(0, $afterUnknown['state']['consecutiveFailures']);
		$this->assertSame('timeout', $afterUnknown['state']['lastError']);
	}

	public function testUnknownDoesNotAffectCountersWhenCurrentlyUnhealthy()
	{
		$manager = new IncidentManager(new FakeClock(), 1, 2);

		$unhealthy = $manager->evaluate('apache', $this->unhealthyResult(), null);
		$unhealthy['state'] = $manager->markNotificationSent($unhealthy['state']);

		$afterUnknown = $manager->evaluate('apache', $this->unknownResult(), $unhealthy['state']);

		$this->assertNull($afterUnknown['notification']);
		$this->assertSame('unhealthy', $afterUnknown['state']['currentStatus']);
		$this->assertSame(0, $afterUnknown['state']['consecutiveSuccesses']);
	}

	public function testRepeatedUnhealthyChecksDoNotResendWithinThrottleWindow()
	{
		$clock = new FakeClock(new \DateTime('2026-01-01 00:00:00'));
		$manager = new IncidentManager($clock, 1, 1, 60);

		$first = $manager->evaluate('apache', $this->unhealthyResult(), null);
		$this->assertSame('initial', $first['notification']['type']);
		$state = $manager->markNotificationSent($first['state']);

		for ($i = 0; $i < 4; $i++) {
			$clock->advance('PT5M');
			$evaluation = $manager->evaluate('apache', $this->unhealthyResult(), $state);
			$this->assertNull($evaluation['notification'], "iteration {$i} should not notify within throttle window");
			$state = $evaluation['state'];
		}
	}

	public function testRepeatAlertFiresAfterThrottleWindowElapses()
	{
		$clock = new FakeClock(new \DateTime('2026-01-01 00:00:00'));
		$manager = new IncidentManager($clock, 1, 1, 60);

		$first = $manager->evaluate('apache', $this->unhealthyResult(), null);
		$state = $manager->markNotificationSent($first['state']);

		$clock->advance('PT61M');
		$evaluation = $manager->evaluate('apache', $this->unhealthyResult(), $state);

		$this->assertNotNull($evaluation['notification']);
		$this->assertSame('repeat', $evaluation['notification']['type']);
	}

	public function testRecoveryAfterThresholdSendsRecoveryNotificationAndClosesIncident()
	{
		$manager = new IncidentManager(new FakeClock(), 1, 1);

		$first = $manager->evaluate('apache', $this->unhealthyResult(), null);
		$state = $manager->markNotificationSent($first['state']);
		$this->assertNotNull($state['currentIncidentId']);

		$evaluation = $manager->evaluate('apache', $this->healthyResult(), $state);

		$this->assertSame('recovery', $evaluation['notification']['type']);
		$this->assertSame('healthy', $evaluation['state']['currentStatus']);
		$this->assertNull($evaluation['state']['currentIncidentId']);
	}

	public function testFailedNotificationSendIsRetriedOnNextCheck()
	{
		$manager = new IncidentManager(new FakeClock());

		$first = $manager->evaluate('apache', $this->unhealthyResult(), null);
		$this->assertSame('initial', $first['notification']['type']);

		// send() 失敗：呼叫端不會呼叫 markNotificationSent()，failureAlertSent 維持 false
		$stateAfterFailedSend = $first['state'];
		$this->assertFalse($stateAfterFailedSend['failureAlertSent']);

		$next = $manager->evaluate('apache', $this->unhealthyResult(), $stateAfterFailedSend);

		$this->assertNotNull($next['notification']);
		$this->assertSame('initial', $next['notification']['type']);
	}
}
