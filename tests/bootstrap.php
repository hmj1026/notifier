<?php
/**
 * PHPUnit 測試啟動檔
 *
 * 載入 Composer autoloader，支援 PHPUnit 5.7+
 */

// Composer autoloader
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
	require_once $autoloadPath;
} else {
	// 降級模式：直接載入類別
	require_once dirname(__DIR__) . '/src/utility.php';
	require_once dirname(__DIR__) . '/src/Notifier.php';
	require_once dirname(__DIR__) . '/src/LogAnalyzer.php';
	require_once dirname(__DIR__) . '/src/Notifier/GoogleChatNotifier.php';
}

// 設定時區
date_default_timezone_set('Asia/Taipei');
