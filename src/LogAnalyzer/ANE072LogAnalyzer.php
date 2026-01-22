<?php
/**
 * ANE072 專案 Log 分析器
 *
 * 分析 ANE072 專案的 log 格式。
 * 特徵：Start/End 標記、Status: True/False、IfSucceed: True/False
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\LogAnalyzer
 */

namespace Notifier\LogAnalyzer;

use Notifier\LogAnalyzer;

class ANE072LogAnalyzer extends LogAnalyzer
{
	/**
	 * 分析 ANE072 格式 Log（批次處理聚合統計）
	 *
	 * @param string $content     Log 內容
	 * @param string $logPath     Log 檔案路徑
	 * @param string $projectName 任務名稱
	 *
	 * @return array 分析結果
	 */
	public function analyze($content, $logPath, $projectName = '')
	{
		$result = [
			'success'          => true,
			'recordsProcessed' => 0,
			'successCount'     => 0,
			'failureCount'     => 0,
			'errorBreakdown'   => [],  // 錯誤訊息統計 ['錯誤訊息' => 筆數]
			'personIds'        => [],
			'errorType'        => '',
			'errorHint'        => '',
			'logPath'          => $logPath,
			'projectName'      => $projectName,
		];

		// 檢查是否有 End 標記（程式是否正常結束）
		$hasEnd = preg_match('/============.*End.*============/', $content);

		if (!$hasEnd) {
			// 程式異常中斷，完全沒有資料
			$result['success'] = false;
			$result['errorType'] = '程式異常中斷';
			$result['errorHint'] = 'Log 未正常結束，請檢查程式執行狀態';

			return $result;
		}

		// 格式 1: "Status: True" 或 "Status: False ErrorMsg: XXXX"
		// 格式 2: "IfSucceed":"True" 或 "IfSucceed":"False"

		// 將內容分割成行，避免正則表達式跨行匹配問題
		$lines = preg_split('/(\r\n|\r|\n)/', $content);

		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) continue;

			// 檢查 Status 行
			if (preg_match('/Status:\s*(True|False)/i', $line, $statusMatch)) {
				$isSuccess = (strcasecmp($statusMatch[1], 'True') === 0);

				// 提取 ErrorMsg (如果有)
				$errorMsg = '';
				if (preg_match('/ErrorMsg:\s*(.*)/', $line, $errMsgMatch)) {
					$errorMsg = trim($errMsgMatch[1]);
				} elseif (!$isSuccess) {
					// 如果是 False 但沒有 ErrorMsg 標籤，嘗試取 Status 後面的內容（視情況而定，目前保持空）
				}

				if ($isSuccess) {
					$result['successCount']++;
				} else {
					$result['failureCount']++;
					if (!empty($errorMsg)) {
						$normalizedMsg = $this->normalizeErrorMessage($errorMsg);
						if (!isset($result['errorBreakdown'][$normalizedMsg])) {
							$result['errorBreakdown'][$normalizedMsg] = 0;
						}
						$result['errorBreakdown'][$normalizedMsg]++;
					}
				}
				continue; // 處理下一行
			}

			// 檢查 JSON IfSucceed 格式
			if (strpos($line, '"IfSucceed"') !== false) {
				if (preg_match('/"IfSucceed":\s*"(True|False)"/i', $line, $ifSucceedMatch)) {
					$isSuccess = (strcasecmp($ifSucceedMatch[1], 'True') === 0);
					$errorMsg = '';

					if (preg_match('/"ErrMessage":\s*"([^"]*)"/', $line, $errMsgMatch)) {
						$errorMsg = trim($errMsgMatch[1]);
					}

					if ($isSuccess) {
						$result['successCount']++;
					} else {
						$result['failureCount']++;
						if (!empty($errorMsg)) {
							$normalizedMsg = $this->normalizeErrorMessage($errorMsg);
							if (!isset($result['errorBreakdown'][$normalizedMsg])) {
								$result['errorBreakdown'][$normalizedMsg] = 0;
							}
							$result['errorBreakdown'][$normalizedMsg]++;
						}
					}
				}
			}
		}

		// ========== getItem 格式解析 ==========
		// 格式：「第 X 個商品[XXX] 更新: 成功!」或「更新: 失敗」
		// 格式：「總共更新 X 筆」「總共新增 X 筆」

		// 統計更新成功/失敗
		preg_match_all('/更新:\s*(成功|失敗)/u', $content, $updateMatches, PREG_SET_ORDER);
		foreach ($updateMatches as $match) {
			if ($match[1] === '成功') {
				$result['successCount']++;
			} else {
				$result['failureCount']++;
			}
		}

		// 提取「總共更新 X 筆」作為處理筆數參考
		if (preg_match('/總共更新\s*(\d+)\s*筆/u', $content, $totalMatch)) {
			// 如果已有 successCount，不覆蓋；否則使用這個數字
			if ($result['successCount'] === 0) {
				$result['successCount'] = (int)$totalMatch[1];
			}
		}

		// ========== getAllocate 格式解析 ==========
		// 格式：只有資料列表，無 Status 行
		// 判斷：有 Start 標記 + 有 End 標記 + 有資料行 = 成功

		// 檢查是否為 getAllocate 格式（有 GetAllocate Start 標記）
		$isAllocateFormat = preg_match('/GetAllocate\s+Start/i', $content);
		if ($isAllocateFormat && $result['recordsProcessed'] === 0 && $result['successCount'] === 0) {
			// 計算資料行數（排除空行和標記行）
			$lines = explode("\n", $content);
			$dataLineCount = 0;
			foreach ($lines as $line) {
				$trimmedLine = trim($line);
				// 排除空行、Start/End 標記行、日期標題行
				if (!empty($trimmedLine)
					&& strpos($trimmedLine, '============') === false
					&& strpos($trimmedLine, '撈回') === false) {
					$dataLineCount++;
				}
			}

			if ($dataLineCount > 0) {
				$result['successCount'] = $dataLineCount;
				$result['recordsProcessed'] = $dataLineCount;
			}
		}

		// 計算總筆數
		$result['recordsProcessed'] = $result['successCount'] + $result['failureCount'];

		// 判斷整體是否成功
		// ANE072 的邏輯：完全沒資料才算失敗，有處理資料就算成功（即使有部分失敗）
		if ($result['recordsProcessed'] === 0) {
			$result['success'] = false;
			$result['errorType'] = '無處理資料';
			$result['errorHint'] = '未找到任何處理記錄，請檢查排程是否正常執行';
		} else {
			// 有處理資料就算成功，即使有部分失敗
			$result['success'] = true;

			// 但如果有失敗，設定提示訊息
			if ($result['failureCount'] > 0) {
				$result['errorHint'] = '部分資料處理失敗，請查看錯誤統計';
			}
		}

		return $result;
	}

	/**
	 * 正規化錯誤訊息
	 *
	 * 將動態內容（如單據號碼、時間等）替換為佔位符，以便聚合統計
	 *
	 * @param string $errorMsg 原始錯誤訊息
	 *
	 * @return string 正規化後的錯誤訊息
	 */
	private function normalizeErrorMessage($errorMsg)
	{
		// 1. 嘗試解碼 Unicode Escape (例如 \u55ae\u64da)
		if (strpos($errorMsg, '\u') !== false) {
			$decoded = json_decode('"' . $errorMsg . '"');
			if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
				$errorMsg = $decoded;
			}
		}

		// 2. 替換 [XXXXXXXX] 格式的單據號碼為 [XXXX]
		$normalized = preg_replace('/\\[[0-9]+\\]/', '[XXXX]', $errorMsg);

		// 3. 替換日期格式 YYYY-MM-DD 或 YYYYMMDD
		$normalized = preg_replace('/\\d{4}-\\d{2}-\\d{2}/', 'YYYY-MM-DD', $normalized);
		$normalized = preg_replace('/\\b\\d{8}\\b/', 'YYYYMMDD', $normalized);

		// 4. 替換時間格式 HH:MM:SS
		return preg_replace('/\\d{2}:\\d{2}:\\d{2}/', 'HH:MM:SS', $normalized);
	}
}
