<?php
/**
 * LAMPP `lampp status` 診斷提供者
 *
 * 執行 `{LAMPP_ROOT}/lampp status` 並盡力解析 Apache/MySQL/ProFTPD
 * 的運作狀態，附加到呼叫端 checker 的 diagnostic 欄位。這是
 * LAMPP 專屬的葉節點外掛，只用於組合進 ApacheServiceChecker 等
 * checker 內部，本身不是 ServiceCheckerInterface 實作，也不會
 * 單獨驅動任何健康判定。無法解析的輸出依 strict 設定決定記錄
 * 層級（warning 或 error），但兩者都只影響 diagnostic 內容，
 * 不影響呼叫端 checker 的 status。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class LamppStatusDiagnosticProvider implements DiagnosticProviderInterface
{
	const RAW_OUTPUT_MAX_LENGTH = 2048;

	/**
	 * @var string
	 */
	private $lamppRoot;

	/**
	 * @var CommandRunnerInterface
	 */
	private $commandRunner;

	/**
	 * @var bool
	 */
	private $strict;

	/**
	 * @param string                 $lamppRoot
	 * @param CommandRunnerInterface $commandRunner
	 * @param bool                   $strict LAMPP_STATUS_STRICT：無法解析時是否記為 error（預設 false，僅記為 warning）
	 */
	public function __construct($lamppRoot, CommandRunnerInterface $commandRunner, $strict = false)
	{
		$this->lamppRoot = rtrim($lamppRoot, '/\\');
		$this->commandRunner = $commandRunner;
		$this->strict = (bool)$strict;
	}

	/**
	 * {@inheritdoc}
	 */
	public function diagnose()
	{
		$binaryPath = $this->lamppRoot . '/lampp';

		$response = $this->commandRunner->run($binaryPath, ['status'], ['timeoutSeconds' => 10]);

		if (!empty($response['timedOut']) || $response['exitCode'] === null) {
			return [
				'source'    => 'lampp status',
				'parseable' => false,
				'level'     => $this->strict ? 'error' : 'warning',
				'message'   => 'lampp status 執行失敗：' . (!empty($response['error']) ? $response['error'] : '未知錯誤'),
			];
		}

		$rawOutput = substr($response['stdout'], 0, self::RAW_OUTPUT_MAX_LENGTH);
		$parsed = $this->parseStatusOutput($rawOutput);

		if (empty($parsed)) {
			return [
				'source'    => 'lampp status',
				'raw'       => $rawOutput,
				'parseable' => false,
				'level'     => $this->strict ? 'error' : 'warning',
				'message'   => 'lampp status 輸出無法解析（版本差異或格式變更）',
			];
		}

		return [
			'source'    => 'lampp status',
			'parsed'    => $parsed,
			'parseable' => true,
			'level'     => 'info',
		];
	}

	/**
	 * 盡力解析 `lampp status` 每一行的「XXX is running/not running」格式
	 *
	 * @param string $output
	 *
	 * @return array 例如 ['apache' => 'running', 'mysql' => 'not_running']
	 */
	private function parseStatusOutput($output)
	{
		$parsed = [];
		$lines = explode("\n", $output);

		foreach ($lines as $line) {
			$line = trim($line);

			if (preg_match('/^(Apache|MySQL|ProFTPD)\s+is\s+(running|not running)/i', $line, $matches)) {
				$service = strtolower($matches[1]);
				$parsed[$service] = (stripos($matches[2], 'not') === false) ? 'running' : 'not_running';
			}
		}

		return $parsed;
	}
}
