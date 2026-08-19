<?php
/**
 * 頂層監控設定錯誤
 *
 * 僅用於「無法降級為單一服務問題」的頂層設定錯誤，例如
 * MONITOR_SERVICES 本身缺漏或為空。呼叫端（CLI 進入點）應將此例外
 * 對應為明確的設定錯誤（exit code 1），與「檢查完成但服務異常」
 * （exit code 2）區分。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class MonitorConfigurationException extends \RuntimeException
{
}
