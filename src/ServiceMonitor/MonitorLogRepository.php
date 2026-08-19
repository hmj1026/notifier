<?php
/**
 * 監控紀錄儲存庫
 *
 * 每次檢查結果以一行 JSON 附加寫入當日 JSONL 檔案（storage/logs/YYYY-MM-DD.jsonl），
 * 寫入時使用 flock 避免併發寫入損壞。依 MONITOR_RETENTION_DAYS 清除到期資料。
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class MonitorLogRepository
{
	/**
	 * @var string
	 */
	private $storagePath;

	/**
	 * @var ClockInterface
	 */
	private $clock;

	/**
	 * @var int
	 */
	private $retentionDays;

	/**
	 * @param string         $storagePath   監控資料儲存根目錄
	 * @param ClockInterface $clock
	 * @param int            $retentionDays 保留天數，<= 0 代表不清除
	 */
	public function __construct($storagePath, ClockInterface $clock, $retentionDays = 30)
	{
		$this->storagePath = rtrim($storagePath, '/\\');
		$this->clock = $clock;
		$this->retentionDays = (int)$retentionDays;
	}

	/**
	 * 附加寫入一筆檢查結果到當日 JSONL 檔案
	 *
	 * @param array $record
	 *
	 * @return void
	 *
	 * @throws \RuntimeException 無法開啟日誌檔案時
	 */
	public function append(array $record)
	{
		$this->ensureLogDirectoryExists();

		$date = $this->clock->now()->format('Y-m-d');
		$path = $this->logPathForDate($date);
		$line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

		$handle = fopen($path, 'ab');

		if ($handle === false) {
			throw new \RuntimeException("無法開啟日誌檔案：{$path}");
		}

		if (flock($handle, LOCK_EX)) {
			fwrite($handle, $line);
			fflush($handle);
			flock($handle, LOCK_UN);
		}

		fclose($handle);
	}

	/**
	 * 讀取指定日期的所有檢查紀錄
	 *
	 * @param string $date YYYY-MM-DD
	 *
	 * @return array
	 */
	public function readForDate($date)
	{
		$path = $this->logPathForDate($date);

		if (!file_exists($path)) {
			return [];
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$records = [];

		foreach ($lines as $line) {
			$decoded = json_decode($line, true);

			if (is_array($decoded)) {
				$records[] = $decoded;
			}
		}

		return $records;
	}

	/**
	 * 清除超過保留天數的 JSONL 檔案
	 *
	 * @return void
	 */
	public function purgeExpired()
	{
		if ($this->retentionDays <= 0) {
			return;
		}

		$logDir = $this->logDirectory();

		if (!is_dir($logDir)) {
			return;
		}

		$cutoff = $this->clock->now();
		$cutoff->modify("-{$this->retentionDays} days");
		$cutoffDate = $cutoff->format('Y-m-d');

		$files = glob($logDir . '/*.jsonl');

		if ($files === false) {
			return;
		}

		foreach ($files as $file) {
			$fileDate = basename($file, '.jsonl');

			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fileDate) && $fileDate < $cutoffDate) {
				@unlink($file);
			}
		}
	}

	/**
	 * @param string $date
	 *
	 * @return string
	 */
	private function logPathForDate($date)
	{
		return $this->logDirectory() . '/' . $date . '.jsonl';
	}

	/**
	 * @return string
	 */
	private function logDirectory()
	{
		return $this->storagePath . '/logs';
	}

	/**
	 * @return void
	 */
	private function ensureLogDirectoryExists()
	{
		if (!is_dir($this->logDirectory())) {
			mkdir($this->logDirectory(), 0755, true);
		}
	}
}
