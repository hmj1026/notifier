<?php
/**
 * Google Chat Webhook 通知器
 *
 * 透過 Webhook 發送通知至 Google Chat 空間。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier
 */

namespace Notifier\Notifier;

use Notifier\Notifier as BaseNotifier;

class GoogleChatNotifier extends BaseNotifier
{
	/**
	 * Webhook URL
	 *
	 * @var string
	 */
	private $webhookUrl;

	/**
	 * HTTP 超時時間 (秒)
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * 建構子
	 *
	 * @param string $webhookUrl Google Chat Webhook URL
	 * @param bool   $enabled    是否啟用通知
	 * @param int    $timeout    HTTP 超時時間 (秒)
	 */
	public function __construct($webhookUrl, $enabled = true, $timeout = 30)
	{
		parent::__construct($enabled);
		$this->webhookUrl = $webhookUrl;
		$this->timeout = $timeout;
	}

	/**
	 * 發送通知
	 *
	 * @param string $message   訊息內容
	 * @param bool   $isSuccess 是否成功 (此參數保留供未來使用)
	 *
	 * @return bool 發送結果
	 */
	public function send($message, $isSuccess)
	{
		if (!$this->enabled) {
			return true;
		}

		// 判斷 $message 是否已經是 JSON (Cards 格式)
		$payload = '';
		$firstChar = substr(trim($message), 0, 1);
		if ($firstChar === '{' && (strpos($message, '"cards"') !== false || strpos($message, '"cardsV2"') !== false)) {
			$payload = $message;
		} else {
			// 一般文字訊息，包裝成 text payload
			$payload = json_encode(['text' => $message]);
		}

		$ch = curl_init($this->webhookUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if (!empty($curlError)) {
			error_log('GoogleChatNotifier cURL Error: ' . $curlError);

			return false;
		}

		return $httpCode === 200;
	}

	/**
	 * 格式化訊息
	 *
	 * @param array $analysis Log 分析結果
	 *
	 * @return string 格式化後的訊息
	 */
	public function formatMessage($analysis)
	{
		$isSuccess = isset($analysis['success']) ? $analysis['success'] : false;
		$projectName = !empty($analysis['projectName'])
			? $analysis['projectName']
			: 'SendDelivery';
		$icon = $isSuccess ? '✅' : '❌';
		$status = $isSuccess ? '執行成功' : '執行失敗';
		$timestamp = date('Y-m-d H:i:s');

		$sections = [];

		// 1. 基本資訊區塊
		$widgets = [];

		// 處理筆數與銷售人員
		if ($isSuccess) {
			$recordsProcessed = isset($analysis['recordsProcessed']) ? $analysis['recordsProcessed'] : 0;
			$infoText = "📊 <b>處理筆數</b>：{$recordsProcessed} 筆";

			if (!empty($analysis['personIds'])) {
				$infoText .= "\n👤 <b>銷售人員</b>：" . implode(', ', $analysis['personIds']);
			}
			$widgets[] = ['textParagraph' => ['text' => $infoText]];
		} else {
			// 錯誤類型與提示
			$errorType = isset($analysis['errorType']) ? $analysis['errorType'] : '未知錯誤';
			$errorHint = isset($analysis['errorHint']) ? $analysis['errorHint'] : '';

			$errorText = "⚠️ <b>錯誤類型</b>：{$errorType}";
			if (!empty($errorHint)) {
				$errorText .= "\n💡 <b>可能原因</b>：{$errorHint}";
			}
			$errorText .= "\n<font color=\"#ff0000\">請儘速檢查處理！</font>";
			$widgets[] = ['textParagraph' => ['text' => $errorText]];
		}

		$sections[] = [
			'widgets' => $widgets,
		];

		// 2. 批次處理統計區塊 (ANE072)
		if (isset($analysis['successCount']) || isset($analysis['failureCount'])) {
			$statsWidgets = [];
			$statsText = "📊 <b>處理統計</b>：\n";
			$statsText .= "　　總筆數：" . $analysis['recordsProcessed'] . " 筆\n";
			$statsText .= "　　成功：" . $analysis['successCount'] . " 筆\n";
			$statsText .= "　　失敗：" . $analysis['failureCount'] . " 筆";
			$statsWidgets[] = ['textParagraph' => ['text' => $statsText]];

			$sections[] = [
				'header'  => '批次統計',
				'widgets' => $statsWidgets,
			];

			// 3. 失敗原因統計 (折疊區塊)
			if (!empty($analysis['errorBreakdown'])) {
				$errorBreakdown = $analysis['errorBreakdown'];
				arsort($errorBreakdown);

				$breakdownWidgets = [];
				foreach ($errorBreakdown as $errorMsg => $count) {
					// 避免文字過長導致顯示問題
					$displayMsg = $this->strLen($errorMsg) > 100
						? $this->subStr($errorMsg, 0, 100) . '...'
						: $errorMsg;
					$breakdownWidgets[] = [
						'textParagraph' => [
							'text' => "• {$displayMsg}：<b>{$count}</b> 筆",
						],
					];
				}

				$sections[] = [
					'header'                    => '⚠️ 失敗原因統計',
					'collapsible'               => true,
					'uncollapsibleWidgetsCount' => 0,
					'widgets'                   => $breakdownWidgets,
				];
			}
		}

		// 4. Log 位置區塊
		$logPath = isset($analysis['logPath']) ? $analysis['logPath'] : '';
		if (!empty($logPath)) {
			$sections[] = [
				'widgets' => [
					[
						'textParagraph' => [
							'text' => "📂 <b>Log 位置</b>：{$logPath}",
						],
					],
				],
			];
		}

		// 建立完整 Card 結構
		$cardStructure = [
			'cardsV2' => [
				[
					'cardId' => uniqid(),
					'card'   => [
						'header'   => [
							'title'    => "{$icon} [{$projectName}] {$status}",
							'subtitle' => "📅 執行時間：{$timestamp}",
						],
						'sections' => $sections,
					],
				],
			],
		];

		// 回傳 JSON 字串 (不轉義 Unicode，確保中文顯示正常)
		return json_encode($cardStructure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * 取得 Webhook URL
	 *
	 * @return string
	 */
	public function getWebhookUrl()
	{
		return $this->webhookUrl;
	}

	/**
	 * 設定 Webhook URL
	 *
	 * @param string $webhookUrl
	 *
	 * @return void
	 */
	public function setWebhookUrl($webhookUrl)
	{
		$this->webhookUrl = $webhookUrl;
	}

	/**
	 * 取得字串長度 (相容模式)
	 *
	 * @param string $str
	 *
	 * @return int
	 */
	private function strLen($str)
	{
		if (function_exists('mb_strlen')) {
			return mb_strlen($str, 'UTF-8');
		}

		return strlen($str);
	}

	/**
	 * 取得子字串 (相容模式)
	 *
	 * @param string $str
	 * @param int    $start
	 * @param int    $length
	 *
	 * @return string
	 */
	private function subStr($str, $start, $length)
	{
		if (function_exists('mb_substr')) {
			return mb_substr($str, $start, $length, 'UTF-8');
		}

		return substr($str, $start, $length);
	}
}
