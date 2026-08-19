<?php
/**
 * 選填診斷資訊提供者介面
 *
 * 讓個別 checker 選擇性附加額外診斷資訊到自身的 diagnostic 欄位。
 * 此類診斷資訊不得被實作為獨立的 ServiceCheckerInterface，
 * 也不得單獨影響其所屬服務以外的健康判定。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface DiagnosticProviderInterface
{
	/**
	 * 取得額外診斷資訊
	 *
	 * @return array
	 */
	public function diagnose();
}
