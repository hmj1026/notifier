<?php
/**
 * Shell 命令執行器（production adapter）
 *
 * CommandRunnerInterface 的正式實作。執行前驗證 binary 是否存在且可執行，
 * 逐一 escape 參數（不手動拼接任意輸入進 shell 字串），輸出長度受上限保護，
 * 逾時時終止子行程並回傳明確失敗結果，不讓排程永久卡住。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class ShellCommandRunner implements CommandRunnerInterface
{
	const DEFAULT_TIMEOUT_SECONDS = 10;
	const DEFAULT_MAX_OUTPUT_BYTES = 4096;
	const POLL_INTERVAL_MICROSECONDS = 50000;

	/**
	 * {@inheritdoc}
	 */
	public function run($binaryPath, array $args = [], array $options = [])
	{
		$timeoutSeconds = isset($options['timeoutSeconds']) ? (int)$options['timeoutSeconds'] : self::DEFAULT_TIMEOUT_SECONDS;
		$maxOutputBytes = isset($options['maxOutputBytes']) ? (int)$options['maxOutputBytes'] : self::DEFAULT_MAX_OUTPUT_BYTES;

		$startedAt = microtime(true);

		if (empty($binaryPath) || !file_exists($binaryPath) || !is_executable($binaryPath)) {
			return $this->failure("binary 不存在或不可執行：{$binaryPath}", $startedAt);
		}

		$commandParts = [escapeshellcmd($binaryPath)];
		foreach ($args as $arg) {
			$commandParts[] = escapeshellarg($arg);
		}
		$command = implode(' ', $commandParts);

		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($command, $descriptorSpec, $pipes);

		if (!is_resource($process)) {
			return $this->failure("無法啟動命令：{$binaryPath}", $startedAt);
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$timedOut = false;
		$deadline = microtime(true) + $timeoutSeconds;
		$status = proc_get_status($process);

		while (true) {
			$status = proc_get_status($process);

			$stdout .= $this->readAvailable($pipes[1], $maxOutputBytes - strlen($stdout));
			$stderr .= $this->readAvailable($pipes[2], $maxOutputBytes - strlen($stderr));

			if (!$status['running']) {
				break;
			}

			if (microtime(true) >= $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				usleep(self::POLL_INTERVAL_MICROSECONDS);
				$status = proc_get_status($process);
				break;
			}

			usleep(self::POLL_INTERVAL_MICROSECONDS);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

		return [
			'exitCode'  => $timedOut ? null : $status['exitcode'],
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'timedOut'  => $timedOut,
			'error'     => $timedOut ? '命令執行逾時' : null,
			'latencyMs' => $latencyMs,
		];
	}

	/**
	 * 從管線讀取目前可用的輸出，不超過剩餘可用位元組數
	 *
	 * @param resource $pipe
	 * @param int      $maxBytes
	 *
	 * @return string
	 */
	private function readAvailable($pipe, $maxBytes)
	{
		if ($maxBytes <= 0) {
			return '';
		}

		$chunk = fread($pipe, $maxBytes);

		return $chunk === false ? '' : $chunk;
	}

	/**
	 * 組出無法啟動命令時的失敗結果
	 *
	 * @param string $error
	 * @param float  $startedAt
	 *
	 * @return array
	 */
	private function failure($error, $startedAt)
	{
		return [
			'exitCode'  => null,
			'stdout'    => '',
			'stderr'    => '',
			'timedOut'  => false,
			'error'     => $error,
			'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
		];
	}
}
