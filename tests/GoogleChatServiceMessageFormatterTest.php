<?php
/**
 * GoogleChatServiceMessageFormatter 單元測試
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\GoogleChatServiceMessageFormatter;
use PHPUnit_Framework_TestCase;

class GoogleChatServiceMessageFormatterTest extends PHPUnit_Framework_TestCase
{
	private function checkResult($overrides = [])
	{
		return array_merge([
			'serviceKey' => 'apache',
			'label'      => 'Apache',
			'status'     => 'unhealthy',
			'checkedAt'  => '2026-01-15T08:05:00+08:00',
			'latencyMs'  => 12,
			'method'     => 'http',
			'message'    => 'HTTP 狀態碼 500 不在預期範圍',
			'diagnostic' => [],
			'details'    => ['httpStatus' => 500],
		], $overrides);
	}

	public function testAlertUsesCardsV2Format()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatIncidentNotification(
			['type' => 'initial', 'incidentId' => 'apache-20260115080500'],
			$this->checkResult(),
			'web-service-2'
		);

		$data = json_decode($json, true);

		$this->assertArrayHasKey('cardsV2', $data);
		$this->assertContains('web-service-2', $data['cardsV2'][0]['card']['header']['title']);
		$this->assertContains('Apache', $data['cardsV2'][0]['card']['header']['title']);
		$this->assertContains('異常', $data['cardsV2'][0]['card']['header']['title']);
	}

	public function testRepeatAlertTitleMarkedAsOngoing()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatIncidentNotification(
			['type' => 'repeat', 'incidentId' => 'apache-20260115080500'],
			$this->checkResult(),
			'web-service-2'
		);

		$data = json_decode($json, true);
		$this->assertContains('持續中', $data['cardsV2'][0]['card']['header']['title']);
	}

	public function testRecoveryNotificationIncludesDuration()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatIncidentNotification(
			[
				'type'           => 'recovery',
				'incidentId'     => 'apache-20260115080500',
				'firstFailureAt' => '2026-01-15T08:05:00+08:00',
				'recoveredAt'    => '2026-01-15T08:15:00+08:00',
			],
			$this->checkResult(['status' => 'healthy', 'message' => 'HTTP 健康檢查通過']),
			'web-service-2'
		);

		$data = json_decode($json, true);
		$this->assertContains('已恢復', $data['cardsV2'][0]['card']['header']['title']);

		$text = $data['cardsV2'][0]['card']['sections'][0]['widgets'][0]['textParagraph']['text'];
		$this->assertContains('10 分', $text);
	}

	public function testDetailsAreRenderedGenerically()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatIncidentNotification(
			['type' => 'initial', 'incidentId' => 'x'],
			$this->checkResult(['details' => ['exitCode' => 1, 'customField' => 'foo']]),
			'host'
		);

		$data = json_decode($json, true);
		$sections = $data['cardsV2'][0]['card']['sections'];
		$detailsSection = $sections[1];

		$this->assertSame('詳細資訊', $detailsSection['header']);
		$rendered = json_encode($detailsSection);
		$this->assertContains('exitCode', $rendered);
		$this->assertContains('customField', $rendered);
	}

	public function testSuspectedSecretIsMaskedInMessage()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatIncidentNotification(
			['type' => 'initial', 'incidentId' => 'x'],
			$this->checkResult(['message' => 'connection failed: password=hunter2secret']),
			'host'
		);

		$this->assertNotContains('hunter2secret', $json);
		$this->assertContains('password=***', $json);
	}

	public function testStatusSnapshotUsesCardsV2AndIncludesEveryService()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatStatusSnapshot(
			[
				'svc_ok' => $this->checkResult([
					'serviceKey' => 'svc_ok',
					'label'      => 'Web',
					'status'     => 'healthy',
					'message'    => 'HTTP 健康檢查通過',
					'details'    => [],
				]),
				'svc_bad' => $this->checkResult([
					'serviceKey' => 'svc_bad',
					'label'      => 'Database',
					'status'     => 'unhealthy',
					'message'    => 'ping 失敗',
				]),
			],
			'host-a'
		);

		$data = json_decode($json, true);

		$this->assertArrayHasKey('cardsV2', $data);
		$title = $data['cardsV2'][0]['card']['header']['title'];
		$this->assertContains('host-a', $title);
		$this->assertContains('當前檢測狀況', $title);

		$rendered = json_encode($data['cardsV2'][0]['card']['sections']);
		$this->assertContains('Web', $rendered);
		$this->assertContains('Database', $rendered);
		$this->assertContains('healthy', $rendered);
		$this->assertContains('unhealthy', $rendered);
	}

	public function testStatusSnapshotMasksSuspectedSecrets()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatStatusSnapshot(
			[
				'svc_ok' => $this->checkResult([
					'message' => 'failed token=super-secret-value',
					'details' => [],
				]),
			],
			'host'
		);

		$this->assertNotContains('super-secret-value', $json);
		$this->assertContains('token=***', $json);
	}

	public function testDailyReportNoDataShowsWarningNotFalseHealthy()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$json = $formatter->formatDailyReport(['date' => '2026-01-15', 'hasData' => false, 'services' => []], 'host');
		$data = json_decode($json, true);

		$text = $data['cardsV2'][0]['card']['sections'][0]['widgets'][0]['textParagraph']['text'];
		$this->assertContains('無任何監控紀錄', $text);
	}

	public function testDailyReportRendersPerServiceSection()
	{
		$formatter = new GoogleChatServiceMessageFormatter();

		$report = [
			'date'     => '2026-01-15',
			'hasData'  => true,
			'services' => [
				'apache' => [
					'label'               => 'Apache',
					'actualChecks'        => 288,
					'expectedChecks'      => 288,
					'coveragePercent'     => 100.0,
					'successCount'        => 288,
					'failureCount'        => 0,
					'unknownCount'        => 0,
					'availabilityPercent' => 100.0,
					'incidentCount'       => 0,
					'stillDownAtDayEnd'   => false,
					'topError'            => null,
				],
			],
		];

		$json = $formatter->formatDailyReport($report, 'host');
		$data = json_decode($json, true);

		$this->assertSame('Apache', $data['cardsV2'][0]['card']['sections'][0]['header']);
	}
}
