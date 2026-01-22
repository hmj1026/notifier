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

		$payload = json_encode(['text' => $message]);

		$ch = curl_init($this->webhookUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
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
		$projectName = isset($analysis['projectName']) && !empty($analysis['projectName'])
			? $analysis['projectName']
			: 'SendDelivery';
		$icon = $isSuccess ? '✅' : '❌';
		$status = $isSuccess ? '執行成功' : '執行失敗';

		$msg = $icon . ' [' . $projectName . '] ' . $status . "\n\n";
		$msg .= '📅 執行時間：' . date('Y-m-d H:i:s') . "\n";

		if ($isSuccess) {
			$recordsProcessed = isset($analysis['recordsProcessed']) ? $analysis['recordsProcessed'] : 0;
			$msg .= '📊 處理筆數：' . $recordsProcessed . " 筆\n";

			if (!empty($analysis['personIds'])) {
				$msg .= '👤 銷售人員：' . implode(', ', $analysis['personIds']) . "\n";
			}
		} else {
			$errorType = isset($analysis['errorType']) ? $analysis['errorType'] : '未知錯誤';
			$errorHint = isset($analysis['errorHint']) ? $analysis['errorHint'] : '';

			$msg .= '⚠️ 錯誤類型：' . $errorType . "\n";
			if (!empty($errorHint)) {
				$msg .= '💡 可能原因：' . $errorHint . "\n";
			}
			$msg .= "\n請儘速檢查處理！";
		}
		
		// ANE072 批次統計資訊（如果有）
		if (isset($analysis['successCount']) || isset($analysis['failureCount'])) {
			$msg .= "\n\n📊 處理統計：\n";
			$msg .= '　　總筆數：' . $analysis['recordsProcessed'] . " 筆\n";
			$msg .= '　　成功：' . $analysis['successCount'] . " 筆\n";
			$msg .= '　　失敗：' . $analysis['failureCount'] . " 筆\n";
			
			// 錯誤訊息統計（折疊顯示）
			if (!empty($analysis['errorBreakdown'])) {
				$msg .= "\n⚠️ 失敗原因統計：\n";
				
				// 按照筆數排序（從多到少）
				$errorBreakdown = $analysis['errorBreakdown'];
				arsort($errorBreakdown);
				
				foreach ($errorBreakdown as $errorMsg => $count) {
					$msg .= '　　• ' . $errorMsg . '：' . $count . " 筆\n";
				}
			}
		}

		$logPath = isset($analysis['logPath']) ? $analysis['logPath'] : '';
		$msg .= "\n📂 Log 位置：" . $logPath;

		return $msg;
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
}
