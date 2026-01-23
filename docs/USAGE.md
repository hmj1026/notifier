# Notification Service 使用說明

本文件提供 Notification Service 的完整使用指南，包含架構說明、部署步驟與整合方式。

---

## 目錄

1. [專案架構](#專案架構)
2. [核心元件說明](#核心元件說明)
3. [部署步驟](#部署步驟)
4. [整合至其他服務](#整合至其他服務)
5. [測試驗證](#測試驗證)
6. [常見問題](#常見問題)

---

## 專案架構

### 目錄結構

```
notifier/
├── src/                          # 核心程式碼 (PSR-4 命名空間: Notifier\)
│   ├── Notifier.php              # 抽象基底類別
│   ├── LogAnalyzer.php           # Log 分析器 (Factory)
│   ├── utility.php               # 工具函式
│   ├── Notifier/
│   │   └── GoogleChatNotifier.php  # Google Chat Webhook 實作
│   └── LogAnalyzer/
│       ├── KPMCLogAnalyzer.php     # KPMC 格式實作
│       └── ANE072LogAnalyzer.php   # ANE072 格式實作
├── tests/                        # 單元測試
│   ├── bootstrap.php             # 測試啟動檔
│   ├── LogAnalyzerTest.php       # LogAnalyzer 測試
│   └── GoogleChatNotifierTest.php  # GoogleChatNotifier 測試
├── docs/                         # 文件
├── openspec/                     # OpenSpec 規格文件
├── notifyResult.php              # 入口程式
├── phpunit.xml                   # PHPUnit 配置
├── composer.json                 # Composer 配置
└── .env.example                  # 環境變數範本
```

### 命名空間對應

| 命名空間 | 目錄 | 說明 |
|---------|------|------|
| `Notifier\` | `src/` | 核心類別 |
| `Notifier\Notifier\` | `src/Notifier/` | 具體通知器實作 |
| `Notifier\LogAnalyzer\` | `src/LogAnalyzer/` | Log 分析器實作 |
| `Notifier\Tests\` | `tests/` | 單元測試 |

---

## 核心元件說明

### 1. Notifier (抽象基底類別)

定義通知器的標準介面，所有通知器實作必須繼承此類別。

```php
namespace Notifier;

abstract class Notifier
{
    abstract public function send($message, $isSuccess);
    abstract public function formatMessage($analysis);
}
```

### 2. LogAnalyzer (Log 分析器)
 
分析 log 檔案內容，判斷執行結果。此類別實作了 Factory Pattern，根據不同專案格式產生對應的分析器。
 
**支援格式：**
 
- `kpmc` (預設)：適用於 KPMC 專案
- `ane072`：適用於 ANE072 專案 (支援聚合統計)
 
**基本用法：**
 
```php
// 使用 Factory 建立分析器
$analyzer = LogAnalyzer::create('kpmc'); // 或 'ane072'
 
// 自訂失敗模式 (僅 KPMC 支援)
$analyzer->addFailurePattern(
    '/Custom Error/',
    '自訂錯誤',
    '自訂提示訊息'
);
```
 
**預設失敗模式 (KPMC)：**
 
| 關鍵字 | 錯誤類型 |
|--------|---------|
| `無法取得.*結構, 中斷執行` | WebService 結構取得失敗 |
| `SoapFault` | SOAP 通訊錯誤 |
| `Error Fetching http headers` | HTTP 連線失敗 |
| `Exception:` | PHP 例外 |
| `Upload Failed` | 上傳失敗 |

### 3. GoogleChatNotifier

透過 Webhook 發送通知至 Google Chat。

```php
$notifier = new GoogleChatNotifier(
    $webhookUrl,  // Webhook URL
    true,         // 是否啟用
    30            // HTTP 超時 (秒)
);
```

---

## 部署步驟

### 方法一：使用 Composer (建議)

```bash
# 1. 複製專案
git clone https://your-repo/notifier.git /var/www/notifier
cd /var/www/notifier

# 2. 安裝依賴
composer install --no-dev --optimize-autoloader

# 3. 設定環境變數
cp .env.example .env
vim .env
```

### 方法二：不使用 Composer

入口程式 `notifyResult.php` 支援降級模式，會自動載入類別：

```bash
# 1. 複製專案
git clone https://your-repo/notifier.git /var/www/notifier

# 2. 設定環境變數
cp .env.example .env
vim .env

# 3. 直接執行
php notifyResult.php /var/www/notifier
```

### 環境變數設定

編輯 `.env` 檔案：

```ini
# 啟用通知
NOTIFY_ENABLED=true

# Google Chat Webhook URL
GOOGLE_CHAT_WEBHOOK=https://chat.googleapis.com/v1/spaces/XXX/messages?key=XXX&token=XXX

# 通知策略
NOTIFY_STRATEGY=all

# Log 設定
LOG_DIRECTORY=log
LOG_FILENAME=SendDelivery.log
```

### 設定 Google Chat Webhook

1. 開啟 Google Chat
2. 進入目標空間 → 點擊空間名稱
3. 選擇「管理 Webhook」→「新增 Webhook」
4. 輸入名稱，複製產生的 URL
5. 填入 `.env` 的 `GOOGLE_CHAT_WEBHOOK`

---

## 整合至其他服務

### 步驟一：複製必要檔案

將以下檔案複製至目標服務：

```
src/
├── Notifier.php
├── LogAnalyzer.php
├── utility.php
└── Notifier/
    └── GoogleChatNotifier.php
notifyResult.php
.env.example
```

### 步驟二：設定環境變數

在目標服務根目錄建立 `.env`：

```ini
NOTIFY_ENABLED=true
GOOGLE_CHAT_WEBHOOK=https://chat.googleapis.com/v1/spaces/...
NOTIFY_STRATEGY=all
LOG_DIRECTORY=log
LOG_FILENAME=SendDelivery.log
```

### 步驟三：修改排程腳本

在 shell 腳本最後加入：

```bash
# sendDelivery.sh 末尾
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

### 步驟四：程式化呼叫 (選用)

```php
<?php
// 使用 Composer autoload
require_once 'vendor/autoload.php';

// 或直接載入
require_once 'src/utility.php';
require_once 'src/Notifier.php';
require_once 'src/LogAnalyzer.php';
require_once 'src/Notifier/GoogleChatNotifier.php';

use Notifier\LogAnalyzer;
use Notifier\Notifier\GoogleChatNotifier;
use function Notifier\readIniFile;
use function Notifier\getConfig;

// 分析 Log
// 參數 1: Log 內容
// 參數 2: Log 路徑 (用於顯示)
// 參數 3: 專案名稱 (用於顯示)
$analyzer = LogAnalyzer::create('kpmc');
$analysis = $analyzer->analyze($logContent, 'log/test.log', 'TaskName');

// 發送通知
$notifier = new GoogleChatNotifier($webhookUrl);
$message = $notifier->formatMessage($analysis);
$notifier->send($message, $analysis['success']);
```

---

## 測試驗證

### 執行單元測試

```bash
# 使用 Composer script
composer test

# 或直接執行
vendor/bin/phpunit

# 在 Docker 環境
docker-compose exec php bash -c "cd /var/www/notifier && phpunit"
```

**預期結果：**

```
PHPUnit 5.7.27 by Sebastian Bergmann and contributors.

.............                                                     13 / 13 (100%)

Time: 73 ms, Memory: 12.75MB

OK (13 tests, 33 assertions)
```

### 驗證 PHP 語法

```bash
php -l src/Notifier.php
php -l src/LogAnalyzer.php
php -l src/Notifier/GoogleChatNotifier.php
php -l notifyResult.php
```

### 手動測試通知

```bash
# 1. 建立測試 log
mkdir -p log/$(date +%Y)/$(date +%m)/$(date +%d)
echo "本次執行總共取得 5 筆資料" > log/$(date +%Y/%m/%d)/SendDelivery.log

# 2. 設定 .env (填入真實 Webhook URL)

# 3. 執行通知
php notifyResult.php .

# 4. 檢查 Google Chat 空間
```

---

## 常見問題

### Q: 通知發送失敗

**檢查步驟：**

1. 確認 `NOTIFY_ENABLED=true`
2. 確認 `GOOGLE_CHAT_WEBHOOK` URL 正確
3. 啟用 `DEBUG_MODE=true` 查看詳細訊息
4. 檢查網路連線與防火牆

### Q: Log 檔案不存在

**解決方式：**

- 確認 `LOG_DIRECTORY` 路徑正確
- 確認 `LOG_FILENAME` 檔名正確
- 檢查 Log 日期目錄結構 (`log/YYYY/MM/DD/`)

### Q: Composer 安裝失敗

**解決方式：**

入口程式支援降級模式，可直接使用：

```bash
php notifyResult.php /path/to/project
```

### Q: 如何新增其他通知渠道？

1. 建立新類別繼承 `Notifier\Notifier`
2. 實作 `send()` 和 `formatMessage()` 方法
3. 在 `notifyResult.php` 中加入渠道選擇邏輯

**範例：**

```php
namespace Notifier\Notifier;

use Notifier\Notifier as BaseNotifier;

class SlackNotifier extends BaseNotifier
{
    public function send($message, $isSuccess) { /* ... */ }
    public function formatMessage($analysis) { /* ... */ }
}
```

---