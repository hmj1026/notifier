<?php
/**
 * 每日監控日報產生器
 *
 * 統計前一個完整曆日（由呼叫端傳入日期字串，時區換算交給 CLI 進入點）
 * 每個服務的實際/預期檢查次數、覆蓋率、成功/失敗次數、可用率（估算值，
 * 非精確 SLA）、異常事件數、預估異常時間、最長異常事件、
 * 第一次/最後一次異常時間、日末是否仍未恢復、最常見錯誤摘要。
 * 完全無資料時明確標示警告，不誤判為 100% 正常。
 *
 * 另外管理 reports/YYYY-MM-DD.sent 冪等標記（僅供呼叫端在確認發送
 * 成功後才建立）。
 *
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class DailyReportGenerator
{
	/**
	 * @var MonitorLogRepository
	 */
	private $logRepository;

	/**
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * @var string
	 */
	private $storagePath;

	/**
	 * @var int
	 */
	private $intervalMinutes;

	/**
	 * @param MonitorLogRepository $logRepository
	 * @param ClockInterface       $clock
	 * @param string               $storagePath     監控資料儲存根目錄（用於 sent marker）
	 * @param int                  $intervalMinutes MONITOR_INTERVAL_MINUTES，用於推算預期檢查次數
	 */
	public function __construct(MonitorLogRepository $logRepository, ClockInterface $clock, $storagePath, $intervalMinutes = 5)
	{
		$this->logRepository = $logRepository;
		$this->clock = $clock;
		$this->storagePath = rtrim($storagePath, '/\\');
		$this->intervalMinutes = max(1, (int)$intervalMinutes);
	}

	/**
	 * 產生指定日期（YYYY-MM-DD）的日報資料
	 *
	 * @param string $date
	 *
	 * @return array
	 */
	public function generate($date)
	{
		$records = $this->logRepository->readForDate($date);

		if (empty($records)) {
			return [
				'date'     => $date,
				'hasData'  => false,
				'services' => [],
			];
		}

		$byService = [];

		foreach ($records as $record) {
			$serviceKey = isset($record['serviceKey']) ? $record['serviceKey'] : 'unknown';
			$byService[$serviceKey][] = $record;
		}

		$expectedChecks = $this->expectedCheckCount();
		$services = [];

		foreach ($byService as $serviceKey => $serviceRecords) {
			$services[$serviceKey] = $this->summarizeService($serviceRecords, $expectedChecks);
		}

		return [
			'date'     => $date,
			'hasData'  => true,
			'services' => $services,
		];
	}

	/**
	 * @param string $date
	 *
	 * @return bool
	 */
	public function isAlreadySent($date)
	{
		return file_exists($this->sentMarkerPath($date));
	}

	/**
	 * 標記指定日期的日報已成功發送（呼叫端須在確認 send() 成功後才呼叫）
	 *
	 * @param string $date
	 *
	 * @return void
	 */
	public function markAsSent($date)
	{
		$this->ensureReportsDirectoryExists();
		file_put_contents($this->sentMarkerPath($date), $this->clock->now()->format(\DateTime::ATOM));
	}

	/**
	 * @return int
	 */
	private function expectedCheckCount()
	{
		$minutesInDay = 24 * 60;

		return (int)floor($minutesInDay / $this->intervalMinutes);
	}

	/**
	 * @param array $records
	 * @param int   $expectedChecks
	 *
	 * @return array
	 */
	private function summarizeService(array $records, $expectedChecks)
	{
		$actualChecks = count($records);
		$successCount = 0;
		$failureCount = 0;
		$unknownCount = 0;
		$incidents = [];
		$currentIncidentStart = null;
		$errorMessages = [];
		$firstFailureAt = null;
		$lastFailureAt = null;
		$label = null;

		foreach ($records as $record) {
			$status = isset($record['status']) ? $record['status'] : 'unknown';

			if ($label === null && !empty($record['label'])) {
				$label = $record['label'];
			}

			if ($status === 'healthy') {
				$successCount++;

				if ($currentIncidentStart !== null) {
					$incidents[] = ['start' => $currentIncidentStart, 'end' => $record['checkedAt']];
					$currentIncidentStart = null;
				}

				continue;
			}

			if ($status === 'unhealthy') {
				$failureCount++;

				if ($currentIncidentStart === null) {
					$currentIncidentStart = $record['checkedAt'];
				}

				if (!empty($record['message'])) {
					$errorMessages[] = $record['message'];
				}

				if ($firstFailureAt === null) {
					$firstFailureAt = $record['checkedAt'];
				}

				$lastFailureAt = $record['checkedAt'];

				continue;
			}

			$unknownCount++;
		}

		$stillDown = ($currentIncidentStart !== null);

		if ($stillDown) {
			$incidents[] = ['start' => $currentIncidentStart, 'end' => null];
		}

		list($totalDowntimeSeconds, $longestIncidentSeconds) = $this->summarizeIncidentDurations($incidents);

		$coverage = $expectedChecks > 0 ? round(($actualChecks / $expectedChecks) * 100, 1) : 0.0;
		$availability = $actualChecks > 0 ? round(($successCount / $actualChecks) * 100, 1) : 0.0;

		return [
			'label'                    => $label !== null ? $label : 'unknown',
			'actualChecks'             => $actualChecks,
			'expectedChecks'           => $expectedChecks,
			'coveragePercent'          => $coverage,
			'successCount'             => $successCount,
			'failureCount'             => $failureCount,
			'unknownCount'             => $unknownCount,
			'availabilityPercent'      => $availability,
			'incidentCount'            => count($incidents),
			'estimatedDowntimeSeconds' => $totalDowntimeSeconds,
			'longestIncidentSeconds'   => $longestIncidentSeconds,
			'firstFailureAt'           => $firstFailureAt,
			'lastFailureAt'            => $lastFailureAt,
			'stillDownAtDayEnd'        => $stillDown,
			'topError'                 => $this->mostCommonError($errorMessages),
		];
	}

	/**
	 * @param array $incidents
	 *
	 * @return array [totalDowntimeSeconds, longestIncidentSeconds]
	 */
	private function summarizeIncidentDurations(array $incidents)
	{
		$totalDowntimeSeconds = 0;
		$longestIncidentSeconds = 0;

		foreach ($incidents as $incident) {
			$endTimestamp = $incident['end'] !== null ? strtotime($incident['end']) : $this->clock->now()->getTimestamp();
			$duration = max(0, $endTimestamp - strtotime($incident['start']));
			$totalDowntimeSeconds += $duration;
			$longestIncidentSeconds = max($longestIncidentSeconds, $duration);
		}

		return [$totalDowntimeSeconds, $longestIncidentSeconds];
	}

	/**
	 * @param array $messages
	 *
	 * @return string|null
	 */
	private function mostCommonError(array $messages)
	{
		if (empty($messages)) {
			return null;
		}

		$counts = array_count_values($messages);
		arsort($counts);

		return key($counts);
	}

	/**
	 * @param string $date
	 *
	 * @return string
	 */
	private function sentMarkerPath($date)
	{
		return $this->storagePath . '/reports/' . $date . '.sent';
	}

	/**
	 * @return void
	 */
	private function ensureReportsDirectoryExists()
	{
		$dir = $this->storagePath . '/reports';

		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
	}
}
