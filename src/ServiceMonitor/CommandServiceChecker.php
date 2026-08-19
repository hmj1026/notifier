<?php
/**
 * 通用 command 型別健康檢查
 *
 * 透過注入的 CommandRunnerInterface 執行指定 binary，驗證 exit code
 * 與/或輸出樣式。語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class CommandServiceChecker extends AbstractServiceChecker
{
	const METHOD = 'command';

	/**
	 * 支援的設定鍵：binaryPath、args（陣列）、
	 * expectedExitCode（預設 0）、expectedOutputContains、
	 * timeoutSeconds（預設 10）
	 *
	 * @var array
	 */
	private $config;

	/**
	 * @var CommandRunnerInterface
	 */
	private $commandRunner;

	/**
	 * @param string                 $serviceKey
	 * @param array                  $config
	 * @param CommandRunnerInterface $commandRunner
	 * @param ClockInterface         $clock
	 */
	public function __construct($serviceKey, array $config, CommandRunnerInterface $commandRunner, ClockInterface $clock)
	{
		parent::__construct($serviceKey, isset($config['label']) ? $config['label'] : $serviceKey, $clock);
		$this->config = $config;
		$this->commandRunner = $commandRunner;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		$binaryPath = isset($this->config['binaryPath']) ? $this->config['binaryPath'] : '';

		if (empty($binaryPath)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：未設定 binaryPath', [], ['error' => 'missing binaryPath']);
		}

		$args = isset($this->config['args']) ? $this->config['args'] : [];
		$expectedExitCode = isset($this->config['expectedExitCode']) ? (int)$this->config['expectedExitCode'] : 0;
		$expectedOutputContains = isset($this->config['expectedOutputContains']) ? $this->config['expectedOutputContains'] : '';
		$timeoutSeconds = isset($this->config['timeoutSeconds']) ? (int)$this->config['timeoutSeconds'] : 10;

		$response = $this->commandRunner->run($binaryPath, $args, ['timeoutSeconds' => $timeoutSeconds]);
		$latencyMs = (int)$response['latencyMs'];

		if (!empty($response['timedOut']) || $response['exitCode'] === null) {
			$reason = !empty($response['timedOut']) ? '執行逾時' : (!empty($response['error']) ? $response['error'] : '未知錯誤');

			return $this->buildResult('unknown', self::METHOD, $latencyMs, "命令未取得結果：{$reason}", [], []);
		}

		$exitCode = (int)$response['exitCode'];

		if ($exitCode !== $expectedExitCode) {
			return $this->buildResult(
				'unhealthy',
				self::METHOD,
				$latencyMs,
				"命令 exit code {$exitCode} 不符合預期值 {$expectedExitCode}",
				['exitCode' => $exitCode]
			);
		}

		if (!empty($expectedOutputContains) && strpos($response['stdout'], $expectedOutputContains) === false) {
			return $this->buildResult(
				'unhealthy',
				self::METHOD,
				$latencyMs,
				'命令輸出不包含預期字串',
				['exitCode' => $exitCode]
			);
		}

		return $this->buildResult('healthy', self::METHOD, $latencyMs, '命令健康檢查通過', ['exitCode' => $exitCode]);
	}
}
