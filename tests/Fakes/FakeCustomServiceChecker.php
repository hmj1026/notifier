<?php
/**
 * ServiceCheckerFactory 的 MONITOR_SERVICE_<KEY>_CLASS escape hatch 測試用固定裝置
 *
 * 建構子簽章需符合 ServiceCheckerFactory::createCustom() 的約定：
 * (string $serviceKey, array $rawConfig, string $namespace, ClockInterface $clock)
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\ClockInterface;
use Notifier\ServiceMonitor\ServiceCheckerInterface;

class FakeCustomServiceChecker implements ServiceCheckerInterface
{
	/**
	 * @var string
	 */
	private $serviceKey;

	/**
	 * @param string         $serviceKey
	 * @param array          $rawConfig
	 * @param string         $namespace
	 * @param ClockInterface $clock
	 */
	public function __construct($serviceKey, array $rawConfig, $namespace, ClockInterface $clock)
	{
		$this->serviceKey = $serviceKey;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		return [
			'serviceKey' => $this->serviceKey,
			'label'      => $this->serviceKey,
			'status'     => 'healthy',
			'checkedAt'  => '2026-01-01T00:00:00+00:00',
			'latencyMs'  => 0,
			'method'     => 'custom',
			'message'    => 'FakeCustomServiceChecker',
			'diagnostic' => [],
			'details'    => [],
		];
	}
}
