# 服務健康監控 (Service Health Monitoring) 使用說明

`monitorServices.php` 提供通用、可插拔的服務健康監控：定期檢查、異常/恢復通知（透過既有 Google Chat Webhook）、每日監控日報。核心元件完全不知道被監控的服務是什麼——新增一個受監控服務只需要改設定，不需要修改任何 PHP 程式碼。

---

## 目錄

1. [架構總覽](#架構總覽)
2. [部署步驟](#部署步驟)
3. [設定說明](#設定說明)
4. [新增受監控服務](#新增受監控服務)
5. [CLI 用法](#cli-用法)
6. [Cron 排程](#cron-排程)
7. [vm2 LAMPP 部署範例](#vm2-lampp-部署範例)
8. [常見問題](#常見問題)

---

## 架構總覽

```
src/ServiceMonitor/
├── ServiceCheckerInterface.php         # check() 零參數，回傳三態 status（healthy/unhealthy/unknown）
├── HttpClientInterface.php + CurlHttpClient.php       # HTTP true-external port
├── CommandRunnerInterface.php + ShellCommandRunner.php # 外部命令 true-external port
├── ClockInterface.php + SystemClock.php               # 時間 true-external port
├── HttpServiceChecker.php              # 通用 http 型別
├── CommandServiceChecker.php           # 通用 command 型別
├── MySqlServiceChecker.php             # 專用 mysql 型別（ping/query）
├── ApacheServiceChecker.php            # http 型別的 LAMPP 預設配置（薄封裝）
├── DiagnosticProviderInterface.php + LamppStatusDiagnosticProvider.php
├── ServiceCheckerFactory.php           # 批次解析 MONITOR_SERVICES
├── MonitorStateRepository.php / MonitorLogRepository.php  # 檔案式儲存
├── IncidentManager.php                 # 異常/恢復狀態機
├── ServiceMonitor.php                  # orchestrator（整批時間預算、單一服務容錯）
├── DailyReportGenerator.php            # 每日日報統計
├── GoogleChatServiceMessageFormatter.php + GoogleChatNotificationSender.php
monitorServices.php                     # CLI 進入點
```

三種內建 checker 型別涵蓋絕大多數服務：

| 型別 | 適用情境 | 判定依據 |
|------|---------|---------|
| `http` | 任何 HTTP 服務（Apache、Nginx、任意 Web 服務） | 狀態碼範圍 + 可選內容比對 |
| `command` | 任何有健康檢查指令的服務（Redis、自訂腳本） | exit code + 可選輸出比對 |
| `mysql` | MySQL/MariaDB | `mysqladmin ping` 或 `SELECT 1`（defaults-extra-file） |

無法用上述三種型別涵蓋的服務，可透過 `MONITOR_SERVICE_<KEY>_CLASS=` 指定自訂的 `ServiceCheckerInterface` 實作類別（escape hatch）。

**檢查結果三態**：`healthy`（明確正向判定）／`unhealthy`（明確負向判定）／`unknown`（checker 本身沒能取得判定，例如逾時、binary 不存在、設定錯誤）。`unknown` 不等於服務異常，不會觸發告警、不會計入連續異常/恢復次數——避免監控系統自身的暫時性問題被誤判為服務故障。

---

## 部署步驟

服務監控依 [D8 部署獨立性] 設計，**應獨立 clone 部署**，不要與既有 per-service 的 notifier 部署目錄共用（例如不要塞進某個 `zdnServiceXXX/lib/notifier/` 目錄），避免生命週期互相牽連。

```bash
# 1. 獨立 clone
git clone https://your-repo/notifier.git /opt/service-monitor
cd /opt/service-monitor

# 2. 安裝依賴（有 composer 的環境）
composer install --no-dev --optimize-autoloader
# 沒有 composer 的環境可略過此步：monitorServices.php 會自動降級為手動載入類別

# 3. 設定環境變數
cp .env.example .env
vim .env   # 至少設定 MONITOR_SERVICES 與對應服務的設定、GOOGLE_CHAT_WEBHOOK

# 4. 先以 dry-run 確認判定結果與預計發送內容正確，才加入 cron
php monitorServices.php check --dry-run
```

`--dry-run` 會顯示每個服務的判定結果與預計發送的 Cards V2 內容（已遮罩疑似秘密值），但不會實際發送通知、不會更新 `state.json`、不會寫入任何 sent marker。**務必先確認 dry-run 結果正確再啟用 cron**，避免部署瞬間因設定不符實際環境（例如健康檢查 URL 預設值不適用）而發送錯誤告警。

---

## 設定說明

完整變數清單與預設值見 `.env.example` 的「服務健康監控設定」區塊。核心概念：

- **`MONITOR_SERVICES`**：有序、逗號分隔的服務 key 清單，是受監控服務的唯一來源。缺漏或空白會被視為明確設定錯誤（`check` 回傳 exit code 1），不會被靜默當成「沒有服務要監控」。
- **命名空間設定**：每個服務 key 對應 `MONITOR_SERVICE_<KEY>_TYPE`（`http`/`command`/`mysql`）與該型別所需的 `MONITOR_SERVICE_<KEY>_*` 設定。
- **`apache`/`mysql` 扁平命名 fallback**：這兩個 key 未設定命名空間版本時，會退回讀取扁平命名（`APACHE_HEALTHCHECK_URL`、`MYSQLADMIN_PATH` 等），沿用既有 LAMPP 部署慣例的變數名稱，方便從既有規劃直接落地。解析順序：命名空間優先，扁平命名 fallback。
- **單一服務設定錯誤不拖垮整批**：例如某個新加的服務缺少必要參數，該服務的檢查結果會是 `unknown`（`diagnostic` 記錄設定錯誤原因），其餘服務仍正常檢查、記錄、告警。

---

## 新增受監控服務

新增一個 `redis` 服務只需要改 `.env`，不需要動任何 PHP 檔案：

```ini
MONITOR_SERVICES=apache,mysql,redis
MONITOR_SERVICE_REDIS_TYPE=command
MONITOR_SERVICE_REDIS_BINARY_PATH=/usr/bin/redis-cli
MONITOR_SERVICE_REDIS_ARGS=ping
MONITOR_SERVICE_REDIS_EXPECTED_OUTPUT_CONTAINS=PONG
```

`http`/`command`/`mysql` 三種型別各自支援的設定鍵，可參考對應 checker 類別的 PHPDoc（`src/ServiceMonitor/HttpServiceChecker.php`、`CommandServiceChecker.php`、`MySqlServiceChecker.php`）。

---

## CLI 用法

```bash
# 執行一次健康檢查
php monitorServices.php check [--dry-run]

# 產生並發送前一日日報（預設日期為系統時區的昨天）
php monitorServices.php report [--date=YYYY-MM-DD] [--force] [--dry-run]

# 查看每個服務最後一次檢查的狀態（唯讀，即使 SERVICE_MONITOR_ENABLED=false 仍可用）
php monitorServices.php status
```

**exit code：**

| exit code | 意義 |
|-----------|------|
| `0` | 成功且所有服務正常；或日報已成功發送/先前已發送過；或偵測到 process lock 佔用而安全跳過 |
| `1` | 設定/儲存/命令執行/通知系統錯誤 |
| `2` | 健康檢查完成，但至少一個服務目前為 `unhealthy` |

`report` 具備冪等性：同一日期成功發送過後，預設不會重複發送（建立 `storage/service-monitor/reports/YYYY-MM-DD.sent` marker），需要補發時加上 `--force`。

---

## Cron 排程

```cron
CRON_TZ=Asia/Taipei
*/5 * * * * cd /opt/service-monitor && php monitorServices.php check >> storage/service-monitor/logs/cron.log 2>&1
0 9 * * *   cd /opt/service-monitor && php monitorServices.php report >> storage/service-monitor/logs/cron.log 2>&1
```

- `check` 排程間隔應與 `.env` 的 `MONITOR_INTERVAL_MINUTES` 一致。
- `MONITOR_CHECK_TOTAL_BUDGET_SECONDS` 必須小於排程間隔換算的秒數，執行過程受 process lock 保護，即使單次執行意外拖長，下一輪 cron 觸發時也只會安全跳過（exit code 0），不會重疊執行、不會產生重複通知。
- 若排程環境不支援 `CRON_TZ`，請依伺服器實際時區換算 cron 時間，並確認 `.env` 的 `SERVICE_MONITOR_TIMEZONE` 與 `MONITOR_INTERVAL_MINUTES` 的計算基準一致。

**回滾**：移除上述 cron 條目，或將 `.env` 的 `SERVICE_MONITOR_ENABLED` 設為 `false`（`check`/`report` 會直接跳過，`status` 仍可查詢既有資料，監控資料本身保留供事後查閱）。

---

## vm2 LAMPP 部署範例

以下設定基於 2026-08-19 對 vm2（GCP Compute Engine `web-service-2`，Ubuntu 24.04.1 LTS）的唯讀盤點結果：

- `curl http://127.0.0.1/` 回傳 **403**（目錄禁止列出/無預設首頁），不是 `.env.example` 預設假設的 200——**部署時務必覆寫健康檢查 URL 或期望狀態碼範圍**，否則啟用瞬間就會發送一次錯誤的異常告警。
- `lampp status` 顯示 MySQL 當時未啟動——這與「curl 健康檢查回應」是兩件獨立的事，不能混為一談；`http` 型別的健康判定完全依據自身的檢查結果，`lampp status` 只是附加診斷資訊。
- vm2 上已有既有 notifier 部署（`/var/www/zdnServiceKPMC/lib/notifier`、`/var/www/zdnServiceANE072/lib/notifier`）——服務監控應獨立 clone 到 `/opt/service-monitor` 之類的路徑，不要塞進這些既有目錄。
- vm2 未安裝 composer——`monitorServices.php` 會自動降級為手動 `require_once` 載入，不需要額外處理。

vm2 專用 `.env` 片段（其餘沿用 `.env.example` 預設值）：

```ini
SERVICE_MONITOR_HOST_NAME=web-service-2
MONITOR_SERVICES=apache,mysql

# vm2 實測：根目錄回 403，調整為接受 200-403 視為健康
# （若能改用一個會回 200 的健康檢查路徑，優先使用該路徑）
APACHE_HEALTHCHECK_URL=http://127.0.0.1/
APACHE_HTTP_MIN_STATUS=200
APACHE_HTTP_MAX_STATUS=403

MYSQL_HEALTHCHECK_MODE=ping
MYSQLADMIN_PATH=/opt/lampp/bin/mysqladmin
```

部署前務必先執行：

```bash
php monitorServices.php check --dry-run
```

確認兩個服務的判定結果符合預期，再加入 cron。

---

## 常見問題

### Q: 為什麼 `status` 顯示某服務是 `pending` 而不是 `healthy`/`unhealthy`？

代表這個服務目前為止的檢查都還沒有取得過明確的 `healthy`/`unhealthy` 判定（例如一直逾時或設定有誤，回傳的都是 `unknown`）。`unknown` 不會建立確認的服務狀態基準線，請檢查該服務的設定或連線狀況。

### Q: 我改了 `.env` 但某個設定看起來沒有生效？

`apache`/`mysql` 這兩個 key 有「命名空間優先、扁平命名 fallback」兩種來源，可能改到的變數被命名空間版本蓋過了（或反之）。優先檢查是否同時存在 `MONITOR_SERVICE_APACHE_*`/`MONITOR_SERVICE_MYSQL_*` 版本的設定。

### Q: 新增的服務一直是 `unknown`，而且沒有拖累其他服務？

這是預期行為：單一服務設定錯誤會被降級為該服務專屬的「永遠 `unknown`」checker，`check --dry-run` 或 `status` 的 `diagnostic`/訊息欄位會說明設定錯誤原因，其餘服務不受影響。

### Q: MySQL query 模式一直回報設定錯誤？

`MYSQL_DEFAULTS_EXTRA_FILE` 指向的憑證檔權限必須是 `0600`（僅擁有者可讀寫）。權限不安全或檔案不存在時，系統一律回報設定錯誤（`unknown`），不會嘗試略過權限問題直接查詢。
