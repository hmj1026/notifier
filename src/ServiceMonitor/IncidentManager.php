<?php
/**
 * 每服務異常/恢復狀態機
 *
 * 依單次檢查結果推進單一服務的狀態機：累計連續異常/連續成功次數、
 * 依 threshold 判定是否開啟/關閉 incident、依節流間隔決定是否需要
 * 重複提醒。unknown 結果一律不驅動任何狀態轉換，只更新
 * lastCheckedAt/lastError。
 *
 * lastAlertAt/failureAlertSent 只能由呼叫端在確認通知發送成功後
 * 透過 markNotificationSent() 更新（見 Notification-Failure Safety
 * 需求）：若發送失敗，這兩個欄位維持不變，下次檢查會依
 * failureAlertSent 仍為 false 而再次嘗試發送初次異常告警。
 *
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class IncidentManager
{
	/**
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * @var int
	 */
	private $failureThreshold;

	/**
	 * @var int
	 */
	private $recoveryThreshold;

	/**
	 * @var int
	 */
	private $repeatAlertMinutes;

	/**
	 * @param ClockInterface $clock
	 * @param int            $failureThreshold   連續異常達此次數才判定為異常（預設 1）
	 * @param int            $recoveryThreshold  連續成功達此次數才判定為恢復（預設 1）
	 * @param int            $repeatAlertMinutes 持續異常期間的重複提醒間隔（分鐘），0 表示停用
	 */
	public function __construct(ClockInterface $clock, $failureThreshold = 1, $recoveryThreshold = 1, $repeatAlertMinutes = 60)
	{
		$this->clock = $clock;
		$this->failureThreshold = max(1, (int)$failureThreshold);
		$this->recoveryThreshold = max(1, (int)$recoveryThreshold);
		$this->repeatAlertMinutes = (int)$repeatAlertMinutes;
	}

	/**
	 * 依單次檢查結果推進單一服務的狀態機
	 *
	 * @param string     $serviceKey
	 * @param array      $checkResult   ServiceCheckerInterface::check() 的結果
	 * @param array|null $previousState 該服務先前的狀態記錄，不存在時為 null
	 *
	 * @return array ['state' => array 新狀態（未套用通知確認欄位）, 'notification' => array|null 需要發送的通知描述]
	 */
	public function evaluate($serviceKey, array $checkResult, array $previousState = null)
	{
		$now = $this->clock->now()->format(\DateTime::ATOM);
		$status = $checkResult['status'];

		$state = $previousState !== null ? $previousState : $this->initialState();
		$state['lastCheckedAt'] = $now;

		if ($status === 'unknown') {
			$state['lastError'] = $checkResult['message'];

			return ['state' => $state, 'notification' => null];
		}

		$isEstablishingBaseline = ($state['currentStatus'] === null);
		$state['lastError'] = ($status === 'unhealthy') ? $checkResult['message'] : null;

		if ($status === 'healthy') {
			$state['lastSuccessAt'] = $now;
			$state['consecutiveSuccesses']++;
			$state['consecutiveFailures'] = 0;
		} else {
			$state['lastFailureAt'] = $now;
			$state['consecutiveFailures']++;
			$state['consecutiveSuccesses'] = 0;

			if ($state['consecutiveFailures'] === 1) {
				$state['firstFailureAt'] = $now;
			}
		}

		$notification = null;

		if ($state['currentStatus'] === 'healthy' || $isEstablishingBaseline) {
			if ($status === 'unhealthy' && $state['consecutiveFailures'] >= $this->failureThreshold) {
				$state['previousStatus'] = $state['currentStatus'];
				$state['currentStatus'] = 'unhealthy';
				$state['statusChangedAt'] = $now;
				$state['currentIncidentId'] = $serviceKey . '-' . $this->clock->now()->format('YmdHis');
				$state['failureAlertSent'] = false;

				$notification = ['type' => 'initial', 'incidentId' => $state['currentIncidentId']];
			} elseif ($isEstablishingBaseline && $status === 'healthy') {
				// 初次（或尚未建立基準線期間）健康：僅建立狀態，不發送通知
				$state['currentStatus'] = 'healthy';
			}
			// 其餘情況（尚未達 failure threshold 的初次異常）：currentStatus 保持 null，繼續累積
		} elseif ($state['currentStatus'] === 'unhealthy') {
			if (!$state['failureAlertSent']) {
				// 初次異常告警尚未確認發送成功，優先重試（見 Notification-Failure Safety）
				$notification = ['type' => 'initial', 'incidentId' => $state['currentIncidentId']];
			} elseif ($status === 'healthy' && $state['consecutiveSuccesses'] >= $this->recoveryThreshold) {
				$notification = [
					'type'           => 'recovery',
					'incidentId'     => $state['currentIncidentId'],
					'firstFailureAt' => $state['firstFailureAt'],
					'recoveredAt'    => $now,
				];

				$state['previousStatus'] = 'unhealthy';
				$state['currentStatus'] = 'healthy';
				$state['statusChangedAt'] = $now;
				$state['currentIncidentId'] = null;
				$state['firstFailureAt'] = null;
				$state['failureAlertSent'] = false;
			} elseif ($status === 'unhealthy' && $this->repeatAlertMinutes > 0) {
				if ($this->isRepeatAlertDue($state['lastAlertAt'])) {
					$notification = ['type' => 'repeat', 'incidentId' => $state['currentIncidentId']];
				}
			}
		}

		return ['state' => $state, 'notification' => $notification];
	}

	/**
	 * 通知確認發送成功後呼叫，更新 lastAlertAt/failureAlertSent
	 *
	 * @param array $state
	 *
	 * @return array
	 */
	public function markNotificationSent(array $state)
	{
		$state['lastAlertAt'] = $this->clock->now()->format(\DateTime::ATOM);
		$state['failureAlertSent'] = true;

		return $state;
	}

	/**
	 * @param string|null $lastAlertAt
	 *
	 * @return bool
	 */
	private function isRepeatAlertDue($lastAlertAt)
	{
		if ($lastAlertAt === null) {
			return true;
		}

		$elapsedMinutes = ($this->clock->now()->getTimestamp() - strtotime($lastAlertAt)) / 60;

		return $elapsedMinutes >= $this->repeatAlertMinutes;
	}

	/**
	 * @return array
	 */
	private function initialState()
	{
		return [
			'currentStatus'        => null,
			'previousStatus'       => null,
			'lastCheckedAt'        => null,
			'lastSuccessAt'        => null,
			'lastFailureAt'        => null,
			'statusChangedAt'      => null,
			'consecutiveFailures'  => 0,
			'consecutiveSuccesses' => 0,
			'firstFailureAt'       => null,
			'lastAlertAt'          => null,
			'failureAlertSent'     => false,
			'currentIncidentId'    => null,
			'lastError'            => null,
		];
	}
}
