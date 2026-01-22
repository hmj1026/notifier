<?php
/**
 * 工具函式
 *
 * 提供通用工具函式，如讀取 .env 檔案等。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier
 */

namespace Notifier;

/**
 * 讀取 .env 檔案
 *
 * 解析 INI 格式的環境變數檔案
 *
 * @param string $path .env 檔案路徑 (選填)
 *
 * @return array 環境變數陣列
 */
function readIniFile($path = null)
{
	if ($path === null) {
		if (defined('DOCROOT') && defined('INIFILE')) {
			$path = DOCROOT . INIFILE;
		} else {
			$path = dirname(__DIR__) . '/.env';
		}
	}

	if (!file_exists($path)) {
		return [];
	}

	$settings = [];
	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

	foreach ($lines as $line) {
		// 跳過註解
		$line = trim($line);
		if (empty($line) || strpos($line, '#') === 0) {
			continue;
		}

		// 解析 KEY=VALUE
		$pos = strpos($line, '=');
		if ($pos !== false) {
			$key = trim(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1));

			// 移除引號
			if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
				(substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
				$value = substr($value, 1, -1);
			}

			$settings[$key] = $value;
		}
	}

	return $settings;
}

/**
 * 取得設定值 (含預設值)
 *
 * @param array  $settings 設定陣列
 * @param string $key      鍵名
 * @param mixed  $default  預設值
 *
 * @return mixed
 */
function getConfig($settings, $key, $default = null)
{
	return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * 布林值轉換
 *
 * 將字串轉換為布林值
 *
 * @param string $value 字串值
 *
 * @return bool
 */
function toBool($value)
{
	if (is_bool($value)) {
		return $value;
	}

	$value = strtolower(trim($value));

	return in_array($value, ['true', '1', 'yes', 'on']);
}
