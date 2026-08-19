<?php
/**
 * cURL HTTP 客戶端（production adapter）
 *
 * HttpClientInterface 的正式實作，是整個 ServiceMonitor 子系統中
 * 唯一允許呼叫 curl_* 函式的類別。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class CurlHttpClient implements HttpClientInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function get($url, array $options = [])
	{
		$connectTimeout = isset($options['connectTimeout']) ? (int)$options['connectTimeout'] : 5;
		$requestTimeout = isset($options['requestTimeout']) ? (int)$options['requestTimeout'] : 10;
		$hostHeader = isset($options['hostHeader']) ? $options['hostHeader'] : '';
		$verifySsl = isset($options['verifySsl']) ? (bool)$options['verifySsl'] : true;

		$startedAt = microtime(true);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

		if (!empty($hostHeader)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $hostHeader]);
		}

		$body = curl_exec($ch);
		$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		$latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

		if ($body === false || !empty($curlError)) {
			return [
				'ok'         => false,
				'httpStatus' => null,
				'body'       => null,
				'error'      => !empty($curlError) ? $curlError : '未知的 cURL 錯誤',
				'latencyMs'  => $latencyMs,
			];
		}

		return [
			'ok'         => true,
			'httpStatus' => (int)$httpStatus,
			'body'       => $body,
			'error'      => null,
			'latencyMs'  => $latencyMs,
		];
	}
}
