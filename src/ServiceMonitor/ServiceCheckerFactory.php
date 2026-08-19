<?php
/**
 * 服務 Checker 工廠
 *
 * 一次性批次解析 MONITOR_SERVICES 清單，為每個服務建立對應的
 * ServiceCheckerInterface 實作。單一服務的設定格式錯誤會被降級為
 * 該服務專屬的「永遠回傳 unknown」checker（AlwaysUnknownServiceChecker），
 * 不會拖垮整批解析；MONITOR_SERVICES 本身缺漏/為空則是頂層明確
 * 設定錯誤，會拋出 MonitorConfigurationException。
 *
 * 這裡刻意不沿用 LogAnalyzer::create()「switch + 回傳 null」的慣例，
 * 因為單一服務設定錯誤在這裡的處理方式不同：降級為 unknown checker
 * 並記錄診斷原因，而不是中止呼叫端的流程。
 *
 * apache/mysql 這兩個 serviceKey 額外支援 propmts.md 既有的扁平命名
 * fallback（見各 create*Checker 方法），其餘服務一律使用
 * MONITOR_SERVICE_<KEY>_* 命名空間設定。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

use function Notifier\toBool;

class ServiceCheckerFactory
{
	/**
	 * 一次性批次解析所有受監控服務
	 *
	 * @param array                   $rawConfig 完整設定陣列（readIniFile() 的原始輸出）
	 * @param HttpClientInterface     $http
	 * @param CommandRunnerInterface  $cmd
	 * @param ClockInterface          $clock
	 *
	 * @return array<string, ServiceCheckerInterface> 以 serviceKey 為 key，依 MONITOR_SERVICES 順序排列
	 *
	 * @throws MonitorConfigurationException 當 MONITOR_SERVICES 缺漏或為空
	 */
	public static function createAll(array $rawConfig, HttpClientInterface $http, CommandRunnerInterface $cmd, ClockInterface $clock)
	{
		$serviceKeysRaw = isset($rawConfig['MONITOR_SERVICES']) ? trim($rawConfig['MONITOR_SERVICES']) : '';

		if ($serviceKeysRaw === '') {
			throw new MonitorConfigurationException('MONITOR_SERVICES 未設定或為空，請至少指定一個受監控服務');
		}

		$serviceKeys = array_filter(array_map('trim', explode(',', $serviceKeysRaw)), function ($key) {
			return $key !== '';
		});

		$checkers = [];

		foreach ($serviceKeys as $serviceKey) {
			$checkers[$serviceKey] = self::createOne($serviceKey, $rawConfig, $http, $cmd, $clock);
		}

		return $checkers;
	}

	/**
	 * 建立單一服務的 checker；設定錯誤時降級為永遠 unknown 的 checker
	 *
	 * @param string                 $serviceKey
	 * @param array                  $rawConfig
	 * @param HttpClientInterface    $http
	 * @param CommandRunnerInterface $cmd
	 * @param ClockInterface         $clock
	 *
	 * @return ServiceCheckerInterface
	 */
	private static function createOne($serviceKey, array $rawConfig, HttpClientInterface $http, CommandRunnerInterface $cmd, ClockInterface $clock)
	{
		$namespace = strtoupper($serviceKey);
		$label = self::readNamespaced($rawConfig, $namespace, 'LABEL', $serviceKey);

		try {
			$customClass = self::readNamespaced($rawConfig, $namespace, 'CLASS', '');

			if (!empty($customClass)) {
				return self::createCustom($serviceKey, $customClass, $rawConfig, $namespace, $clock);
			}

			$type = self::readNamespaced($rawConfig, $namespace, 'TYPE', '');

			if (empty($type)) {
				$type = self::flatFallbackDefaultType($serviceKey);
			}

			if (empty($type)) {
				throw new \RuntimeException('缺少必要參數 TYPE');
			}

			switch ($type) {
				case 'http':
					return self::createHttpChecker($serviceKey, $label, $rawConfig, $namespace, $http, $cmd, $clock);
				case 'command':
					return self::createCommandChecker($serviceKey, $label, $rawConfig, $namespace, $cmd, $clock);
				case 'mysql':
					return self::createMysqlChecker($serviceKey, $label, $rawConfig, $namespace, $cmd, $clock);
				default:
					throw new \RuntimeException("不支援的服務型別：{$type}");
			}
		} catch (\Exception $e) {
			return new AlwaysUnknownServiceChecker($serviceKey, $label, $clock, '設定錯誤：' . $e->getMessage());
		}
	}

	/**
	 * apache/mysql 這兩個 serviceKey 在未設定 TYPE 時的預設型別
	 *
	 * @param string $serviceKey
	 *
	 * @return string 空字串代表沒有預設型別（其餘服務必須明確指定 TYPE）
	 */
	private static function flatFallbackDefaultType($serviceKey)
	{
		if ($serviceKey === 'apache') {
			return 'http';
		}

		if ($serviceKey === 'mysql') {
			return 'mysql';
		}

		return '';
	}

	/**
	 * 讀取 MONITOR_SERVICE_<namespace>_<suffix>（無扁平命名 fallback，適用於 TYPE/LABEL/CLASS）
	 *
	 * @param array  $rawConfig
	 * @param string $namespace
	 * @param string $suffix
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	private static function readNamespaced(array $rawConfig, $namespace, $suffix, $default)
	{
		$key = "MONITOR_SERVICE_{$namespace}_{$suffix}";

		return (isset($rawConfig[$key]) && $rawConfig[$key] !== '') ? $rawConfig[$key] : $default;
	}

	/**
	 * 命名空間設定優先，$flatKey 提供時退回扁平命名，兩者皆缺時回傳 $default
	 *
	 * @param array       $rawConfig
	 * @param string      $namespaceKey
	 * @param string|null $flatKey
	 * @param mixed       $default
	 *
	 * @return mixed
	 */
	private static function resolveValue(array $rawConfig, $namespaceKey, $flatKey, $default = null)
	{
		if (isset($rawConfig[$namespaceKey]) && $rawConfig[$namespaceKey] !== '') {
			return $rawConfig[$namespaceKey];
		}

		if ($flatKey !== null && isset($rawConfig[$flatKey]) && $rawConfig[$flatKey] !== '') {
			return $rawConfig[$flatKey];
		}

		return $default;
	}

	/**
	 * @param string                 $serviceKey
	 * @param string                 $label
	 * @param array                  $rawConfig
	 * @param string                 $namespace
	 * @param HttpClientInterface    $http
	 * @param CommandRunnerInterface $cmd
	 * @param ClockInterface         $clock
	 *
	 * @return ServiceCheckerInterface
	 */
	private static function createHttpChecker($serviceKey, $label, array $rawConfig, $namespace, HttpClientInterface $http, CommandRunnerInterface $cmd, ClockInterface $clock)
	{
		$isApacheFallback = ($serviceKey === 'apache');

		$config = [
			'label'                => $label,
			'url'                  => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_URL", $isApacheFallback ? 'APACHE_HEALTHCHECK_URL' : null, 'http://127.0.0.1/'),
			'hostHeader'           => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_HOST_HEADER", $isApacheFallback ? 'APACHE_HEALTHCHECK_HOST_HEADER' : null, ''),
			'expectedBodyContains' => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_EXPECTED_BODY_CONTAINS", $isApacheFallback ? 'APACHE_EXPECTED_BODY_CONTAINS' : null, ''),
			'minStatus'            => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_MIN_STATUS", $isApacheFallback ? 'APACHE_HTTP_MIN_STATUS' : null, 200),
			'maxStatus'            => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_MAX_STATUS", $isApacheFallback ? 'APACHE_HTTP_MAX_STATUS' : null, 399),
			'connectTimeout'       => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_CONNECT_TIMEOUT", $isApacheFallback ? 'APACHE_CONNECT_TIMEOUT' : null, 5),
			'requestTimeout'       => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_REQUEST_TIMEOUT", $isApacheFallback ? 'APACHE_REQUEST_TIMEOUT' : null, 10),
			'verifySsl'            => true,
		];

		if (empty($config['url'])) {
			throw new \RuntimeException('http 型別缺少必要參數 URL');
		}

		if (!$isApacheFallback) {
			return new HttpServiceChecker($serviceKey, $config, $http, $clock);
		}

		$lamppRoot = self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_LAMPP_ROOT", 'LAMPP_ROOT', '/opt/lampp');
		$statusCheckEnabled = toBool(self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_LAMPP_STATUS_CHECK_ENABLED", 'LAMPP_STATUS_CHECK_ENABLED', 'true'));
		$statusStrict = toBool(self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_LAMPP_STATUS_STRICT", 'LAMPP_STATUS_STRICT', 'false'));

		$diagnosticProvider = $statusCheckEnabled ? new LamppStatusDiagnosticProvider($lamppRoot, $cmd, $statusStrict) : null;

		return new ApacheServiceChecker($serviceKey, $config, $http, $clock, $diagnosticProvider);
	}

	/**
	 * @param string                 $serviceKey
	 * @param string                 $label
	 * @param array                  $rawConfig
	 * @param string                 $namespace
	 * @param CommandRunnerInterface $cmd
	 * @param ClockInterface         $clock
	 *
	 * @return ServiceCheckerInterface
	 */
	private static function createCommandChecker($serviceKey, $label, array $rawConfig, $namespace, CommandRunnerInterface $cmd, ClockInterface $clock)
	{
		$binaryPath = self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_BINARY_PATH", null, '');

		if (empty($binaryPath)) {
			throw new \RuntimeException('command 型別缺少必要參數 BINARY_PATH');
		}

		$argsRaw = self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_ARGS", null, '');
		$args = $argsRaw !== '' ? array_map('trim', explode(',', $argsRaw)) : [];

		$config = [
			'label'                  => $label,
			'binaryPath'             => $binaryPath,
			'args'                   => $args,
			'expectedExitCode'       => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_EXPECTED_EXIT_CODE", null, 0),
			'expectedOutputContains' => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_EXPECTED_OUTPUT_CONTAINS", null, ''),
			'timeoutSeconds'         => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_TIMEOUT_SECONDS", null, 10),
		];

		return new CommandServiceChecker($serviceKey, $config, $cmd, $clock);
	}

	/**
	 * @param string                 $serviceKey
	 * @param string                 $label
	 * @param array                  $rawConfig
	 * @param string                 $namespace
	 * @param CommandRunnerInterface $cmd
	 * @param ClockInterface         $clock
	 *
	 * @return ServiceCheckerInterface
	 */
	private static function createMysqlChecker($serviceKey, $label, array $rawConfig, $namespace, CommandRunnerInterface $cmd, ClockInterface $clock)
	{
		$isMysqlFallback = ($serviceKey === 'mysql');

		$config = [
			'label'             => $label,
			'mode'              => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_MODE", $isMysqlFallback ? 'MYSQL_HEALTHCHECK_MODE' : null, 'ping'),
			'mysqladminPath'    => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_MYSQLADMIN_PATH", $isMysqlFallback ? 'MYSQLADMIN_PATH' : null, ''),
			'mysqlClientPath'   => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_MYSQL_CLIENT_PATH", $isMysqlFallback ? 'MYSQL_CLIENT_PATH' : null, ''),
			'host'              => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_HOST", $isMysqlFallback ? 'MYSQL_HOST' : null, '127.0.0.1'),
			'port'              => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_PORT", $isMysqlFallback ? 'MYSQL_PORT' : null, '3306'),
			'socket'            => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_SOCKET", $isMysqlFallback ? 'MYSQL_SOCKET' : null, ''),
			'connectTimeout'    => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_CONNECT_TIMEOUT", $isMysqlFallback ? 'MYSQL_CONNECT_TIMEOUT' : null, 5),
			'defaultsExtraFile' => self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_DEFAULTS_EXTRA_FILE", $isMysqlFallback ? 'MYSQL_DEFAULTS_EXTRA_FILE' : null, ''),
			'timeoutSeconds'    => (int)self::resolveValue($rawConfig, "MONITOR_SERVICE_{$namespace}_TIMEOUT_SECONDS", null, 10),
		];

		if ($config['mode'] === 'ping' && empty($config['mysqladminPath'])) {
			throw new \RuntimeException('mysql ping 模式缺少必要參數 MYSQLADMIN_PATH');
		}

		if ($config['mode'] === 'query' && (empty($config['mysqlClientPath']) || empty($config['defaultsExtraFile']))) {
			throw new \RuntimeException('mysql query 模式缺少必要參數 MYSQL_CLIENT_PATH 或 DEFAULTS_EXTRA_FILE');
		}

		return new MySqlServiceChecker($serviceKey, $config, $cmd, $clock);
	}

	/**
	 * MONITOR_SERVICE_<KEY>_CLASS escape hatch：自訂 checker 類別
	 * 需自行實作 ServiceCheckerInterface，建構子簽章為
	 * (string $serviceKey, array $rawConfig, string $namespace, ClockInterface $clock)。
	 *
	 * @param string         $serviceKey
	 * @param string         $className
	 * @param array          $rawConfig
	 * @param string         $namespace
	 * @param ClockInterface $clock
	 *
	 * @return ServiceCheckerInterface
	 */
	private static function createCustom($serviceKey, $className, array $rawConfig, $namespace, ClockInterface $clock)
	{
		if (!class_exists($className)) {
			throw new \RuntimeException("自訂 checker 類別不存在：{$className}");
		}

		$instance = new $className($serviceKey, $rawConfig, $namespace, $clock);

		if (!($instance instanceof ServiceCheckerInterface)) {
			throw new \RuntimeException("自訂 checker 類別未實作 ServiceCheckerInterface：{$className}");
		}

		return $instance;
	}
}
