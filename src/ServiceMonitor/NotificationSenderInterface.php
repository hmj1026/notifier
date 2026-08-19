<?php
/**
 * 服務監控通知傳送介面
 *
 * 讓 ServiceMonitor（orchestrator）不需要知道通知內容如何格式化、
 * 透過哪個管道傳送；P5 的 GoogleChatServiceMessageFormatter +
 * 既有 GoogleChatNotifier::send() 是這個介面的正式實作。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface NotificationSenderInterface
{
	/**
	 * 發送一則服務監控通知
	 *
	 * @param array  $notification IncidentManager::evaluate() 回傳的通知描述
	 *                              （至少含 type: initial|repeat|recovery、incidentId）
	 * @param array  $checkResult  ServiceCheckerInterface::check() 結果
	 * @param string $hostName     發出通知的主機識別名稱
	 *
	 * @return bool 是否發送成功
	 */
	public function send(array $notification, array $checkResult, $hostName);
}
