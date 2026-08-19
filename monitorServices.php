<?php
/**
 * 服務健康監控程式
 *
 * 定期檢查受監控服務健康狀態、追蹤異常/恢復狀態、透過 Google Chat
 * 發送通知，並可產生前一日監控日報。cron 驅動的一次性執行模型，
 * 不常駐、不自動重啟服務。
 * 語法相容 PHP 5.6。
 *
 * 用法：
 *   php monitorServices.php check [--dry-run] [--notify-now]
 *   php monitorServices.php report [--date=YYYY-MM-DD] [--force] [--dry-run]
 *   php monitorServices.php status
 *
 * exit code：
 *   0 - 成功且所有服務正常（或日報已成功發送/先前已發送過，或 process lock 佔用而安全跳過）
 *   1 - 設定/儲存/命令執行/通知系統錯誤
 *   2 - 健康檢查完成但至少一個服務異常
 *
 * 安裝模式：內嵌 Composer autoload，或無 composer 時降級為手動 require_once
 * （比照既有 notifyResult.php 慣例，因部分部署環境未安裝 composer）。
 *
 * @package Notifier
 */

use Notifier\Notifier\GoogleChatNotifier;
use Notifier\ServiceMonitor\ClockInterface;
use Notifier\ServiceMonitor\CurlHttpClient;
use Notifier\ServiceMonitor\DailyReportGenerator;
use Notifier\ServiceMonitor\GoogleChatNotificationSender;
use Notifier\ServiceMonitor\GoogleChatServiceMessageFormatter;
use Notifier\ServiceMonitor\IncidentManager;
use Notifier\ServiceMonitor\MonitorConfigurationException;
use Notifier\ServiceMonitor\MonitorLogRepository;
use Notifier\ServiceMonitor\MonitorStateRepository;
use Notifier\ServiceMonitor\ServiceCheckerFactory;
use Notifier\ServiceMonitor\ServiceMonitor;
use Notifier\ServiceMonitor\ShellCommandRunner;
use Notifier\ServiceMonitor\SystemClock;
use function Notifier\getConfig;
use function Notifier\readIniFile;
use function Notifier\toBool;

// monitorServices.php 依 D8 部署獨立性設計，部署為獨立 clone，
// 不像 notifyResult.php 需要以 argv 指向外部專案路徑。
define('DOCROOT', dirname(__FILE__) . '/');
define('NOTIFIER_PATH', DOCROOT);
define('INIFILE', '.env');

// 載入 Composer autoloader
$autoloadPath = NOTIFIER_PATH . 'vendor/autoload.php';
if (file_exists($autoloadPath)) {
	require_once $autoloadPath;
}

// 檢查 Notifier 類別是否已透過 autoloader 載入
// 若未載入，則使用降級模式手動載入（依相依順序：介面/抽象類別優先）
$notifierClassesLoaded = class_exists('Notifier\\ServiceMonitor\\ServiceMonitor', false)
	&& class_exists('Notifier\\Notifier\\GoogleChatNotifier', false)
	&& function_exists('Notifier\\readIniFile');

if (!$notifierClassesLoaded) {
	require_once NOTIFIER_PATH . 'src/utility.php';
	require_once NOTIFIER_PATH . 'src/Notifier.php';
	require_once NOTIFIER_PATH . 'src/Notifier/GoogleChatNotifier.php';

	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ServiceCheckerInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/HttpClientInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/CurlHttpClient.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/CommandRunnerInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ShellCommandRunner.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ClockInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/SystemClock.php';

	require_once NOTIFIER_PATH . 'src/ServiceMonitor/AbstractServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/HttpServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/CommandServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/MySqlServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/DiagnosticProviderInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/LamppStatusDiagnosticProvider.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ApacheServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/AlwaysUnknownServiceChecker.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/MonitorConfigurationException.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ServiceCheckerFactory.php';

	require_once NOTIFIER_PATH . 'src/ServiceMonitor/MonitorStateRepository.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/MonitorLogRepository.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/IncidentManager.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/NotificationSenderInterface.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/ServiceMonitor.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/DailyReportGenerator.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/GoogleChatServiceMessageFormatter.php';
	require_once NOTIFIER_PATH . 'src/ServiceMonitor/GoogleChatNotificationSender.php';
}

/**
 * 主程式
 *
 * @param array $argv
 *
 * @return int Exit code
 */
function main(array $argv)
{
	$settings = readIniFile();

	$timezone = getConfig($settings, 'SERVICE_MONITOR_TIMEZONE', 'Asia/Taipei');
	date_default_timezone_set($timezone);

	$command = isset($argv[1]) ? $argv[1] : '';
	$options = parseOptions(array_slice($argv, 2));

	if (!in_array($command, ['check', 'report', 'status'], true)) {
		echo "用法：php monitorServices.php <check|report|status> [--dry-run] [--notify-now] [--date=YYYY-MM-DD] [--force]\n";

		return 1;
	}

	$hostName = getConfig($settings, 'SERVICE_MONITOR_HOST_NAME', '');

	if (empty($hostName)) {
		$hostName = gethostname();
	}

	$storagePath = resolveStoragePath(getConfig($settings, 'MONITOR_STORAGE_PATH', 'storage/service-monitor'));
	$clock = new SystemClock();

	// status 為唯讀診斷指令，即使 SERVICE_MONITOR_ENABLED=false 仍可查詢既有資料
	if ($command === 'status') {
		return runStatus($storagePath, $clock);
	}

	$enabled = toBool(getConfig($settings, 'SERVICE_MONITOR_ENABLED', 'true'));

	if (!$enabled) {
		echo "服務監控功能已停用（SERVICE_MONITOR_ENABLED=false）\n";

		return 0;
	}

	$dryRun = !empty($options['dry-run']);
	$notifyNow = !empty($options['notify-now']);

	if ($command === 'check') {
		return runCheck($settings, $storagePath, $hostName, $clock, $dryRun, $notifyNow);
	}

	return runReport($settings, $storagePath, $hostName, $clock, $dryRun, $options);
}

/**
 * 將 MONITOR_STORAGE_PATH 解析為絕對路徑：已是絕對路徑（以 / 開頭）時原樣使用，
 * 否則視為相對於 monitorServices.php 所在目錄
 *
 * @param string $rawPath
 *
 * @return string
 */
function resolveStoragePath($rawPath)
{
	$rawPath = rtrim($rawPath, '/\\');

	if (strpos($rawPath, '/') === 0) {
		return $rawPath;
	}

	return DOCROOT . $rawPath;
}

/**
 * 解析 --key=value / --flag 選項
 *
 * @param array $args
 *
 * @return array
 */
function parseOptions(array $args)
{
	$options = [];

	foreach ($args as $arg) {
		if (strpos($arg, '--') !== 0) {
			continue;
		}

		$body = substr($arg, 2);
		$pos = strpos($body, '=');

		if ($pos === false) {
			$options[$body] = true;
		} else {
			$options[substr($body, 0, $pos)] = substr($body, $pos + 1);
		}
	}

	return $options;
}

/**
 * 執行一次服務健康檢查
 *
 * @param array          $settings
 * @param string         $storagePath
 * @param string         $hostName
 * @param ClockInterface $clock
 * @param bool           $dryRun
 * @param bool           $notifyNow 檢查後額外發送一則現況訊息（確認通知通道）
 *
 * @return int Exit code
 */
function runCheck(array $settings, $storagePath, $hostName, ClockInterface $clock, $dryRun, $notifyNow = false)
{
	$http = new CurlHttpClient();
	$cmd = new ShellCommandRunner();

	try {
		$checkers = ServiceCheckerFactory::createAll($settings, $http, $cmd, $clock);
	} catch (MonitorConfigurationException $e) {
		echo '設定錯誤：' . $e->getMessage() . "\n";

		return 1;
	}

	$lockPath = $storagePath . '/check.lock';
	$lockHandle = acquireLock($lockPath);

	if ($lockHandle === null) {
		echo "偵測到另一個執行中的程序（process lock 佔用），本次安全跳過\n";

		return 0;
	}

	try {
		$stateRepository = new MonitorStateRepository($storagePath, $clock);
		$retentionDays = (int)getConfig($settings, 'MONITOR_RETENTION_DAYS', 30);
		$logRepository = new MonitorLogRepository($storagePath, $clock, $retentionDays);

		$failureThreshold = (int)getConfig($settings, 'MONITOR_FAILURE_THRESHOLD', 1);
		$recoveryThreshold = (int)getConfig($settings, 'MONITOR_RECOVERY_THRESHOLD', 1);
		$repeatAlertMinutes = (int)getConfig($settings, 'MONITOR_REPEAT_ALERT_MINUTES', 60);
		$incidentManager = new IncidentManager($clock, $failureThreshold, $recoveryThreshold, $repeatAlertMinutes);

		$totalBudgetSeconds = (int)getConfig($settings, 'MONITOR_CHECK_TOTAL_BUDGET_SECONDS', ServiceMonitor::DEFAULT_TOTAL_BUDGET_SECONDS);

		// 空字串（例如 .env 寫成 MONITOR_CHECK_TOTAL_BUDGET_SECONDS=）會被 (int) 轉成 0，
		// 若不擋下會讓第一個服務就判定預算已耗盡，整批全部標記為 unknown
		if ($totalBudgetSeconds <= 0) {
			$totalBudgetSeconds = ServiceMonitor::DEFAULT_TOTAL_BUDGET_SECONDS;
		}

		$notificationSender = $dryRun ? null : buildNotificationSender($settings);

		$monitor = new ServiceMonitor($checkers, $stateRepository, $logRepository, $incidentManager, $clock, $hostName, $totalBudgetSeconds, $notificationSender);
		$outcome = $monitor->runCheck(!$dryRun);

		if (!$dryRun) {
			$logRepository->purgeExpired();
		}

		printCheckSummary($outcome, $dryRun);

		if ($notifyNow) {
			$snapshotCode = sendCurrentStatusSnapshot($settings, $outcome, $hostName, $dryRun);

			if ($snapshotCode !== 0) {
				return $snapshotCode;
			}
		}

		return $outcome['anyUnhealthy'] ? 2 : 0;
	} finally {
		releaseLock($lockHandle);
	}
}

/**
 * 產生並（視情況）發送前一日監控日報
 *
 * @param array          $settings
 * @param string         $storagePath
 * @param string         $hostName
 * @param ClockInterface $clock
 * @param bool           $dryRun
 * @param array          $options
 *
 * @return int Exit code
 */
function runReport(array $settings, $storagePath, $hostName, ClockInterface $clock, $dryRun, array $options)
{
	$retentionDays = (int)getConfig($settings, 'MONITOR_RETENTION_DAYS', 30);
	$logRepository = new MonitorLogRepository($storagePath, $clock, $retentionDays);
	$intervalMinutes = (int)getConfig($settings, 'MONITOR_INTERVAL_MINUTES', 5);
	$generator = new DailyReportGenerator($logRepository, $clock, $storagePath, $intervalMinutes);

	$date = (isset($options['date']) && $options['date'] !== true) ? $options['date'] : dateOffsetDays($clock, -1);

	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		echo "錯誤：--date 必須為 YYYY-MM-DD 格式\n";

		return 1;
	}

	$force = !empty($options['force']);

	if (!$dryRun && !$force && $generator->isAlreadySent($date)) {
		echo "{$date} 的日報先前已成功發送過，不重複發送（可加上 --force 強制重送）\n";

		return 0;
	}

	$report = $generator->generate($date);
	$formatter = new GoogleChatServiceMessageFormatter();
	$message = $formatter->formatDailyReport($report, $hostName);

	if ($dryRun) {
		echo "=== Dry-run 模式：不會實際發送通知、不建立 sent marker ===\n";
		echo $message . "\n";

		return 0;
	}

	$notifier = buildGoogleChatNotifier($settings);

	if ($notifier === null) {
		echo "通知未啟用或缺少 GOOGLE_CHAT_WEBHOOK，無法發送日報\n";

		return 1;
	}

	$sent = $notifier->send($message, true);

	if (!$sent) {
		echo "日報發送失敗\n";

		return 1;
	}

	$generator->markAsSent($date);
	echo "{$date} 日報已發送\n";

	return 0;
}

/**
 * 顯示每個服務的最後狀態
 *
 * @param string         $storagePath
 * @param ClockInterface $clock
 *
 * @return int Exit code
 */
function runStatus($storagePath, ClockInterface $clock)
{
	$stateRepository = new MonitorStateRepository($storagePath, $clock);
	$state = $stateRepository->load();

	if (empty($state)) {
		echo "尚無任何監控狀態記錄\n";

		return 0;
	}

	foreach ($state as $serviceKey => $serviceState) {
		$status = isset($serviceState['currentStatus']) ? $serviceState['currentStatus'] : null;
		$displayStatus = $status !== null ? $status : 'pending';
		$icon = $status === 'healthy' ? '✅' : ($status === 'unhealthy' ? '❌' : '❓');
		$lastCheckedAt = isset($serviceState['lastCheckedAt']) ? $serviceState['lastCheckedAt'] : '無';

		echo "{$icon} {$serviceKey}：{$displayStatus}（最後檢查：{$lastCheckedAt}）\n";
	}

	return 0;
}

/**
 * @param array $outcome
 * @param bool  $dryRun
 *
 * @return void
 */
function printCheckSummary(array $outcome, $dryRun)
{
	if ($dryRun) {
		echo "=== Dry-run 模式：不會實際發送通知、不更新 state.json/log ===\n";
	}

	foreach ($outcome['results'] as $serviceKey => $result) {
		$icon = $result['status'] === 'healthy' ? '✅' : ($result['status'] === 'unhealthy' ? '❌' : '❓');
		echo "{$icon} {$serviceKey} [{$result['label']}]：{$result['status']} - {$result['message']}\n";
	}
}

/**
 * 發送（或 dry-run 預覽）本次檢查的現況 Cards V2 訊息
 *
 * @param array  $settings
 * @param array  $outcome
 * @param string $hostName
 * @param bool   $dryRun
 *
 * @return int 0 成功預覽或已發送；1 通道未設定或發送失敗
 */
function sendCurrentStatusSnapshot(array $settings, array $outcome, $hostName, $dryRun)
{
	$formatter = new GoogleChatServiceMessageFormatter();
	$message = $formatter->formatStatusSnapshot($outcome['results'], $hostName);

	if ($dryRun) {
		echo "=== 現況訊息（dry-run，不會實際發送）===\n";
		echo $message . "\n";

		return 0;
	}

	$notifier = buildGoogleChatNotifier($settings);

	if ($notifier === null) {
		echo "通知未啟用或缺少 GOOGLE_CHAT_WEBHOOK，無法發送現況訊息\n";

		return 1;
	}

	$sender = new GoogleChatNotificationSender($formatter, $notifier);

	if (!$sender->sendStatusSnapshot($outcome['results'], $hostName)) {
		echo "現況訊息發送失敗\n";

		return 1;
	}

	echo "現況訊息已發送，請到 Google Chat 確認通知通道可用\n";

	return 0;
}

/**
 * @param array $settings
 *
 * @return GoogleChatNotifier|null
 */
function buildGoogleChatNotifier(array $settings)
{
	$notifyEnabled = toBool(getConfig($settings, 'NOTIFY_ENABLED', 'false'));

	if (!$notifyEnabled) {
		return null;
	}

	$webhookUrl = getConfig($settings, 'GOOGLE_CHAT_WEBHOOK', '');

	if (empty($webhookUrl)) {
		return null;
	}

	$timeout = (int)getConfig($settings, 'NOTIFY_TIMEOUT', 30);

	return new GoogleChatNotifier($webhookUrl, true, $timeout);
}

/**
 * @param array $settings
 *
 * @return GoogleChatNotificationSender|null
 */
function buildNotificationSender(array $settings)
{
	$notifier = buildGoogleChatNotifier($settings);

	if ($notifier === null) {
		return null;
	}

	return new GoogleChatNotificationSender(new GoogleChatServiceMessageFormatter(), $notifier);
}

/**
 * @param ClockInterface $clock
 * @param int            $offsetDays
 *
 * @return string YYYY-MM-DD
 */
function dateOffsetDays(ClockInterface $clock, $offsetDays)
{
	$date = $clock->now();
	$date->modify($offsetDays . ' days');

	return $date->format('Y-m-d');
}

/**
 * 取得 process lock，避免 cron 重疊執行
 *
 * @param string $lockPath
 *
 * @return resource|null
 */
function acquireLock($lockPath)
{
	$dir = dirname($lockPath);

	if (!is_dir($dir)) {
		mkdir($dir, 0755, true);
	}

	$handle = fopen($lockPath, 'c');

	if ($handle === false) {
		return null;
	}

	if (!flock($handle, LOCK_EX | LOCK_NB)) {
		fclose($handle);

		return null;
	}

	return $handle;
}

/**
 * @param resource|null $handle
 *
 * @return void
 */
function releaseLock($handle)
{
	if ($handle === null) {
		return;
	}

	flock($handle, LOCK_UN);
	fclose($handle);
}

// 執行主程式
exit(main($argv));
