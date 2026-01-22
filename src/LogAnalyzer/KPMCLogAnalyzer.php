<?php
/**
 * KPMC 專案 Log 分析器
 *
 * 分析 KPMC 專案（如 zdnServiceKPMC）的 log 格式。
 * 特徵：「本次執行總共取得 X 筆資料」、「銷售人員: XXX」
 * 語法相容 PHP 5.6。
 *
 * @package Notifier\LogAnalyzer
 */

namespace Notifier\LogAnalyzer;

use Notifier\LogAnalyzer;

class KPMCLogAnalyzer extends LogAnalyzer
{
	// 繼承 LogAnalyzer 的預設實作 (KPMC 格式邏輯)
}
