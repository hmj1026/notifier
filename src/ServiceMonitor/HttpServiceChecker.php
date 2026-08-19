<?php
/**
 * 通用 http 型別健康檢查
 *
 * 透過注入的 HttpClientInterface 發送 GET 請求，驗證狀態碼範圍與
 * 可選的回應內容比對。判定邏輯本身不觸碰網路，只依賴注入結果，
 * 因此可用假的 HttpClientInterface 單元測試。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class HttpServiceChecker extends AbstractServiceChecker
{
	const METHOD = 'http';

	/**
	 * 支援的設定鍵：url、hostHeader、expectedBodyContains、
	 * minStatus（預設 200）、maxStatus（預設 399）、
	 * connectTimeout（預設 5）、requestTimeout（預設 10）、verifySsl（預設 true）
	 *
	 * @var array
	 */
	private $config;

	/**
	 * @var HttpClientInterface
	 */
	private $httpClient;

	/**
	 * @param string               $serviceKey
	 * @param array                $config
	 * @param HttpClientInterface  $httpClient
	 * @param ClockInterface       $clock
	 */
	public function __construct($serviceKey, array $config, HttpClientInterface $httpClient, ClockInterface $clock)
	{
		parent::__construct($serviceKey, isset($config['label']) ? $config['label'] : $serviceKey, $clock);
		$this->config = $config;
		$this->httpClient = $httpClient;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		$url = isset($this->config['url']) ? $this->config['url'] : '';

		if (empty($url)) {
			return $this->buildResult('unknown', self::METHOD, 0, '設定錯誤：未設定健康檢查 URL', [], ['error' => 'missing url']);
		}

		$minStatus = isset($this->config['minStatus']) ? (int)$this->config['minStatus'] : 200;
		$maxStatus = isset($this->config['maxStatus']) ? (int)$this->config['maxStatus'] : 399;
		$expectedBodyContains = isset($this->config['expectedBodyContains']) ? $this->config['expectedBodyContains'] : '';
		$hostHeader = isset($this->config['hostHeader']) ? $this->config['hostHeader'] : '';
		$verifySsl = isset($this->config['verifySsl']) ? (bool)$this->config['verifySsl'] : true;

		$response = $this->httpClient->get($url, [
			'connectTimeout' => isset($this->config['connectTimeout']) ? (int)$this->config['connectTimeout'] : 5,
			'requestTimeout' => isset($this->config['requestTimeout']) ? (int)$this->config['requestTimeout'] : 10,
			'hostHeader'     => $hostHeader,
			'verifySsl'      => $verifySsl,
		]);

		$latencyMs = isset($response['latencyMs']) ? (int)$response['latencyMs'] : 0;

		if (empty($response['ok'])) {
			$reason = !empty($response['error']) ? $response['error'] : '未知錯誤';

			return $this->buildResult('unknown', self::METHOD, $latencyMs, "未取得 HTTP 回應：{$reason}", [], []);
		}

		$httpStatus = (int)$response['httpStatus'];
		$body = isset($response['body']) ? $response['body'] : '';

		if ($httpStatus < $minStatus || $httpStatus > $maxStatus) {
			return $this->buildResult(
				'unhealthy',
				self::METHOD,
				$latencyMs,
				"HTTP 狀態碼 {$httpStatus} 不在預期範圍 [{$minStatus}-{$maxStatus}]",
				['httpStatus' => $httpStatus]
			);
		}

		if (!empty($expectedBodyContains) && strpos($body, $expectedBodyContains) === false) {
			return $this->buildResult(
				'unhealthy',
				self::METHOD,
				$latencyMs,
				'HTTP 回應內容不包含預期字串',
				['httpStatus' => $httpStatus]
			);
		}

		return $this->buildResult('healthy', self::METHOD, $latencyMs, 'HTTP 健康檢查通過', ['httpStatus' => $httpStatus]);
	}
}
