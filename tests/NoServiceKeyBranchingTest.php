<?php
/**
 * D4 機制檢查：核心元件不得對 serviceKey 做分支
 *
 * design.md 的 D4「硬性規則：核心元件不得對 serviceKey 做分支」目前
 * 沒有強制機制，這是唯一的自動化檢查：對核心檔案的原始碼做字串
 * 掃描，確保不出現任何服務別/機器別的字面字串。
 *
 * 檔案清單以 file_exists 容錯：DailyReportGenerator/
 * GoogleChatServiceMessageFormatter 在較早的實作階段可能尚未建立，
 * 屆時會被略過；一旦建立，本測試自動涵蓋，不需要再修改此檔案。
 */

namespace Notifier\Tests;

use PHPUnit_Framework_TestCase;

class NoServiceKeyBranchingTest extends PHPUnit_Framework_TestCase
{
	const FORBIDDEN_SUBSTRINGS = ['apache', 'mysql', 'lampp'];

	public function testCoreComponentsDoNotReferenceSpecificServiceNames()
	{
		$root = dirname(__DIR__) . '/src/ServiceMonitor/';
		$coreFiles = [
			'ServiceMonitor.php',
			'MonitorStateRepository.php',
			'MonitorLogRepository.php',
			'IncidentManager.php',
			'DailyReportGenerator.php',
			'GoogleChatServiceMessageFormatter.php',
		];

		$checkedAtLeastOne = false;

		foreach ($coreFiles as $fileName) {
			$path = $root . $fileName;

			if (!file_exists($path)) {
				continue;
			}

			$checkedAtLeastOne = true;
			$source = strtolower(file_get_contents($path));

			foreach (self::FORBIDDEN_SUBSTRINGS as $forbidden) {
				$this->assertNotContains(
					$forbidden,
					$source,
					"{$fileName} 不得包含服務別/機器別字面字串 '{$forbidden}'（違反 design.md D4）"
				);
			}
		}

		$this->assertTrue($checkedAtLeastOne, '至少要有一個核心檔案存在才能執行這項檢查');
	}
}
