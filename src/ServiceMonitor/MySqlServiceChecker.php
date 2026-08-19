<?php
/**
 * 專用 mysql 型別健康檢查
 *
 * 提供 ping 模式（mysqladmin ping exit code）與 query 模式
 * （透過 0600 權限的 defaults-extra-file 執行 SELECT 1）。
 * 兩種模式都複用 CommandRunnerInterface，不另立 MySQL 專屬 port。
 * 密碼不會出現在命令列參數中；defaults-extra-file 權限不安全或
 * 不存在時一律回報設定錯誤（unknown），不嘗試略過直接查詢。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class MySqlServiceChecker extends AbstractServiceChecker
{
	const METHOD = 'mysql';

	/**
	 * 支援的設定鍵：mode（ping|query，預設 ping）、
	 * mysqladminPath、mysqlClientPath、host、port、socket、
	 * connectTimeout（預設 5）、defaultsExtraFile、timeoutSeconds（預設 10）
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
		$mode = isset($this->config['mode']) ? $this->config['mode'] : 'ping';

		if ($mode === 'query') {
			return $this->checkQuery();
		}

		return $this->checkPing();
	}

	/**
	 * ping 模式：呼叫 mysqladmin ping，依 exit code 判定
	 *
	 * @return array
	 */
	private function checkPing()
	{
		$binaryPath = isset($this->config['mysqladminPath']) ? $this->config['mysqladminPath'] : '';

		if (empty($binaryPath)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：未設定 mysqladmin 路徑', [], ['error' => 'missing mysqladminPath']);
		}

		$args = $this->buildConnectionArgs();

		if (!empty($this->config['connectTimeout'])) {
			$args[] = '--connect-timeout=' . (int)$this->config['connectTimeout'];
		}

		if (!empty($this->config['defaultsExtraFile'])) {
			$args[] = '--defaults-extra-file=' . $this->config['defaultsExtraFile'];
		}

		array_unshift($args, 'ping');

		return $this->interpretPingResult($this->runCommand($binaryPath, $args));
	}

	/**
	 * query 模式：透過 defaults-extra-file 執行 SELECT 1，驗證結果確實為 1
	 *
	 * @return array
	 */
	private function checkQuery()
	{
		$defaultsExtraFile = isset($this->config['defaultsExtraFile']) ? $this->config['defaultsExtraFile'] : '';

		if (empty($defaultsExtraFile)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：query 模式需要 defaults-extra-file', [], ['error' => 'missing defaultsExtraFile']);
		}

		if (!file_exists($defaultsExtraFile) || !is_readable($defaultsExtraFile)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：defaults-extra-file 不存在或無法讀取', [], ['error' => 'defaults-extra-file unreadable']);
		}

		$permissions = fileperms($defaultsExtraFile) & 0777;

		if ($permissions !== 0600) {
			return $this->buildResult(
				'unknown',
				self::METHOD,
				0,
				sprintf('設定錯誤：defaults-extra-file 權限不安全（目前 %o，需為 0600）', $permissions),
				[],
				['error' => 'unsafe defaults-extra-file permissions']
			);
		}

		$binaryPath = isset($this->config['mysqlClientPath']) ? $this->config['mysqlClientPath'] : '';

		if (empty($binaryPath)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：未設定 mysql client 路徑', [], ['error' => 'missing mysqlClientPath']);
		}

		$args = $this->buildConnectionArgs();
		array_unshift($args, '--defaults-extra-file=' . $defaultsExtraFile);
		$args[] = '-N';
		$args[] = '-e';
		$args[] = 'SELECT 1';

		return $this->interpretQueryResult($this->runCommand($binaryPath, $args));
	}

	/**
	 * @return array
	 */
	private function buildConnectionArgs()
	{
		$args = [];

		if (!empty($this->config['host'])) {
			$args[] = '--host=' . $this->config['host'];
		}

		if (!empty($this->config['port'])) {
			$args[] = '--port=' . $this->config['port'];
		}

		if (!empty($this->config['socket'])) {
			$args[] = '--socket=' . $this->config['socket'];
		}

		return $args;
	}

	/**
	 * @param string $binaryPath
	 * @param array  $args
	 *
	 * @return array
	 */
	private function runCommand($binaryPath, array $args)
	{
		$timeoutSeconds = isset($this->config['timeoutSeconds']) ? (int)$this->config['timeoutSeconds'] : 10;

		return $this->commandRunner->run($binaryPath, $args, ['timeoutSeconds' => $timeoutSeconds]);
	}

	/**
	 * @param array $response
	 *
	 * @return array
	 */
	private function interpretPingResult(array $response)
	{
		$latencyMs = (int)$response['latencyMs'];

		if (!empty($response['timedOut']) || $response['exitCode'] === null) {
			return $this->buildResult('unknown', self::METHOD, $latencyMs, 'mysqladmin ping 未取得結果：' . $this->describeCommandFailure($response), [], []);
		}

		$exitCode = (int)$response['exitCode'];

		if ($exitCode !== 0) {
			return $this->buildResult('unhealthy', self::METHOD, $latencyMs, 'mysqladmin ping 回傳非 0 exit code', ['exitCode' => $exitCode]);
		}

		return $this->buildResult('healthy', self::METHOD, $latencyMs, 'mysqladmin ping 健康檢查通過', ['exitCode' => 0]);
	}

	/**
	 * @param array $response
	 *
	 * @return array
	 */
	private function interpretQueryResult(array $response)
	{
		$latencyMs = (int)$response['latencyMs'];

		if (!empty($response['timedOut']) || $response['exitCode'] === null) {
			return $this->buildResult('unknown', self::METHOD, $latencyMs, 'SELECT 1 查詢未取得結果：' . $this->describeCommandFailure($response), [], []);
		}

		$exitCode = (int)$response['exitCode'];

		if ($exitCode !== 0) {
			return $this->buildResult('unhealthy', self::METHOD, $latencyMs, 'SELECT 1 查詢回傳非 0 exit code', ['exitCode' => $exitCode]);
		}

		if (trim($response['stdout']) !== '1') {
			return $this->buildResult('unhealthy', self::METHOD, $latencyMs, 'SELECT 1 查詢結果不是預期的 1', ['exitCode' => $exitCode]);
		}

		return $this->buildResult('healthy', self::METHOD, $latencyMs, 'MySQL query 健康檢查通過', ['exitCode' => $exitCode]);
	}

	/**
	 * @param array $response
	 *
	 * @return string
	 */
	private function describeCommandFailure(array $response)
	{
		if (!empty($response['timedOut'])) {
			return '執行逾時';
		}

		return !empty($response['error']) ? $response['error'] : '未知錯誤';
	}
}
