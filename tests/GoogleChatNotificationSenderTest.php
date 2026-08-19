<?php
/**
 * GoogleChatNotificationSender 單元測試
 *
 * 停用中的 GoogleChatNotifier 的 send() 不觸碰真實網路（沿用既有
 * GoogleChatNotifierTest 的慣例），只驗證格式化與傳送有被正確串接。
 */

namespace Notifier\Tests;

use Notifier\Notifier\GoogleChatNotifier;
use Notifier\ServiceMonitor\GoogleChatNotificationSender;
use Notifier\ServiceMonitor\GoogleChatServiceMessageFormatter;
use PHPUnit_Framework_TestCase;

class GoogleChatNotificationSenderTest extends PHPUnit_Framework_TestCase
{
	public function testSendDelegatesToNotifierAndReturnsItsResult()
	{
		$notifier = new GoogleChatNotifier('https://fake-webhook.url', false);
		$sender = new GoogleChatNotificationSender(new GoogleChatServiceMessageFormatter(), $notifier);

		$checkResult = [
			'serviceKey' => 'apache',
			'label'      => 'Apache',
			'status'     => 'unhealthy',
			'checkedAt'  => '2026-01-15T08:05:00+08:00',
			'latencyMs'  => 1,
			'method'     => 'http',
			'message'    => 'down',
			'diagnostic' => [],
			'details'    => [],
		];

		$result = $sender->send(['type' => 'initial', 'incidentId' => 'x'], $checkResult, 'host');

		$this->assertTrue($result);
	}
}
