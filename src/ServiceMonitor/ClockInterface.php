<?php
/**
 * 時鐘介面（true-external port）
 *
 * 讓需要「目前時間」的邏輯（狀態時間戳記、日報計算等）可被單元測試，
 * 不需要依賴系統實際時間。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface ClockInterface
{
	/**
	 * 取得目前時間
	 *
	 * @return \DateTime
	 */
	public function now();
}
