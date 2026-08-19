<?php
/**
 * 外部命令執行介面（true-external port）
 *
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

interface CommandRunnerInterface
{
	/**
	 * 執行外部命令
	 *
	 * $options 支援：
	 *   timeoutSeconds - int 執行逾時秒數
	 *   maxOutputBytes - int 標準輸出/錯誤輸出各自的擷取上限（bytes）
	 *
	 * 回傳陣列結構：
	 *   exitCode  - int|null     Exit code（逾時或無法啟動時為 null）
	 *   stdout    - string      標準輸出（已依 maxOutputBytes 截斷）
	 *   stderr    - string      標準錯誤輸出（已依 maxOutputBytes 截斷）
	 *   timedOut  - bool        是否逾時
	 *   error     - string|null 錯誤說明（binary 不存在/不可執行/逾時等）
	 *   latencyMs - int         耗時（毫秒）
	 *
	 * @param string $binaryPath binary 完整路徑
	 * @param array  $args       命令列參數（實作端負責逐一 escape，呼叫端不得手動拼接字串）
	 * @param array  $options    選項
	 *
	 * @return array
	 */
	public function run($binaryPath, array $args = [], array $options = []);
}
