<?php
/**
 * GoogleChatNotifier 單元測試
 *
 * 測試 Google Chat Webhook 通知器
 * 支援 PHPUnit 5.7+
 */

namespace Notifier\Tests;

use Notifier\Notifier\GoogleChatNotifier;
use PHPUnit_Framework_TestCase;

class GoogleChatNotifierTest extends PHPUnit_Framework_TestCase
{
	/**
	 * 測試格式化成功訊息
	 */
	public function testFormatMessageSuccess()
	{
		$notifier = new GoogleChatNotifier('https://fake-webhook.url', true);

		$analysis = [
			'success'          => true,
			'recordsProcessed' => 13,
			'personIds'        => ['70013', '00073'],
			'errorType'        => '',
			'errorHint'        => '',
			'logPath'          => 'log/2026/01/22/SendDelivery.log',
		];

		$message = $notifier->formatMessage($analysis);

		$this->assertContains('✅', $message);
		$this->assertContains('執行成功', $message);
		$this->assertContains('13 筆', $message);
		$this->assertContains('70013', $message);
		$this->assertContains('00073', $message);
		$this->assertContains('log/2026/01/22/SendDelivery.log', $message);
	}

	/**
	 * 測試格式化失敗訊息
	 */
	public function testFormatMessageFailure()
	{
		$notifier = new GoogleChatNotifier('https://fake-webhook.url', true);

		$analysis = [
			'success'          => false,
			'recordsProcessed' => 0,
			'personIds'        => [],
			'errorType'        => 'SOAP 通訊錯誤',
			'errorHint'        => '請檢查網路連線或 WebService 服務',
			'logPath'          => 'log/2026/01/17/SendDelivery.log',
		];

		$message = $notifier->formatMessage($analysis);

		$this->assertContains('❌', $message);
		$this->assertContains('執行失敗', $message);
		$this->assertContains('SOAP 通訊錯誤', $message);
		$this->assertContains('請儘速檢查處理', $message);
	}

	/**
	 * 測試停用時 send() 回傳 true
	 */
	public function testSendWhenDisabled()
	{
		$notifier = new GoogleChatNotifier('https://fake-webhook.url', false);

		$result = $notifier->send('Test message', true);

		$this->assertTrue($result);
	}

	/**
	 * 測試建構子正確設定屬性
	 */
	public function testConstructorSetsProperties()
	{
		$webhookUrl = 'https://chat.googleapis.com/v1/spaces/test';
		$notifier = new GoogleChatNotifier($webhookUrl, true);

		// 透過 formatMessage 間接驗證 notifier 已正確初始化
		$analysis = [
			'success'          => true,
			'recordsProcessed' => 5,
			'personIds'        => [],
			'errorType'        => '',
			'errorHint'        => '',
			'logPath'          => 'test.log',
		];

		$message = $notifier->formatMessage($analysis);
		$this->assertNotEmpty($message);
	}

	/**
	 * 測試訊息包含執行時間
	 */
	public function testMessageContainsTimestamp()
	{
		$notifier = new GoogleChatNotifier('https://fake-webhook.url', true);

		$analysis = [
			'success'          => true,
			'recordsProcessed' => 1,
			'personIds'        => [],
			'errorType'        => '',
			'errorHint'        => '',
			'logPath'          => 'test.log',
		];

		$message = $notifier->formatMessage($analysis);

		$this->assertContains('📅', $message);
		$this->assertContains('執行時間', $message);
	}
}
