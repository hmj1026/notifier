<?php
/**
 * 系統時鐘（production adapter）
 *
 * 回傳目前實際時間，遵循程式啟動時透過 date_default_timezone_set() 設定的時區。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class SystemClock implements ClockInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function now()
	{
		return new \DateTime('now');
	}
}
