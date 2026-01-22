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
	/**
	 * 測試 Cards V2 格式結構 (含折疊功能)
	 */
	public function testFormatMessageCardV2Structure()
	{
		$notifier = new GoogleChatNotifier('https://dummy.url');
		$analysisResult = [
			'success'          => false,
			'recordsProcessed' => 10,
			'successCount'     => 5,
			'failureCount'     => 5,
			'projectName'      => 'ANE072_Job',
			'logPath'          => '/path/to/log',
			'errorBreakdown'   => [
				'Error A' => 3,
				'Error B' => 2,
			],
		];

		$json = $notifier->formatMessage($analysisResult);
		$data = json_decode($json, true);

		// 驗證是否使用 cardsV2 格式
		// 注意: 若您的實作尚未更新，這裡會失敗 (Red)
		// 如果 formatMessage 仍返回舊格式 (text only)，這裡需確保新格式優先
		
		// 由於這是 TDD，我們期望它現在失敗或我們即將修改它
		if (isset($data['text'])) {
		    // 舊格式，標記為失敗或忽略，待實作
		}

		// 改為檢查特定結構
		// 但因為我要 Refactor formatMessage，所以測試應該 assertHasKey cardsV2
		$this->assertArrayHasKey('cardsV2', $data, 'Payload should use cardsV2 format');
		
		// 驗證是否包含折疊區塊
		$sections = $data['cardsV2'][0]['card']['sections'];
		$hasCollapsible = false;
		foreach ($sections as $section) {
			if (isset($section['header']) && strpos($section['header'], '失敗原因統計') !== false) {
				if (isset($section['collapsible']) && $section['collapsible'] === true) {
					$hasCollapsible = true;
				}
			}
		}
		
		$this->assertTrue($hasCollapsible, 'Payload should contain a collapsible section for error stats');
	}
}
