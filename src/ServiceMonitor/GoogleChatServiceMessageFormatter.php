<?php
/**
 * 服務監控訊息格式化器
 *
 * 建立 Cards V2 格式的 JSON payload（異常告警／重複提醒／恢復通知／
 * 每日日報／手動現況快照），不修改也不繼承既有的 GoogleChatNotifier::formatMessage()
 * （該方法是既有 log 分析專用）。details{} 一律通用 key/value 渲染，
 * 不對任何服務別做特殊分支。動態內容（checker message、details 值、
 * 最常見錯誤摘要）在組出文字前先遮罩疑似秘密值並做 HTML escape，
 * 避免命令輸出中意外夾帶的密碼或標記破壞卡片內容。實際傳送交由既有、
 * 不變的 GoogleChatNotifier::send() 完成。
 *
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class GoogleChatServiceMessageFormatter
{
	/**
	 * 格式化一則異常/重複提醒/恢復通知
	 *
	 * @param array  $notification IncidentManager::evaluate() 回傳的通知描述
	 * @param array  $checkResult  ServiceCheckerInterface::check() 結果
	 * @param string $hostName
	 *
	 * @return string Cards V2 JSON 字串
	 */
	public function formatIncidentNotification(array $notification, array $checkResult, $hostName)
	{
		if ($notification['type'] === 'recovery') {
			return $this->formatRecovery($notification, $checkResult, $hostName);
		}

		return $this->formatAlert($notification, $checkResult, $hostName);
	}

	/**
	 * 格式化每日日報
	 *
	 * @param array  $report   DailyReportGenerator::generate() 回傳的資料
	 * @param string $hostName
	 *
	 * @return string Cards V2 JSON 字串
	 */
	public function formatDailyReport(array $report, $hostName)
	{
		$sections = [];

		if (empty($report['hasData'])) {
			$sections[] = [
				'widgets' => [
					['textParagraph' => ['text' => '⚠️ <b>本日無任何監控紀錄</b>，可能是監控程式未執行或儲存異常，請人工確認。']],
				],
			];
		} else {
			foreach ($report['services'] as $summary) {
				$sections[] = $this->buildReportServiceSection($summary);
			}
		}

		return $this->buildCard(
			"📊 [{$hostName}] 每日監控日報",
			'📅 日期：' . $report['date'],
			$sections
		);
	}

	/**
	 * 格式化一則涵蓋本次所有服務結果的現況訊息（手動確認通知通道用）
	 *
	 * 不依 serviceKey 分支；每筆結果用自身的 label / status / message。
	 *
	 * @param array  $results  serviceKey => check() 結果
	 * @param string $hostName
	 *
	 * @return string Cards V2 JSON 字串
	 */
	public function formatStatusSnapshot(array $results, $hostName)
	{
		$sections = [];
		$checkedAt = '';

		foreach ($results as $result) {
			if ($checkedAt === '' && !empty($result['checkedAt'])) {
				$checkedAt = $result['checkedAt'];
			}

			$label = isset($result['label']) ? $result['label'] : $result['serviceKey'];
			$status = isset($result['status']) ? $result['status'] : 'unknown';
			$icon = ($status === 'healthy') ? '✅' : (($status === 'unhealthy') ? '❌' : '❓');
			$message = isset($result['message']) ? $result['message'] : '';

			$sections[] = [
				'header'  => $label,
				'widgets' => [
					['textParagraph' => ['text' => $icon . ' <b>' . $this->sanitizeText($status) . '</b>：' . $this->sanitizeText($message)]],
				],
			];
		}

		if (empty($sections)) {
			$sections[] = [
				'widgets' => [
					['textParagraph' => ['text' => '沒有任何服務檢查結果']],
				],
			];
		}

		$subtitle = ($checkedAt !== '') ? ('📅 檢查時間：' . $checkedAt) : '手動發送，用以確認通知通道';

		return $this->buildCard("📋 [{$hostName}] 當前檢測狀況", $subtitle, $sections);
	}

	/**
	 * @param array  $notification
	 * @param array  $checkResult
	 * @param string $hostName
	 *
	 * @return string
	 */
	private function formatAlert(array $notification, array $checkResult, $hostName)
	{
		$isRepeat = ($notification['type'] === 'repeat');
		$label = isset($checkResult['label']) ? $checkResult['label'] : $checkResult['serviceKey'];
		$icon = $isRepeat ? '🔁' : '❌';
		$title = "{$icon} [{$hostName}] {$label} 異常" . ($isRepeat ? '（持續中）' : '');

		$sections = [
			[
				'widgets' => [
					['textParagraph' => ['text' => '⚠️ <b>說明</b>：' . $this->sanitizeText($checkResult['message'])]],
				],
			],
		];

		$detailsSection = $this->buildDetailsSection($checkResult);

		if ($detailsSection !== null) {
			$sections[] = $detailsSection;
		}

		return $this->buildCard($title, '📅 檢查時間：' . $checkResult['checkedAt'], $sections);
	}

	/**
	 * @param array  $notification
	 * @param array  $checkResult
	 * @param string $hostName
	 *
	 * @return string
	 */
	private function formatRecovery(array $notification, array $checkResult, $hostName)
	{
		$label = isset($checkResult['label']) ? $checkResult['label'] : $checkResult['serviceKey'];
		$title = "✅ [{$hostName}] {$label} 已恢復";

		$firstFailureAt = isset($notification['firstFailureAt']) ? $notification['firstFailureAt'] : null;
		$recoveredAt = isset($notification['recoveredAt']) ? $notification['recoveredAt'] : $checkResult['checkedAt'];
		$durationText = $this->formatDuration($firstFailureAt, $recoveredAt);

		$text = '⏱ <b>異常開始</b>：' . ($firstFailureAt !== null ? $firstFailureAt : '未知')
			. "\n✅ <b>恢復時間</b>：{$recoveredAt}"
			. "\n⏳ <b>異常持續</b>：{$durationText}";

		$sections = [['widgets' => [['textParagraph' => ['text' => $text]]]]];

		return $this->buildCard($title, '📅 恢復時間：' . $recoveredAt, $sections);
	}

	/**
	 * @param array $summary
	 *
	 * @return array
	 */
	private function buildReportServiceSection(array $summary)
	{
		$text = "監測覆蓋率：{$summary['coveragePercent']}%（{$summary['actualChecks']}/{$summary['expectedChecks']} 次，估算值）\n"
			. "監測可用率：{$summary['availabilityPercent']}%（估算值，非精確 SLA uptime）\n"
			. "成功／失敗／未知：{$summary['successCount']} / {$summary['failureCount']} / {$summary['unknownCount']}\n"
			. "異常事件數：{$summary['incidentCount']}";

		if (!empty($summary['stillDownAtDayEnd'])) {
			$text .= "\n⚠️ <b>日末時仍未恢復</b>";
		}

		if (!empty($summary['topError'])) {
			$text .= "\n最常見錯誤：" . $this->sanitizeText($summary['topError']);
		}

		return [
			'header'  => $summary['label'],
			'widgets' => [['textParagraph' => ['text' => $text]]],
		];
	}

	/**
	 * @param array $checkResult
	 *
	 * @return array|null
	 */
	private function buildDetailsSection(array $checkResult)
	{
		$details = isset($checkResult['details']) ? $checkResult['details'] : [];

		if (empty($details)) {
			return null;
		}

		$widgets = [];

		foreach ($details as $key => $value) {
			if (is_array($value)) {
				$value = json_encode($value, JSON_UNESCAPED_UNICODE);
			}

			$widgets[] = ['textParagraph' => ['text' => '• ' . $key . '：' . $this->sanitizeText((string)$value)]];
		}

		return [
			'header'  => '詳細資訊',
			'widgets' => $widgets,
		];
	}

	/**
	 * @param string $title
	 * @param string $subtitle
	 * @param array  $sections
	 *
	 * @return string
	 */
	private function buildCard($title, $subtitle, array $sections)
	{
		$cardStructure = [
			'cardsV2' => [
				[
					'cardId' => uniqid(),
					'card'   => [
						'header'   => [
							'title'    => $title,
							'subtitle' => $subtitle,
						],
						'sections' => $sections,
					],
				],
			],
		];

		return json_encode($cardStructure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param string|null $startIso
	 * @param string      $endIso
	 *
	 * @return string
	 */
	private function formatDuration($startIso, $endIso)
	{
		if ($startIso === null) {
			return '未知';
		}

		$seconds = max(0, strtotime($endIso) - strtotime($startIso));
		$hours = (int)floor($seconds / 3600);
		$minutes = (int)floor(($seconds % 3600) / 60);
		$remainingSeconds = $seconds % 60;

		if ($hours > 0) {
			return "{$hours} 小時 {$minutes} 分";
		}

		if ($minutes > 0) {
			return "{$minutes} 分 {$remainingSeconds} 秒";
		}

		return "{$remainingSeconds} 秒";
	}

	/**
	 * 遮罩看起來像秘密值的內容並做 HTML escape，避免 webhook URL/密碼
	 * 或不明標記意外出現在通知文字或破壞卡片結構
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private function sanitizeText($text)
	{
		$masked = preg_replace('/(password|passwd|secret|token|key)=([^&\s]+)/i', '$1=***', $text);

		return htmlspecialchars($masked, ENT_QUOTES, 'UTF-8');
	}
}
