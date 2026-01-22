<?php
/**
 * 通知器抽象基底類別
 *
 * 定義通知器的標準介面，所有通知器實作必須繼承此類別。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier
 */

namespace Notifier;

abstract class Notifier
{
	/**
	 * 是否啟用通知
	 *
	 * @var bool
	 */
	protected $enabled;

	/**
	 * 建構子
	 *
	 * @param bool $enabled 是否啟用通知
	 */
	public function __construct($enabled = true)
	{
		$this->enabled = $enabled;
	}

	/**
	 * 發送通知
	 *
	 * @param string $message   訊息內容
	 * @param bool   $isSuccess 是否成功
	 *
	 * @return bool 發送結果
	 */
	abstract public function send($message, $isSuccess);

	/**
	 * 格式化訊息
	 *
	 * @param array $analysis Log 分析結果
	 *
	 * @return string 格式化後的訊息
	 */
	abstract public function formatMessage($analysis);

	/**
	 * 檢查是否啟用
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		return $this->enabled;
	}

	/**
	 * 設定啟用狀態
	 *
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public function setEnabled($enabled)
	{
		$this->enabled = $enabled;
	}
}
