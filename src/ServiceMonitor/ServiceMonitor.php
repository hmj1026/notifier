<?php
/**
 * 服務監控 orchestrator
 *
 * 依序執行 ServiceCheckerFactory::createAll() 產生的所有 checker，
 * 強制 MONITOR_CHECK_TOTAL_BUDGET_SECONDS 整批時間預算（預算耗盡後，
 * 尚未開始檢查的服務直接標記為 unknown，不再執行），單一服務的
 * checker 拋出例外時降級為該服務的 unknown 結果，不中止整批檢查。
 * 每個結果都會寫入日誌、推進 IncidentManager 狀態機，並在需要通知
 * 且確認發送成功時才更新該服務的 lastAlertAt/failureAlertSent。
 *
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class ServiceMonitor
{
	const DEFAULT_TOTAL_BUDGET_SECONDS = 240;

	/**
	 * @var array<string, ServiceCheckerInterface>
	 */
	private $checkers;

	/**
	 * @var MonitorStateRepository
	 */
	private $stateRepository;

	/**
	 * @var MonitorLogRepository
	 */
	private $logRepository;

	/**
	 * @var IncidentManager
	 */
	private $incidentManager;

	/**
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * @var string
	 */
	private $hostName;

	/**
	 * @var int|float
	 */
	private $totalBudgetSeconds;

	/**
	 * @var NotificationSenderInterface|null
	 */
	private $notificationSender;

	/**
	 * @param array<string, ServiceCheckerInterface> $checkers
	 * @param MonitorStateRepository                 $stateRepository
	 * @param MonitorLogRepository                   $logRepository
	 * @param IncidentManager                        $incidentManager
	 * @param ClockInterface                          $clock
	 * @param string                                  $hostName
	 * @param int|float                               $totalBudgetSeconds 整批檢查總時間預算（秒，呼叫端負責從設定值轉為 int）
	 * @param NotificationSenderInterface|null         $notificationSender null 時不發送任何通知（等同 dry-run 的通知行為）
	 */
	public function __construct(
		array $checkers,
		MonitorStateRepository $stateRepository,
		MonitorLogRepository $logRepository,
		IncidentManager $incidentManager,
		ClockInterface $clock,
		$hostName,
		$totalBudgetSeconds = self::DEFAULT_TOTAL_BUDGET_SECONDS,
		NotificationSenderInterface $notificationSender = null
	) {
		$this->checkers = $checkers;
		$this->stateRepository = $stateRepository;
		$this->logRepository = $logRepository;
		$this->incidentManager = $incidentManager;
		$this->clock = $clock;
		$this->hostName = $hostName;
		$this->totalBudgetSeconds = $totalBudgetSeconds;
		$this->notificationSender = $notificationSender;
	}

	/**
	 * 執行一輪所有服務的健康檢查
	 *
	 * @param bool $persist 是否寫入 state.json 與當日 log（dry-run 時傳 false）
	 *
	 * @return array ['results' => array<string, array>, 'anyUnhealthy' => bool]
	 */
	public function runCheck($persist = true)
	{
		$previousStates = $this->stateRepository->load();
		$newStates = $previousStates;
		$results = [];
		$anyUnhealthy = false;

		$startedAt = microtime(true);
		$budgetExhausted = false;

		foreach ($this->checkers as $serviceKey => $checker) {
			if ($budgetExhausted || (microtime(true) - $startedAt) >= $this->totalBudgetSeconds) {
				$budgetExhausted = true;
				$result = $this->syntheticResult($serviceKey, '整批檢查時間預算已耗盡，本次跳過');
			} else {
				$result = $this->safeCheck($serviceKey, $checker);
			}

			$results[$serviceKey] = $result;

			if ($result['status'] === 'unhealthy') {
				$anyUnhealthy = true;
			}

			if ($persist) {
				$this->logRepository->append($result);
			}

			$previousState = isset($previousStates[$serviceKey]) ? $previousStates[$serviceKey] : null;
			$evaluation = $this->incidentManager->evaluate($serviceKey, $result, $previousState);
			$state = $evaluation['state'];

			if ($evaluation['notification'] !== null && $this->notificationSender !== null) {
				$sent = $this->notificationSender->send($evaluation['notification'], $result, $this->hostName);

				if ($sent) {
					$state = $this->incidentManager->markNotificationSent($state);
				}
			}

			$newStates[$serviceKey] = $state;
		}

		if ($persist) {
			$this->stateRepository->save($newStates);
		}

		return ['results' => $results, 'anyUnhealthy' => $anyUnhealthy];
	}

	/**
	 * 執行單一 checker，例外時降級為該服務的 unknown 結果，不中止其他服務的檢查
	 *
	 * @param string                 $serviceKey
	 * @param ServiceCheckerInterface $checker
	 *
	 * @return array
	 */
	private function safeCheck($serviceKey, ServiceCheckerInterface $checker)
	{
		try {
			return $checker->check();
		} catch (\Exception $e) {
			return $this->syntheticResult($serviceKey, 'checker 執行時發生例外：' . $e->getMessage());
		}
	}

	/**
	 * 組出不需要實際執行 checker 的合成 unknown 結果（預算耗盡/例外情況）
	 *
	 * @param string $serviceKey
	 * @param string $message
	 *
	 * @return array
	 */
	private function syntheticResult($serviceKey, $message)
	{
		return [
			'serviceKey' => $serviceKey,
			'label'      => $serviceKey,
			'status'     => 'unknown',
			'checkedAt'  => $this->clock->now()->format(\DateTime::ATOM),
			'latencyMs'  => 0,
			'method'     => 'unavailable',
			'message'    => $message,
			'diagnostic' => [],
			'details'    => [],
		];
	}
}
