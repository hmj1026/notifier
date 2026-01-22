<?php
/**
 * LogAnalyzer 單元測試
 *
 * 測試 Log 分析器的各種情境
 * 支援 PHPUnit 5.7+
 */

namespace Notifier\Tests;

use Notifier\LogAnalyzer;
use PHPUnit_Framework_TestCase;

class LogAnalyzerTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @var LogAnalyzer
	 */
	private $analyzer;

	protected function setUp()
	{
		// 使用 KPMC 格式分析器
		$this->analyzer = new \Notifier\LogAnalyzer\KPMCLogAnalyzer();
	}

	/**
	 * 測試偵測成功執行
	 */
	public function testAnalyzeSuccessfulLog()
	{
		$logContent = "
============ Get Receipt Start ============
本次執行總共取得 13 筆資料
銷售人員: 70013
銷售人員: 00073
============ Get Receipt End ============
		";

		$result = $this->analyzer->analyze($logContent, 'log/2026/01/22/SendDelivery.log');

		$this->assertTrue($result['success']);
		$this->assertEquals(13, $result['recordsProcessed']);
		$this->assertContains('70013', $result['personIds']);
		$this->assertContains('00073', $result['personIds']);
		$this->assertEquals('log/2026/01/22/SendDelivery.log', $result['logPath']);
	}

	/**
	 * 測試偵測 SoapFault 錯誤
	 */
	public function testAnalyzeSoapFaultError()
	{
		$logContent = "
============ Get Receipt Start ============
SoapFault: Error Fetching http headers
		";

		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		$this->assertFalse($result['success']);
		$this->assertEquals('SOAP 通訊錯誤', $result['errorType']);
		$this->assertNotEmpty($result['errorHint']);
	}

	/**
	 * 測試偵測 HTTP 連線失敗
	 */
	public function testAnalyzeHttpConnectionError()
	{
		$logContent = "
Error Fetching http headers
Connection timeout
		";

		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		$this->assertFalse($result['success']);
		$this->assertEquals('HTTP 連線失敗', $result['errorType']);
	}

	/**
	 * 測試偵測 WebService 結構取得失敗
	 */
	public function testAnalyzeWebServiceStructureError()
	{
		$logContent = "
無法取得Delivery Order結構, 中斷執行
		";

		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		$this->assertFalse($result['success']);
		$this->assertEquals('WebService 結構取得失敗', $result['errorType']);
	}

	/**
	 * 測試提取處理筆數
	 */
	public function testExtractRecordsProcessed()
	{
		$logContent = "本次執行總共取得 25 筆資料";

		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		$this->assertEquals(25, $result['recordsProcessed']);
	}

	/**
	 * 測試提取銷售人員 (多個)
	 */
	public function testExtractMultiplePersonIds()
	{
		$logContent = "
銷售人員: 70013
銷售人員: 00073
銷售人員: 12345
銷售人員: 70013
		";

		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		// 應去除重複
		$this->assertCount(3, $result['personIds']);
	}

	/**
	 * 測試空 log 內容
	 */
	public function testAnalyzeEmptyLog()
	{
		$result = $this->analyzer->analyze('', 'log/test.log');

		// 空內容不符合成功條件，也不符合失敗模式
		$this->assertTrue($result['success']);
		$this->assertEquals(0, $result['recordsProcessed']);
	}

	/**
	 * 測試自訂失敗模式
	 */
	public function testAddCustomFailurePattern()
	{
		$this->analyzer->addFailurePattern(
			'/Custom Error Pattern/',
			'自訂錯誤',
			'這是自訂錯誤提示'
		);

		$logContent = "Custom Error Pattern occurred";
		$result = $this->analyzer->analyze($logContent, 'log/test.log');

		$this->assertFalse($result['success']);
		$this->assertEquals('自訂錯誤', $result['errorType']);
		$this->assertEquals('這是自訂錯誤提示', $result['errorHint']);
	}

	/**
	 * 測試 ANE072 格式：成功執行（有 End 標記）
	 */
	public function testAnalyzeANE072SuccessWithEnd()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = "
============ Get Order Start (2026-01-22 09:53:01) ============
SELECT * FROM orders
Status: True ErrorMsg: 
Status: True ErrorMsg: 
============ Delete Order End (2026-01-22 10:00:15) ============
		";

		$result = $analyzer->analyze($logContent, 'log/2026/01/22/postOrder.log', '訂單上傳');

		$this->assertTrue($result['success']);
		$this->assertEquals('訂單上傳', $result['projectName']);
		$this->assertEquals(2, $result['successCount']);
	}

	/**
	 * 測試 ANE072 格式：無 End 標記（異常中斷）
	 */
	public function testAnalyzeANE072MissingEnd()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = "
============ Get Order Start (2026-01-22 09:53:01) ============
SELECT * FROM orders
Processing...
		";

		$result = $analyzer->analyze($logContent, 'log/test.log');

		$this->assertFalse($result['success']);
		$this->assertEquals('程式異常中斷', $result['errorType']);
		$this->assertContains('Log 未正常結束', $result['errorHint']);
	}

	/**
	 * 測試 ANE072 格式：Status False（API 失敗）
	 */
	public function testAnalyzeANE072StatusFalse()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = "
============ Get Receipt Start ============
Status: False ErrorMsg: API 連線失敗
Status: True ErrorMsg: 
============ Get Post ERPData End ============
		";

		$result = $analyzer->analyze($logContent, 'log/test.log');

		// 有處理資料就算成功（即使有部分失敗）
		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['successCount']);
		$this->assertEquals(1, $result['failureCount']);
	}

	/**
	 * 測試 ANE072 格式：IfSucceed False
	 */
	public function testAnalyzeANE072IfSucceedFalse()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = '
============ Start ============
response: {"result":[{"IfSucceed":"False","ErrMethodName":"Test","ErrMessage":"錯誤訊息","IfBiz":"True"}]}
response: {"result":[{"IfSucceed":"True","ErrMethodName":"","ErrMessage":"","IfBiz":"True"}]}
============ End ============
		';

		$result = $analyzer->analyze($logContent, 'log/test.log');

		// 有處理資料就算成功
		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['successCount']);
		$this->assertEquals(1, $result['failureCount']);
	}
	/**
	 * 測試 ANE072 格式：getItem (更新成功格式)
	 */
	public function testAnalyzeANE072GetItemFormat()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = "
============ GetItemData Start (2026-01-22 01:00:02) ============
第 638 個商品[SFP-TBCJ0001] 更新: 成功!
第 639 個商品[SFP-TBOB0001] 更新: 成功!
總共新增 0 筆。
總共更新 649 筆。
============ Update ClassNo End  (2026-01-22 11:17:45) ============
		";

		$result = $analyzer->analyze($logContent, 'log/2026/01/22/getItem.log', '商品同步');

		$this->assertTrue($result['success']);
		$this->assertEquals(2, $result['successCount']); // 兩行「更新: 成功!」 matches
		$this->assertEquals(0, $result['failureCount']);
	}

	/**
	 * 測試 ANE072 格式：getAllocate (資料列表格式)
	 */
	public function testAnalyzeANE072GetAllocateFormat()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		$logContent = "
============ GetAllocate Start (2026-01-22 01:10:01) ============
2026-01-22 01:10:01撈回調撥資料：
  20231204002  20231204  王英如  ...
  20240514005  20240514  王英如  ...
============ Update Allocate End ============
		";

		$result = $analyzer->analyze($logContent, 'log/2026/01/22/getAllocate.log', '調撥資料');

		$this->assertTrue($result['success']);
		$this->assertEquals(2, $result['recordsProcessed']);
		$this->assertEquals(2, $result['successCount']);
	}
	/**
	 * 測試 ANE072 格式：Unicode 解碼
	 */
	public function testAnalyzeANE072UnicodeDecoding()
	{
		$analyzer = new \Notifier\LogAnalyzer\ANE072LogAnalyzer();
		// 模擬包含 Unicode escape 的 log
		// \u55ae\u64da = 單據
		$logContent = "
============ Post Order Start ============
Status: False ErrorMsg: \u55ae\u64da\u865f\u78bc [12345]\u5df2\u5b58\u5728!
Status: False ErrorMsg: \u55ae\u64da\u865f\u78bc [67890]\u5df2\u5b58\u5728!
============ Post Order End ============
		";

		$result = $analyzer->analyze($logContent, 'test.log', 'ANE072_Job');

		// ANE072 邏輯：只要有處理記錄就算 Job 成功（因為是批次處理）
		$this->assertTrue($result['success']);
		$this->assertEquals(2, $result['failureCount']);
		
		// 驗證是否正確解碼且聚合
		// "單據號碼 [XXXX]已存在!"
		$expectedKey = '單據號碼 [XXXX]已存在!';
		$this->assertArrayHasKey($expectedKey, $result['errorBreakdown']);
		$this->assertEquals(2, $result['errorBreakdown'][$expectedKey]);
	}
}
