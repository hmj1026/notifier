<?php
/**
 * CurlHttpClient 單元測試
 *
 * 只驗證連線失敗路徑（不依賴任何外部網路服務，僅打本機一個
 * 保證無人監聽的連接埠，確保測試快速且具決定性）。
 */

namespace Notifier\Tests;

use Notifier\ServiceMonitor\CurlHttpClient;
use PHPUnit_Framework_TestCase;

class CurlHttpClientTest extends PHPUnit_Framework_TestCase
{
	/**
	 * 測試連線被拒絕時回傳明確失敗結果，而非拋出例外
	 */
	public function testGetReturnsFailureWhenConnectionRefused()
	{
		$client = new CurlHttpClient();

		$result = $client->get('http://127.0.0.1:1/', ['connectTimeout' => 2, 'requestTimeout' => 2]);

		$this->assertFalse($result['ok']);
		$this->assertNull($result['httpStatus']);
		$this->assertNotEmpty($result['error']);
	}
}
