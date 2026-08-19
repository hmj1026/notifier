<?php
/**
 * DiagnosticProviderInterface 測試替身
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\DiagnosticProviderInterface;

class FakeDiagnosticProvider implements DiagnosticProviderInterface
{
	/**
	 * @var array
	 */
	private $diagnostic;

	/**
	 * @param array $diagnostic
	 */
	public function __construct(array $diagnostic)
	{
		$this->diagnostic = $diagnostic;
	}

	/**
	 * {@inheritdoc}
	 */
	public function diagnose()
	{
		return $this->diagnostic;
	}
}
