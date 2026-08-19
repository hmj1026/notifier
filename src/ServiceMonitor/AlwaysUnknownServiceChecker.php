<?php
/**
 * 永遠回傳 unknown 的降級 checker
 *
 * ServiceCheckerFactory 在單一服務設定錯誤時，用這個 checker 取代
 * 原本應該建立的 checker，讓該服務的檢查結果穩定為 unknown（而非
 * 讓整批解析中止），並把設定錯誤原因保留在 diagnostic 欄位。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class AlwaysUnknownServiceChecker extends AbstractServiceChecker
{
	/**
	 * @var string
	 */
	private $reason;

	/**
	 * @param string         $serviceKey
	 * @param string         $label
	 * @param ClockInterface $clock
	 * @param string         $reason 設定錯誤原因
	 */
	public function __construct($serviceKey, $label, ClockInterface $clock, $reason)
	{
		parent::__construct($serviceKey, $label, $clock);
		$this->reason = $reason;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		return $this->buildResult('unknown', 'unavailable', 0, $this->reason, [], ['configError' => $this->reason]);
	}
}
