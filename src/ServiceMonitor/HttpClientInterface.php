<?php
/**
 * HTTP 客戶端介面（true-external port）
 *
 * 所有實際發送 HTTP 請求的動作都必須透過此介面注入，
 * 讓 checker 的狀態碼/內容比對邏輯可以在不觸碰網路的情況下被單元測試。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface HttpClientInterface
{
	/**
	 * 發送 GET 請求
	 *
	 * $options 支援：
	 *   connectTimeout - int    連線逾時秒數
	 *   requestTimeout - int    請求逾時秒數
	 *   hostHeader     - string 自訂 Host header（選填，支援 VirtualHost）
	 *   verifySsl      - bool   是否驗證 SSL 憑證（預設 true）
	 *
	 * 回傳陣列結構：
	 *   ok         - bool         是否成功取得回應（未逾時、未連線失敗）
	 *   httpStatus - int|null     HTTP 狀態碼（取得回應時才有值）
	 *   body       - string|null 回應內容
	 *   error      - string|null 錯誤說明（取得回應失敗時才有值）
	 *   latencyMs  - int          耗時（毫秒）
	 *
	 * @param string $url     目標 URL
	 * @param array  $options 選項
	 *
	 * @return array
	 */
	public function get($url, array $options = []);
}
