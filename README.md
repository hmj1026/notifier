# Notification Service

通用通知服務框架，提供可重複使用的通知發送機制。支援 Google Chat Webhook，相容 PHP 5.6。

## 功能特色

- ✅ **解耦設計**：不修改現有核心程式
- ✅ **可擴展**：抽象介面設計，便於新增通知渠道
- ✅ **PHP 5.6 相容**：支援舊系統環境
- ✅ **PSR-4 Autoload**：符合現代 PHP 專案規範
- ✅ **PHPUnit 5.7**：完整單元測試覆蓋

---

## 系統需求

- PHP 5.6 或以上
- cURL 擴展 (php-curl)
- Composer (建議)

---

## 快速開始

### 1. 安裝

```bash
# 複製專案
git clone https://your-repo/notifier.git
cd notifier

# 安裝依賴 (建議)
composer install

# 設定環境變數
cp .env.example .env
vim .env
```

### 2. 執行測試

```bash
# 使用 Composer script
composer test

# 或直接執行 PHPUnit
vendor/bin/phpunit
```

### 3. 發送通知

**單一排程：**
```bash
php notifyResult.php /path/to/project
```

**多排程監控：**
```bash
# 指定不同的 Log 檔名和任務顯示名稱
php notifyResult.php /path/to/project "" postOrder.log "訂單上傳"
php notifyResult.php /path/to/project "" getAllocate.log "配額取得"
```

---

## 專案結構

```
notifier/
├── src/                      # 核心類別 (PSR-4: Notifier\)
│   ├── Notifier.php          # 抽象基底類別
│   ├── LogAnalyzer.php       # Log 分析器
│   ├── utility.php           # 工具函式
│   └── Notifier/
│       └── GoogleChatNotifier.php  # Google Chat 實作
├── tests/                    # 單元測試
│   ├── bootstrap.php
│   ├── LogAnalyzerTest.php
│   └── GoogleChatNotifierTest.php
├── docs/                     # 文件
│   └── USAGE.md              # 詳細使用說明
├── notifyResult.php          # 入口程式
├── phpunit.xml               # PHPUnit 配置
├── composer.json             # Composer 配置
├── .env.example              # 環境變數範本
├── .gitignore                # Git 忽略檔案
└── README.md                 # 本文件
```

---

## 環境變數

| 變數名稱 | 說明 | 預設值 |
|---------|------|--------|
| `NOTIFY_ENABLED` | 是否啟用通知 | `false` |
| `NOTIFY_CHANNEL` | 通知渠道 | `google_chat` |
| `NOTIFY_STRATEGY` | 通知策略 (all/failure_only) | `all` |
| `GOOGLE_CHAT_WEBHOOK` | Webhook URL | - |
| `LOG_DIRECTORY` | Log 目錄 | `log` |
| `LOG_FILENAME` | Log 檔名 | `SendDelivery.log` |
| `NOTIFY_TIMEOUT` | HTTP 超時 (秒) | `30` |
| `DEBUG_MODE` | 除錯模式 | `false` |

---

## 基本用法

```php
<?php
require_once 'vendor/autoload.php';

use Notifier\LogAnalyzer;
use Notifier\Notifier\GoogleChatNotifier;

// 1. 分析 Log
$analyzer = new LogAnalyzer();
$analysis = $analyzer->analyze($logContent, $logPath);

// 2. 發送通知
$notifier = new GoogleChatNotifier($webhookUrl);
$message = $notifier->formatMessage($analysis);
$result = $notifier->send($message, $analysis['success']);
```

---

## 文件

- [詳細使用說明](docs/USAGE.md) - 架構說明、部署步驟、整合指南

---

## Docker 開發環境

整合 [docker_run](../docker_run) 開發環境：

```bash
# 1. 設定 .env
# NOTIFIER_PATH=E:/projects/notifier

# 2. 重建容器
docker-compose down && docker-compose up -d --build

# 3. 執行測試
docker-compose exec php bash -c "cd /var/www/notifier && phpunit"
```

---

## 授權

MIT License
