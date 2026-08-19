<?php
/**
 * NotificationSenderInterface 測試替身
 *
 * 依 queueResult() 呼叫順序回放預錄的發送結果，未預先排入時預設回傳 true。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\NotificationSenderInterface;

class FakeNotificationSender implements NotificationSenderInterface
{
	/**
	 * @var array
	 */
	private $results = [];

	/**
	 * @var array
	 */
	private $calls = [];

	/**
	 * @param bool $result
	 *
	 * @return void
	 */
	public function queueResult($result)
	{
		$this->results[] = $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function send(array $notification, array $checkResult, $hostName)
	{
		$this->calls[] = ['notification' => $notification, 'checkResult' => $checkResult, 'hostName' => $hostName];

		if (empty($this->results)) {
			return true;
		}

		return array_shift($this->results);
	}

	/**
	 * @return array
	 */
	public function getCalls()
	{
		return $this->calls;
	}
}
