<?php
/**
 * Checker 共用基底
 *
 * 提供所有 ServiceCheckerInterface 實作共用的建構子欄位（serviceKey/label/clock）
 * 與正規化結果陣列組裝邏輯，避免每個 checker 重複拼同一組 9 個欄位。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

abstract class AbstractServiceChecker implements ServiceCheckerInterface
{
	/**
	 * @var string
	 */
	protected $serviceKey;

	/**
	 * @var string
	 */
	protected $label;

	/**
	 * @var ClockInterface
	 */
	protected $clock;

	/**
	 * @param string         $serviceKey
	 * @param string         $label
	 * @param ClockInterface $clock
	 */
	public function __construct($serviceKey, $label, ClockInterface $clock)
	{
		$this->serviceKey = $serviceKey;
		$this->label = !empty($label) ? $label : $serviceKey;
		$this->clock = $clock;
	}

	/**
	 * 組出符合 ServiceCheckerInterface::check() 正規化結構的結果陣列
	 *
	 * @param string $status     healthy / unhealthy / unknown
	 * @param string $method     檢查方式
	 * @param int    $latencyMs
	 * @param string $message
	 * @param array  $details
	 * @param array  $diagnostic
	 *
	 * @return array
	 */
	protected function buildResult($status, $method, $latencyMs, $message, array $details = [], array $diagnostic = [])
	{
		return [
			'serviceKey' => $this->serviceKey,
			'label'      => $this->label,
			'status'     => $status,
			'checkedAt'  => $this->clock->now()->format(\DateTime::ATOM),
			'latencyMs'  => $latencyMs,
			'method'     => $method,
			'message'    => $message,
			'diagnostic' => $diagnostic,
			'details'    => $details,
		];
	}
}
