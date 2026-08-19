<?php
/**
 * Apache（LAMPP）health checker
 *
 * http 型別的 LAMPP 預設配置，不是新的抽象層：內部組合一個
 * HttpServiceChecker 處理實際健康判定，並可選擇性組合
 * DiagnosticProviderInterface（如 lampp status）附加診斷資訊到
 * 結果的 diagnostic 欄位。orchestrator 完全不需要知道 LAMPP 的存在。
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\ServiceMonitor
 */

namespace Notifier\ServiceMonitor;

class ApacheServiceChecker implements ServiceCheckerInterface
{
	/**
	 * @var HttpServiceChecker
	 */
	private $httpChecker;

	/**
	 * @var DiagnosticProviderInterface|null
	 */
	private $diagnosticProvider;

	/**
	 * @param string                          $serviceKey
	 * @param array                           $config             HttpServiceChecker 相容的設定，url 預設 http://127.0.0.1/
	 * @param HttpClientInterface             $httpClient
	 * @param ClockInterface                  $clock
	 * @param DiagnosticProviderInterface|null $diagnosticProvider 選填，附加額外診斷資訊（如 lampp status）
	 */
	public function __construct(
		$serviceKey,
		array $config,
		HttpClientInterface $httpClient,
		ClockInterface $clock,
		DiagnosticProviderInterface $diagnosticProvider = null
	) {
		$defaults = [
			'url'       => 'http://127.0.0.1/',
			'minStatus' => 200,
			'maxStatus' => 399,
		];

		$this->httpChecker = new HttpServiceChecker($serviceKey, array_merge($defaults, $config), $httpClient, $clock);
		$this->diagnosticProvider = $diagnosticProvider;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		$result = $this->httpChecker->check();

		if ($this->diagnosticProvider !== null) {
			$result['diagnostic'] = $this->diagnosticProvider->diagnose();
		}

		return $result;
	}
}
