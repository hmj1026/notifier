# 服務健康監控部署說明

本文件說明如何將 `monitorServices.php` **獨立部署**到一台主機，定期檢查服務健康狀態，並透過 Google Chat 發送異常／恢復通知與每日日報。

文件內所有主機、帳號、路徑、遠端、Webhook、VirtualHost 均使用佔位符，方便進版控。請先填寫下方對照表，再套用後續指令。

使用說明與架構見 [SERVICE_MONITOR.md](SERVICE_MONITOR.md)。將 notifier 嵌入既有業務排程（`notifyResult.php`）見 [DEPLOY_TARGET_PROJECT.md](DEPLOY_TARGET_PROJECT.md)，與本文件的獨立監控部署不同。

---

## 目錄

1. [佔位符對照](#佔位符對照)
2. [部署原則](#部署原則)
3. [前置確認](#前置確認)
4. [取得程式碼](#取得程式碼)
5. [環境變數](#環境變數)
6. [Webhook：沿用既有或獨立空間](#webhook沿用既有或獨立空間)
7. [驗證](#驗證)
8. [Cron 排程](#cron-排程)
9. [回滾](#回滾)
10. [常見問題](#常見問題)

---

## 佔位符對照

請在本機備註實際值，**不要把填好的值提交進 git**。

| 佔位符 | 含義 | 填入時注意 |
|--------|------|------------|
| `YOUR_SSH_HOST` | 本機 `~/.ssh/config` 的目標主機別名 | 僅用於從工作站連線目標主機 |
| `YOUR_DEPLOY_USER` | 目標主機上執行監控與 cron 的系統使用者 | 不需 sudo 即可跑 PHP；建立部分系統目錄時才可能需要一次性提權 |
| `YOUR_MONITOR_PATH` | 監控獨立安裝目錄 | 必須是 `YOUR_DEPLOY_USER` 可寫入的路徑，且**不是**既有業務服務的 `lib/notifier/` |
| `YOUR_GIT_SSH_ALIAS` | 目標主機 `~/.ssh/config` 裡能讀取本 repo 的 Host 別名 | 預設 `git@github.com:` 不一定能用該主機上的金鑰 |
| `YOUR_GIT_REMOTE` | clone 用的 git remote | 可直接填完整 URL；若拆開寫則搭配下一列 |
| `YOUR_ORG` | git 遠端的組織或使用者名稱 | 僅在展開 `git@YOUR_GIT_SSH_ALIAS:YOUR_ORG/notifier.git` 時使用 |
| `YOUR_BRANCH` | 要部署的分支 | 遠端必須已有此分支；否則改用下方 rsync 方式 |
| `YOUR_HOST_NAME` | 告警／日報標題用的主機識別名稱 | 對應 `SERVICE_MONITOR_HOST_NAME`；留空則使用系統主機名稱 |
| `YOUR_EXISTING_SERVICE_PATH` | 既有 notifier 所在的業務服務根目錄 | 只用來**手動複製** webhook 設定，不要把監控裝進去 |
| `YOUR_HEALTHCHECK_HOST` | 健康檢查用的 HTTP `Host` | 選一個實際會回預期狀態碼的 VirtualHost；不要假設 `http://127.0.0.1/` 一定是 200 |
| `YOUR_GOOGLE_CHAT_WEBHOOK` | Google Chat Webhook URL | 秘密值；只寫在目標主機 `.env`，權限建議 `600` |
| `YOUR_LAMPP_ROOT` | LAMPP 安裝根目錄 | 常見預設 `/opt/lampp`，仍以目標主機為準 |
| `YOUR_PHP` | PHP CLI 執行檔 | 常見為 `php` 或 `YOUR_LAMPP_ROOT/bin/php`，需 PHP 5.6+ 且啟用 curl |
| `YOUR_TIMEZONE` | 監控與 cron 使用的時區 | 需與系統時區或 `CRON_TZ` 一致，例如 `Asia/Taipei` |

---

## 部署原則

- **獨立 clone**：監控與既有 per-service 的 `notifyResult.php` 部署分開。不要安裝到 `YOUR_EXISTING_SERVICE_PATH/lib/notifier/`。
- **獨立 `.env`**：監控只讀自己安裝目錄下的 `.env`，不會去讀其他專案的設定檔。
- **獨立儲存**：`MONITOR_STORAGE_PATH`（預設 `storage/service-monitor/`）只屬於這份部署。
- **不常駐**：由 cron 觸發一次性 `check`／`report`，不會自動重啟被監控的服務。
- **無 Composer 可跑**：目標主機沒有 `composer` 時可略過 `composer install`；`monitorServices.php` 會改為手動 `require_once`。

---

## 前置確認

在工作站：

```bash
ssh YOUR_SSH_HOST
```

在目標主機上確認下列項目（請依實際輸出調整佔位符，不要把輸出貼進版控文件或工單）：

```bash
# PHP 與 curl
which YOUR_PHP
YOUR_PHP -v
YOUR_PHP -m | grep -i curl

# 工具
which git
which composer   # 沒有則略過 composer install

# LAMPP（若監控 apache/mysql）
ls -ld YOUR_LAMPP_ROOT YOUR_LAMPP_ROOT/bin/mysqladmin
YOUR_LAMPP_ROOT/lampp status

# 根路徑實際 HTTP 狀態碼（常不是 200）
curl -sI --max-time 5 http://127.0.0.1/ | head

# 指定 VirtualHost 的狀態碼（用來決定 YOUR_HEALTHCHECK_HOST）
curl -s -o /dev/null -w "%{http_code}\n" --max-time 5 \
  -H "Host: YOUR_HEALTHCHECK_HOST" http://127.0.0.1/

# 安裝路徑是否可寫
# YOUR_MONITOR_PATH 的父目錄必須讓 YOUR_DEPLOY_USER 可建立或寫入
# 若選用 /opt/... 而該使用者無法寫入，請改選可寫路徑，或一次性提權建立後 chown 給 YOUR_DEPLOY_USER
```

請特別確認：

1. **HTTP 健康檢查**：`http://127.0.0.1/` 若為 403（無預設首頁／禁止列目錄），不可直接使用 `.env.example` 的 200–399。改用會回預期狀態碼的 `YOUR_HEALTHCHECK_HOST`，或刻意調整 `APACHE_HTTP_MIN_STATUS`／`APACHE_HTTP_MAX_STATUS`。
2. **MySQL 實際狀態**：`lampp status` 與 HTTP 檢查是兩件事。若清單含 `mysql` 但資料庫當時未啟動，第一次正式 `check` 就會告警。不打算監控時，不要把 `mysql` 寫進 `MONITOR_SERVICES`。
3. **Git 遠端**：在目標主機用 `YOUR_GIT_SSH_ALIAS` 測試讀取權限，不要假設預設 `github.com` 金鑰可用。
4. **既有 notifier**：可作為 webhook 複製來源，不可作為監控安裝位置。

---

## 取得程式碼

### 方式 A：目標主機 git clone（遠端已有 `YOUR_BRANCH`）

```bash
ssh YOUR_SSH_HOST
git clone -b YOUR_BRANCH YOUR_GIT_REMOTE YOUR_MONITOR_PATH
cd YOUR_MONITOR_PATH
```

目標主機若已設定 SSH Host 別名，remote 應使用該別名，例如：

```bash
git clone -b YOUR_BRANCH git@YOUR_GIT_SSH_ALIAS:YOUR_ORG/notifier.git YOUR_MONITOR_PATH
```

有 Composer 時：

```bash
composer install --no-dev --optimize-autoloader
```

沒有 Composer 時略過上一行。

### 方式 B：從工作站 rsync（遠端尚無該分支時）

在 **工作站** 的 notifier 專案根目錄執行（排除本機工具與秘密檔）：

```bash
rsync -avz --delete \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'vendor' \
  --exclude 'storage/service-monitor' \
  --exclude '.hyperweave' \
  --exclude '.claude' \
  --exclude '.cursor' \
  --exclude '.agent' \
  --exclude '.codex' \
  ./ YOUR_SSH_HOST:YOUR_MONITOR_PATH/
```

然後：

```bash
ssh YOUR_SSH_HOST
cd YOUR_MONITOR_PATH
```

---

## 環境變數

```bash
cd YOUR_MONITOR_PATH
cp .env.example .env
mkdir -p storage/service-monitor/logs
chmod 600 .env
```

用編輯器填入實際值。下列為**監控相關必填／建議覆寫**片段；未列出的項目可沿用 `.env.example` 預設值。

```ini
# --- 通知（本 clone 自己的 .env；見下一節）---
NOTIFY_ENABLED=true
GOOGLE_CHAT_WEBHOOK=YOUR_GOOGLE_CHAT_WEBHOOK
NOTIFY_TIMEOUT=30

# --- 監控開關與識別 ---
SERVICE_MONITOR_ENABLED=true
SERVICE_MONITOR_TIMEZONE=YOUR_TIMEZONE
SERVICE_MONITOR_HOST_NAME=YOUR_HOST_NAME

MONITOR_STORAGE_PATH=storage/service-monitor
MONITOR_INTERVAL_MINUTES=5
MONITOR_SERVICES=apache,mysql

# --- apache（http）---
# 優先：指向會回 200 的 VirtualHost
APACHE_HEALTHCHECK_URL=http://127.0.0.1/
APACHE_HEALTHCHECK_HOST_HEADER=YOUR_HEALTHCHECK_HOST
APACHE_HTTP_MIN_STATUS=200
APACHE_HTTP_MAX_STATUS=399

# --- mysql ---
MYSQL_HEALTHCHECK_MODE=ping
MYSQLADMIN_PATH=YOUR_LAMPP_ROOT/bin/mysqladmin
LAMPP_ROOT=YOUR_LAMPP_ROOT
```

若無法提供穩定回 200 的 Host，且操作者**有意**把「網頁伺服器有回應（含 403）」視為健康，才調整狀態碼範圍，例如：

```ini
APACHE_HEALTHCHECK_URL=http://127.0.0.1/
APACHE_HEALTHCHECK_HOST_HEADER=
APACHE_HTTP_MIN_STATUS=200
APACHE_HTTP_MAX_STATUS=403
```

`apache`／`mysql` 也支援命名空間寫法（`MONITOR_SERVICE_APACHE_*`、`MONITOR_SERVICE_MYSQL_*`），優先於上方扁平命名。不要兩套同時寫成不同值。

完整變數見 `.env.example` 的「服務健康監控設定」區塊。

---

## Webhook：沿用既有或獨立空間

監控**只讀本目錄 `.env`** 的 `NOTIFY_ENABLED`、`GOOGLE_CHAT_WEBHOOK`、`NOTIFY_TIMEOUT`。沒有 `SERVICE_MONITOR_WEBHOOK` 這類專屬鍵，也不會自動讀取 `YOUR_EXISTING_SERVICE_PATH/.env`。

`NOTIFY_STRATEGY`（`all`／`failure_only`）不影響監控告警與日報。

### 沿用既有 Google Chat 空間

1. 打開 `YOUR_EXISTING_SERVICE_PATH/.env`（或該服務內嵌 notifier 的 `.env`）。
2. **手動複製** `GOOGLE_CHAT_WEBHOOK` 的值到 `YOUR_MONITOR_PATH/.env`。
3. 設 `NOTIFY_ENABLED=true`。
4. 不要用指令把整個既有 `.env` 印到終端、工單或聊天。

兩份部署仍是獨立檔案：之後業務通知改 webhook，不會自動改到監控，需再複製一次。

### 使用獨立 webhook

1. 在要接收監控告警的 Google Chat 空間新增 Webhook。
2. 把新 URL 填入 `YOUR_MONITOR_PATH/.env` 的 `GOOGLE_CHAT_WEBHOOK`。
3. 設 `NOTIFY_ENABLED=true`。

### 通知未設定時的行為

| 指令 | `NOTIFY_ENABLED=false` 或 webhook 空白 |
|------|----------------------------------------|
| `check` | 仍執行健康檢查與狀態儲存，但不發送告警 |
| `report` | 無法發送日報，結束碼 `1` |

---

## 驗證

**務必先 dry-run，確認判定符合預期後再加入 cron。** `--dry-run` 會印出各服務結果與預計發送內容（已遮罩疑似秘密值），但不會真正發送、不會更新 `state.json`、不會寫 sent marker。

```bash
cd YOUR_MONITOR_PATH
YOUR_PHP monitorServices.php check --dry-run
YOUR_PHP monitorServices.php status
```

可選：確認日報格式（同樣不發送）：

```bash
YOUR_PHP monitorServices.php report --dry-run
```

### 確認通知通道（部署後必做）

一般 `check` 在**初次全健康時不會發 Google Chat**（只建立基準線）。要確認 webhook 當下真的通，請在 dry-run 通過後執行：

```bash
cd YOUR_MONITOR_PATH
YOUR_PHP monitorServices.php check --notify-now
```

終端機應出現「現況訊息已發送」。再到該空間確認收到標題含 `YOUR_HOST_NAME`、內含各服務當前狀態的現況卡片。這則訊息與異常／恢復告警分開，即使全部健康也會送。

可先預覽不發送：

```bash
YOUR_PHP monitorServices.php check --dry-run --notify-now
```

若出現「通知未啟用或缺少 GOOGLE_CHAT_WEBHOOK」或結束碼 `1`，先檢查本目錄 `.env` 的 `NOTIFY_ENABLED` 與 `GOOGLE_CHAT_WEBHOOK`，不要加入 cron。

結束碼：

| 結束碼 | 意義 |
|--------|------|
| `0` | 成功且服務皆正常；或日報已發送／先前已發送；或 process lock 佔用而安全跳過 |
| `1` | 設定／儲存／命令／通知系統錯誤 |
| `2` | 檢查完成，但至少一個服務目前為 `unhealthy` |

dry-run 通過後，可再執行一次不含 `--dry-run` 的 `check` 以建立初始狀態（初次健康不發通知；初次確認異常則依 `MONITOR_FAILURE_THRESHOLD` 告警）。

---

## Cron 排程

`check` 間隔須與 `.env` 的 `MONITOR_INTERVAL_MINUTES` 一致。`MONITOR_CHECK_TOTAL_BUDGET_SECONDS` 必須小於該間隔的秒數。執行中有 process lock，逾時未結束時下一輪會安全跳過（結束碼 `0`），不會重疊、不會重複通知。

以 `YOUR_DEPLOY_USER` 的 crontab 為例（不需修改系統 `/etc/crontab`，除非該主機的慣例是把排程集中在那裡）：

```cron
CRON_TZ=YOUR_TIMEZONE
*/5 * * * * cd YOUR_MONITOR_PATH && YOUR_PHP monitorServices.php check >> storage/service-monitor/logs/cron.log 2>&1
0 9 * * *   cd YOUR_MONITOR_PATH && YOUR_PHP monitorServices.php report >> storage/service-monitor/logs/cron.log 2>&1
```

```bash
crontab -e
```

若環境不支援 `CRON_TZ`，請依伺服器時區換算時間，並確認 `SERVICE_MONITOR_TIMEZONE` 一致。

---

## 回滾

1. **停用（保留資料）**：crontab 刪除上述兩行，或將 `.env` 的 `SERVICE_MONITOR_ENABLED` 設為 `false`。`check`／`report` 會跳過；`status` 仍可查既有資料。
2. **完全移除**：停用後刪除 `YOUR_MONITOR_PATH`。不會影響 `YOUR_EXISTING_SERVICE_PATH` 的業務通知。

---

## 常見問題

### Q: 為什麼不能沿用業務專案的 `.env` 檔案路徑？

監控入口以自己的安裝目錄為 `DOCROOT`，只讀該處 `.env`。這是刻意的部署獨立性：監控生命週期不應綁在某一個業務服務上。要沿用同一個 Chat 空間，請複製 webhook 字串，不是共用檔案。

### Q: 目標主機 `git clone git@github.com:...` 失敗？

該主機的預設 GitHub 金鑰可能不是讀取本 repo 的那把。改用 `YOUR_GIT_SSH_ALIAS`，或改走方式 B（rsync）。

### Q: 選 `/opt/...` 建立目錄失敗？

部分主機上一般部署帳號不能寫 `/opt`。改選 `YOUR_DEPLOY_USER` 可寫的路徑（例如網頁根目錄下的獨立資料夾），或一次性提權建立目錄並 `chown` 給該使用者。監控程式執行時不需要 sudo。

### Q: dry-run 顯示 Apache `unhealthy`，但網頁其實有在跑？

多半是健康檢查 URL／狀態碼範圍與實際不符。先用 `curl` 確認 `http://127.0.0.1/` 與 `Host: YOUR_HEALTHCHECK_HOST` 的狀態碼，再改 `.env`，不要先開 cron。

### Q: 第一次正式 `check` 就對 MySQL 告警？

該服務當時未達到健康條件（例如未啟動）。先啟動服務，或暫時從 `MONITOR_SERVICES` 拿掉 `mysql`。

### Q: 訊息或 log 裡出現 webhook、token、密碼？

不應出現。dry-run 與正式通知會遮罩疑似秘密值。若仍看到，立刻停用 `NOTIFY_ENABLED` 並檢查設定，不要把輸出貼到版控或工單。
