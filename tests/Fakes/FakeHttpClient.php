<?php
/**
 * HttpClientInterface 測試替身
 *
 * 依 queueResponse() 呼叫順序回放預錄回應，讓 checker 測試不需要真實網路。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\HttpClientInterface;

class FakeHttpClient implements HttpClientInterface
{
	/**
	 * 依呼叫順序排列的預錄回應佇列
	 *
	 * @var array
	 */
	private $responses = [];

	/**
	 * 記錄每次呼叫的 url/options，供測試斷言
	 *
	 * @var array
	 */
	private $calls = [];

	/**
	 * 排入下一次 get() 呼叫要回傳的回應
	 *
	 * @param array $response
	 *
	 * @return void
	 */
	public function queueResponse(array $response)
	{
		$this->responses[] = $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get($url, array $options = [])
	{
		$this->calls[] = ['url' => $url, 'options' => $options];

		if (empty($this->responses)) {
			return [
				'ok'         => false,
				'httpStatus' => null,
				'body'       => null,
				'error'      => 'FakeHttpClient：沒有預先排入的回應',
				'latencyMs'  => 0,
			];
		}

		return array_shift($this->responses);
	}

	/**
	 * 取得所有已記錄的呼叫
	 *
	 * @return array
	 */
	public function getCalls()
	{
		return $this->calls;
	}
}
