<?php
/**
 * ServiceCheckerInterface 測試替身，check() 一律拋出例外
 *
 * 用於測試 ServiceMonitor 不因單一 checker 拋出例外而中止整批檢查。
 */

namespace Notifier\Tests\Fakes;

use Notifier\ServiceMonitor\ServiceCheckerInterface;

class FakeThrowingServiceChecker implements ServiceCheckerInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function check()
	{
		throw new \RuntimeException('boom');
	}
}
