<?php
/**
 * 排程執行結果通知程式
 *
 * 分析 log 檔案並發送通知至 Google Chat。
 * 語法相容 PHP 5.6。
 *
 * 用法：
 *   php notifyResult.php <專案路徑> [notifier路徑] [Log檔名] [任務名稱]
 *
 * 參數說明：
 *   專案路徑    - 必填。目標專案的根目錄路徑
 *   notifier路徑 - 選填。留空 "" 表示自動偵測
 *   Log檔名     - 選填。覆寫 .env 的 LOG_FILENAME (例: postOrder.log)
 *   任務名稱    - 選填。通知顯示名稱 (例: 訂單上傳)，預設為專案名稱
 *
 * 範例：
 *   php notifyResult.php /var/www/project "" postOrder.log "訂單上傳"
 *   php notifyResult.php /var/www/project "" getAllocate.log "配額取得"
 *
 * 安裝模式：
 *   1. 內嵌模式：src/ 在專案根目錄
 *   2. lib 模式：src/ 在 lib/notifier/ 目錄
 *   3. 外部模式：透過 NOTIFIER_PATH 環境變數或第二參數指定
 *
 * @package Notifier
 */

use Notifier\LogAnalyzer;
use Notifier\Notifier\GoogleChatNotifier;
use function Notifier\readIniFile;
use function Notifier\getConfig;
use function Notifier\toBool;

// 設定專案根目錄
if (isset($argv[1])) {
	define('DOCROOT', rtrim($argv[1], '/\\') . '/');
} else {
	define('DOCROOT', dirname(__FILE__) . '/');
}

// 設定 Notifier 路徑 (支援多種安裝模式)
$notifierPath = null;

// 1. 優先使用第二個命令列參數 (空字串表示自動偵測)
if (isset($argv[2]) && $argv[2] !== '') {
	$notifierPath = rtrim($argv[2], '/\\') . '/';
}

// 2. 其次檢查環境變數 NOTIFIER_PATH
if ($notifierPath === null && getenv('NOTIFIER_PATH')) {
	$notifierPath = rtrim(getenv('NOTIFIER_PATH'), '/\\') . '/';
}

// 3. 檢查 lib/notifier/ 目錄
if ($notifierPath === null && is_dir(DOCROOT . 'lib/notifier/src')) {
	$notifierPath = DOCROOT . 'lib/notifier/';
}

// 4. 預設為專案根目錄 (內嵌模式)
if ($notifierPath === null) {
	$notifierPath = DOCROOT;
}

define('NOTIFIER_PATH', $notifierPath);
define('INIFILE', '.env');
date_default_timezone_set('Asia/Taipei');

// 載入 Composer autoloader (優先檢查專案根目錄，再檢查 notifier 目錄)
$autoloadPaths = [
	NOTIFIER_PATH . 'vendor/autoload.php',  // 優先 notifier 自己的 autoloader
	DOCROOT . 'vendor/autoload.php',        // 其次專案根目錄
];

foreach ($autoloadPaths as $autoloadPath) {
	if (file_exists($autoloadPath)) {
		require_once $autoloadPath;
	}
}

// 檢查 Notifier 類別是否已透過 autoloader 載入
// 若未載入，則使用降級模式手動載入
$notifierClassesLoaded = class_exists('Notifier\\LogAnalyzer', false)
	&& class_exists('Notifier\\Notifier\\GoogleChatNotifier', false)
	&& function_exists('Notifier\\readIniFile');

if (!$notifierClassesLoaded) {
	// 降級模式：直接載入類別
	// 當專案有 vendor/autoload.php 但未註冊 Notifier 命名空間時使用
	require_once NOTIFIER_PATH . 'src/utility.php';
	require_once NOTIFIER_PATH . 'src/Notifier.php';
	require_once NOTIFIER_PATH . 'src/LogAnalyzer.php';
	require_once NOTIFIER_PATH . 'src/LogAnalyzer/KPMCLogAnalyzer.php';
	require_once NOTIFIER_PATH . 'src/LogAnalyzer/ANE072LogAnalyzer.php';
	require_once NOTIFIER_PATH . 'src/Notifier/GoogleChatNotifier.php';
}

/**
 * 主程式
 *
 * @return int Exit code
 */
function main()
{
	// 讀取設定
	$settings = readIniFile();

	// 檢查是否啟用通知
	$notifyEnabled = getConfig($settings, 'NOTIFY_ENABLED', 'false');
	if (!toBool($notifyEnabled)) {
		echo "通知功能已停用\n";

		return 0;
	}

	// 取得 Log 設定
	$logDirectory = getConfig($settings, 'LOG_DIRECTORY', 'log');
	$logFilename = getConfig($settings, 'LOG_FILENAME', 'SendDelivery.log');

	// 命令列參數覆寫：$argv[3] = Log 檔名, $argv[4] = 任務名稱
	global $argv;
	if (isset($argv[3]) && $argv[3] !== '') {
		$logFilename = $argv[3];
	}
	$jobName = isset($argv[4]) && $argv[4] !== '' ? $argv[4] : '';

	// 組合今日 log 路徑
	$logPath = sprintf(
		'%s/%s/%s/%s/%s',
		$logDirectory,
		date('Y'),
		date('m'),
		date('d'),
		$logFilename
	);
	$fullLogPath = DOCROOT . $logPath;

	// 檢查 log 檔案是否存在
	if (!file_exists($fullLogPath)) {
		echo "Log 檔案不存在：{$fullLogPath}\n";

		return 1;
	}

	// 讀取 log 內容
	$logContent = file_get_contents($fullLogPath);
	if ($logContent === false) {
		echo "無法讀取 Log 檔案：{$fullLogPath}\n";

		return 1;
	}

	// 取得任務顯示名稱
	// 優先使用命令列參數 $argv[4]，其次使用專案資料夾名稱
	$projectName = !empty($jobName) ? $jobName : basename(rtrim(DOCROOT, '/\\'));

	// 根據專案路徑自動判斷 Log 格式類型
	// 使用 DOCROOT 而非 $projectName，因為 $projectName 可能被 jobName 覆蓋
	$logFormat = 'kpmc';  // 預設格式
	if (stripos(DOCROOT, 'ANE072') !== false) {
		$logFormat = 'ane072';
	}

	// 使用工廠方法建立對應的 Log 分析器
	$analyzer = LogAnalyzer::create($logFormat);
	$analysis = $analyzer->analyze($logContent, $logPath, $projectName);

	// 檢查通知策略
	$notifyStrategy = getConfig($settings, 'NOTIFY_STRATEGY', 'all');
	if ($notifyStrategy === 'failure_only' && $analysis['success']) {
		echo "通知策略為僅失敗通知，本次執行成功，不發送通知\n";

		return 0;
	}

	// 取得 Webhook URL
	$webhookUrl = getConfig($settings, 'GOOGLE_CHAT_WEBHOOK', '');
	if (empty($webhookUrl)) {
		echo "錯誤：未設定 GOOGLE_CHAT_WEBHOOK\n";

		return 1;
	}

	// 取得超時設定
	$timeout = (int)getConfig($settings, 'NOTIFY_TIMEOUT', 30);

	// 建立通知器
	$notifier = new GoogleChatNotifier($webhookUrl, true, $timeout);

	// 格式化並發送通知
	$message = $notifier->formatMessage($analysis);

	// 除錯模式
	$debugMode = toBool(getConfig($settings, 'DEBUG_MODE', 'false'));
	if ($debugMode) {
		echo "=== 除錯訊息 ===\n";
		echo "Log 路徑: {$fullLogPath}\n";
		echo "分析結果: " . print_r($analysis, true) . "\n";
		echo "通知訊息:\n{$message}\n";
		echo "================\n";
	}

	// 發送通知
	$result = $notifier->send($message, $analysis['success']);

	if ($result) {
		echo "通知發送成功\n";

		return 0;
	} else {
		echo "通知發送失敗\n";

		return 1;
	}
}

// 執行主程式
exit(main());
