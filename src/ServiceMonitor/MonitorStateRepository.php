<?php
/**
 * 監控狀態儲存庫
 *
 * 以 state.json 保存每個服務的狀態機資料。寫入採「暫存檔 + rename」
 * 的原子寫入，避免寫到一半被中斷造成半殘檔案；讀取到無法解析的
 * 內容時，先將損壞檔案備份保留證據，再以安全的空狀態繼續執行，
 * 不直接覆蓋遺失原始內容。
 * 這是核心元件，不得對 serviceKey 做任何分支判斷。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class MonitorStateRepository
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
	 * @param string         $storagePath 監控資料儲存根目錄
	 * @param ClockInterface $clock
	 */
	public function __construct($storagePath, ClockInterface $clock)
	{
		$this->storagePath = rtrim($storagePath, '/\\');
		$this->clock = $clock;
	}

	/**
	 * 讀取目前狀態（以 serviceKey 為 key 的陣列）
	 *
	 * @return array
	 */
	public function load()
	{
		$path = $this->statePath();

		if (!file_exists($path)) {
			return [];
		}

		$content = file_get_contents($path);

		if ($content === false || trim($content) === '') {
			return [];
		}

		$decoded = json_decode($content, true);

		if (!is_array($decoded)) {
			$this->backupCorruptFile($path);

			return [];
		}

		return $decoded;
	}

	/**
	 * 原子寫入整份狀態
	 *
	 * @param array $state
	 *
	 * @return void
	 *
	 * @throws \RuntimeException 寫入或 rename 失敗時
	 */
	public function save(array $state)
	{
		$this->ensureDirectoryExists();

		$path = $this->statePath();
		$tempPath = $path . '.tmp-' . uniqid('', true);

		$json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

		if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
			@unlink($tempPath);

			throw new \RuntimeException("無法寫入暫存狀態檔：{$tempPath}");
		}

		if (!rename($tempPath, $path)) {
			@unlink($tempPath);

			throw new \RuntimeException("無法以原子方式更新狀態檔：{$path}");
		}
	}

	/**
	 * 將無法解析的損壞檔案備份保留，不直接覆蓋遺失內容
	 *
	 * @param string $path
	 *
	 * @return void
	 */
	private function backupCorruptFile($path)
	{
		$backupPath = $path . '.corrupt-' . $this->clock->now()->format('YmdHis');
		@rename($path, $backupPath);
	}

	/**
	 * @return string
	 */
	private function statePath()
	{
		return $this->storagePath . '/state.json';
	}

	/**
	 * @return void
	 */
	private function ensureDirectoryExists()
	{
		if (!is_dir($this->storagePath)) {
			mkdir($this->storagePath, 0755, true);
		}
	}
}
