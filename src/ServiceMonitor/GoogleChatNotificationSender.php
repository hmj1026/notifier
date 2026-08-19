<?php
/**
 * NotificationSenderInterface 的 Google Chat 正式實作
 *
 * 組合 GoogleChatServiceMessageFormatter（格式化）與既有、不變的
 * Notifier\Notifier\GoogleChatNotifier::send()（傳送），兩者之間
 * 不新增另一套 Webhook 傳送邏輯。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

use Notifier\Notifier\GoogleChatNotifier;

class GoogleChatNotificationSender implements NotificationSenderInterface
{
	/**
	 * @var GoogleChatServiceMessageFormatter
	 */
	private $formatter;

	/**
	 * @var GoogleChatNotifier
	 */
	private $notifier;

	/**
	 * @param GoogleChatServiceMessageFormatter $formatter
	 * @param GoogleChatNotifier                $notifier
	 */
	public function __construct(GoogleChatServiceMessageFormatter $formatter, GoogleChatNotifier $notifier)
	{
		$this->formatter = $formatter;
		$this->notifier = $notifier;
	}

	/**
	 * {@inheritdoc}
	 */
	public function send(array $notification, array $checkResult, $hostName)
	{
		$message = $this->formatter->formatIncidentNotification($notification, $checkResult, $hostName);
		$isSuccess = ($notification['type'] === 'recovery');

		return $this->notifier->send($message, $isSuccess);
	}
}
