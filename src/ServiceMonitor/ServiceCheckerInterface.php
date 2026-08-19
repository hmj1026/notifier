<?php
/**
 * 服務健康檢查介面
 *
 * 所有服務健康檢查實作必須遵循此介面，回傳統一的正規化結果結構。
 * 下游元件（狀態追蹤、儲存、日報、通知格式化）只依賴此結構，
 * 不得依賴任何特定服務的專屬欄位。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface ServiceCheckerInterface
{
	/**
	 * 執行一次健康檢查
	 *
	 * 回傳陣列結構：
	 *   serviceKey - string 服務識別鍵
	 *   label      - string 顯示名稱
	 *   status     - string 三態狀態：healthy / unhealthy / unknown
	 *   checkedAt  - string ISO 8601 時間戳記
	 *   latencyMs  - int    檢查耗時（毫秒）
	 *   method     - string 檢查方式（http / command / mysql ...）
	 *   message    - string 判定說明
	 *   diagnostic - array  診斷資訊（自由格式）
	 *   details    - array  型別專屬詳細資料（自由格式 key/value）
	 *
	 * healthy：checker 成功執行且取得明確的正向判定
	 * unhealthy：checker 成功執行、且從目標取得明確的負向判定
	 * unknown：checker 本身未能取得明確判定（逾時、binary 不存在、設定錯誤等），
	 *          MUST NOT 被下游當作與 unhealthy 等價的服務異常證據
	 *
	 * @return array 正規化檢查結果
	 */
	public function check();
}
