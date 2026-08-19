<?php
/**
 * CommandRunnerInterface 測試替身
 *
 * 依 queueResponse() 呼叫順序回放預錄回應，讓 checker 測試不需要真實子行程。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\CommandRunnerInterface;

class FakeCommandRunner implements CommandRunnerInterface
{
	/**
	 * 依呼叫順序排列的預錄回應佇列
	 *
	 * @var array
	 */
	private $responses = [];

	/**
	 * 記錄每次呼叫的 binaryPath/args/options，供測試斷言
	 *
	 * @var array
	 */
	private $calls = [];

	/**
	 * 排入下一次 run() 呼叫要回傳的回應
	 *
	 * @param array $response
	 *
	 * @return void
	 */
	public function queueResponse(array $response)
	{
		$this->responses[] = $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run($binaryPath, array $args = [], array $options = [])
	{
		$this->calls[] = ['binaryPath' => $binaryPath, 'args' => $args, 'options' => $options];

		if (empty($this->responses)) {
			return [
				'exitCode'  => null,
				'stdout'    => '',
				'stderr'    => '',
				'timedOut'  => false,
				'error'     => 'FakeCommandRunner：沒有預先排入的回應',
				'latencyMs' => 0,
			];
		}

		return array_shift($this->responses);
	}

	/**
	 * 取得所有已記錄的呼叫
	 *
	 * @return array
	 */
	public function getCalls()
	{
		return $this->calls;
	}
}
