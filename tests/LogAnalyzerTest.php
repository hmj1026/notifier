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
		$this->analyzer = new LogAnalyzer();
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
}
