<?php
/**
 * Log 分析器
 *
 * 分析 log 檔案內容，判斷執行成功或失敗，並提取關鍵資訊。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier
 */

namespace Notifier;

class LogAnalyzer
{
	/**
	 * 失敗模式清單
	 *
	 * @var array
	 */
	private $failurePatterns;

	/**
	 * 建構子
	 */
	public function __construct()
	{
		// 預設失敗模式 (適用於 KPMC 格式)
		$this->failurePatterns = [
			[
				'pattern'   => '/無法取得.*結構, 中斷執行/',
				'errorType' => 'WebService 結構取得失敗',
				'errorHint' => '請檢查 WebService 連線或服務狀態',
			],
			[
				'pattern'   => '/SoapFault/',
				'errorType' => 'SOAP 通訊錯誤',
				'errorHint' => '請檢查網路連線或 WebService 服務',
			],
			[
				'pattern'   => '/Error Fetching http headers/',
				'errorType' => 'HTTP 連線失敗',
				'errorHint' => 'WebService 連線逾時或服務不可用',
			],
			[
				'pattern'   => '/Exception:/',
				'errorType' => 'PHP 例外',
				'errorHint' => '請查看 log 詳細內容',
			],
			[
				'pattern'   => '/Upload Failed/',
				'errorType' => '上傳失敗',
				'errorHint' => '請檢查資料格式或 WebService 回應',
			],
		];
	}

	/**
	 * 分析 Log 內容
	 * 預設實作 KPMC 格式分析邏輯 (為了向下相容)
	 *
	 * @param string $content     Log 內容
	 * @param string $logPath     Log 檔案路徑
	 * @param string $projectName 專案/任務名稱
	 *
	 * @return array 分析結果
	 */
	public function analyze($content, $logPath, $projectName = '')
	{
		$result = [
			'success'          => true,
			'recordsProcessed' => 0,
			'personIds'        => [],
			'errorType'        => '',
			'errorHint'        => '',
			'logPath'          => $logPath,
			'projectName'      => $projectName,
		];

		// 檢查失敗模式
		foreach ($this->getFailurePatterns() as $pattern) {
			if (preg_match($pattern['pattern'], $content)) {
				$result['success'] = false;
				$result['errorType'] = $pattern['errorType'];
				$result['errorHint'] = $pattern['errorHint'];
				break;
			}
		}

		// 提取處理筆數
		if (preg_match('/本次執行總共取得 (\d+) 筆資料/', $content, $matches)) {
			$result['recordsProcessed'] = (int)$matches[1];
		}

		// 提取銷售人員 (去重複)
		if (preg_match_all('/銷售人員: (\w+)/', $content, $matches)) {
			$result['personIds'] = array_values(array_unique($matches[1]));
		}

		return $result;
	}

	/**
	 * 新增自訂失敗模式
	 *
	 * @param string $pattern   正則表達式模式
	 * @param string $errorType 錯誤類型
	 * @param string $errorHint 錯誤提示
	 *
	 * @return void
	 */
	public function addFailurePattern($pattern, $errorType, $errorHint)
	{
		$this->failurePatterns[] = [
			'pattern'   => $pattern,
			'errorType' => $errorType,
			'errorHint' => $errorHint,
		];
	}

	/**
	 * 取得所有失敗模式
	 *
	 * @return array
	 */
	public function getFailurePatterns()
	{
		return $this->failurePatterns;
	}

	/**
	 * 建立 Log 分析器（工廠方法）
	 *
	 * @param string $formatType 格式類型 (kpmc 或 ane072)
	 *
	 * @return LogAnalyzer 分析器實例
	 */
	public static function create($formatType = 'kpmc')
	{
		switch ($formatType) {
			case 'ane072':
				return new LogAnalyzer\ANE072LogAnalyzer();
			case 'kpmc':
				return new LogAnalyzer\KPMCLogAnalyzer();
			default:
				echo "不支援的 Log 格式: " . $formatType . PHP_EOL;

				return null;
		}
	}
}

