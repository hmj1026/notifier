<?php
/**
 * ClockInterface 測試替身
 *
 * 可手動設定/推進目前時間，讓狀態機、節流、日報等時間相依邏輯可被單元測試。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\ClockInterface;

class FakeClock implements ClockInterface
{
	/**
	 * @var \DateTime
	 */
	private $current;

	/**
	 * @param \DateTime|null $current 初始時間，預設 2026-01-01 00:00:00
	 */
	public function __construct(\DateTime $current = null)
	{
		$this->current = $current !== null ? $current : new \DateTime('2026-01-01 00:00:00');
	}

	/**
	 * {@inheritdoc}
	 */
	public function now()
	{
		return clone $this->current;
	}

	/**
	 * 設定目前時間
	 *
	 * @param \DateTime $current
	 *
	 * @return void
	 */
	public function setNow(\DateTime $current)
	{
		$this->current = $current;
	}

	/**
	 * 依 DateInterval 格式字串推進目前時間
	 *
	 * @param string $intervalSpec 例如 'PT5M'（5 分鐘）
	 *
	 * @return void
	 */
	public function advance($intervalSpec)
	{
		$this->current->add(new \DateInterval($intervalSpec));
	}
}
