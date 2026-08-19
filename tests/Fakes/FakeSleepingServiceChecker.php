<?php
/**
 * ServiceCheckerInterface 測試替身，check() 時可選擇性 usleep()
 *
 * 用於測試 ServiceMonitor 的整批時間預算機制（需要真實流逝的時間）。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\ServiceCheckerInterface;

class FakeSleepingServiceChecker implements ServiceCheckerInterface
{
	/**
	 * @var string
	 */
	private $serviceKey;

	/**
	 * @var int
	 */
	private $sleepMicroseconds;

	/**
	 * @var string
	 */
	private $status;

	/**
	 * @param string $serviceKey
	 * @param int    $sleepMicroseconds
	 * @param string $status
	 */
	public function __construct($serviceKey, $sleepMicroseconds = 0, $status = 'healthy')
	{
		$this->serviceKey = $serviceKey;
		$this->sleepMicroseconds = $sleepMicroseconds;
		$this->status = $status;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		if ($this->sleepMicroseconds > 0) {
			usleep($this->sleepMicroseconds);
		}

		return [
			'serviceKey' => $this->serviceKey,
			'label'      => $this->serviceKey,
			'status'     => $this->status,
			'checkedAt'  => '2026-01-01T00:00:00+00:00',
			'latencyMs'  => (int)round($this->sleepMicroseconds / 1000),
			'method'     => 'fake',
			'message'    => 'fake result',
			'diagnostic' => [],
			'details'    => [],
		];
	}
}
