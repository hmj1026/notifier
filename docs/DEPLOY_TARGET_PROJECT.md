# Notification Service 部署至 YOUR_PROJECT 說明

本文件說明如何將 Notification Service 整合至 YOUR_PROJECT 專案，實現 SendDelivery 執行結果自動通報。

---

## 目錄

1. [前置需求](#前置需求)
2. [部署步驟](#部署步驟)
3. [環境變數設定](#環境變數設定)
4. [修改排程腳本](#修改排程腳本)
5. [測試驗證](#測試驗證)
6. [回滾方式](#回滾方式)

---

## 前置需求

### 系統需求
- PHP 5.6 或以上
- cURL 擴展 (php-curl)
- 網路可連線至 Google Chat Webhook

### YOUR_PROJECT 目錄結構

```
/var/www/YOUR_PROJECT/
├── .env                    # 現有環境變數
├── Library/
│   └── utility.php         # 現有工具函式
├── sendDelivery.php        # 主程式
├── sendDelivery.sh         # 排程腳本
└── log/
    └── YYYY/MM/DD/
        └── SendDelivery.log
```

---

## 部署步驟

Notifier 支援三種安裝模式，請依據您的專案情況選擇：

| 模式 | 適用情境 | 說明 |
|-----|---------|------|
| 內嵌模式 | 專案無 `src/` 目錄 | 直接複製至專案根目錄 |
| lib 模式 | 專案已有 `src/` 目錄 | 複製至 `lib/notifier/` |
| 外部模式 | 多專案共用 | 獨立安裝，透過環境變數指定 |

---

### 模式 A：內嵌模式（專案無 src/ 目錄）

```bash
cd /var/www/YOUR_PROJECT

# 複製核心程式
cp -r /path/to/notifier/src ./src
cp /path/to/notifier/notifyResult.php ./notifyResult.php
```

**目錄結構：**
```
/var/www/YOUR_PROJECT/
├── src/                        # [新增]
│   ├── Notifier.php
│   ├── LogAnalyzer.php
│   └── Notifier/
│       └── GoogleChatNotifier.php
├── notifyResult.php            # [新增]
└── ...
```

**排程腳本呼叫：**
```bash
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

---

### 模式 B：lib 模式（專案已有 src/ 目錄）

```bash
cd /var/www/YOUR_PROJECT

# 建立 lib 目錄
mkdir -p lib/notifier

# 複製完整 notifier
cp -r /path/to/notifier/src lib/notifier/
cp /path/to/notifier/notifyResult.php ./notifyResult.php
```

**目錄結構：**
```
/var/www/YOUR_PROJECT/
├── src/                        # 現有專案程式
├── lib/
│   └── notifier/               # [新增] Notifier 模組
│       └── src/
│           ├── Notifier.php
│           ├── LogAnalyzer.php
│           └── Notifier/
│               └── GoogleChatNotifier.php
├── notifyResult.php            # [新增]
└── ...
```

**排程腳本呼叫：**
```bash
# notifyResult.php 會自動偵測 lib/notifier/ 目錄
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

---

### 模式 C：外部模式（多專案共用）

將 Notifier 安裝在獨立位置，多個專案共用：

```bash
# 安裝 Notifier 至共用位置
cp -r /path/to/notifier /opt/notifier

# 複製入口程式至各專案
cp /opt/notifier/notifyResult.php /var/www/YOUR_PROJECT/
```

**方式 1：透過環境變數**

在 `.env` 中設定：
```ini
NOTIFIER_PATH=/opt/notifier
```

排程腳本：
```bash
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

**方式 2：透過命令列參數**

```bash
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot /opt/notifier
```

---

### 設定檔案權限

```bash
chmod 644 src/*.php 2>/dev/null
chmod 644 src/Notifier/*.php 2>/dev/null
chmod 644 lib/notifier/src/*.php 2>/dev/null
chmod 644 lib/notifier/src/Notifier/*.php 2>/dev/null
chmod 755 notifyResult.php
```

---

## 環境變數設定

### 修改 .env 檔案

在現有 `.env` 檔案末尾新增以下內容：

```ini
# ========== 通知設定 ==========
# 是否啟用通知 (true/false)
NOTIFY_ENABLED=true

# 通知渠道 (目前支援: google_chat)
NOTIFY_CHANNEL=google_chat

# 通知策略
# - all: 成功與失敗都通知
# - failure_only: 僅失敗時通知
NOTIFY_STRATEGY=all

# Google Chat Webhook URL
# 取得方式：Google Chat 空間 > 管理 Webhook > 建立 Webhook
GOOGLE_CHAT_WEBHOOK=https://chat.googleapis.com/v1/spaces/XXXXX/messages?key=XXXXX&token=XXXXX

# Log 設定 (應與現有 sendDelivery.sh 一致)
LOG_DIRECTORY=log
LOG_FILENAME=SendDelivery.log

# HTTP 超時時間 (秒)
NOTIFY_TIMEOUT=30

# 除錯模式 (true/false)
DEBUG_MODE=false
```

### 取得 Google Chat Webhook URL

1. 開啟 Google Chat
2. 進入目標空間 → 點擊空間名稱
3. 選擇「Apps & integrations」→「Manage webhooks」
4. 點擊「Create」，輸入名稱 (如: SendDelivery 通知)
5. 複製產生的 Webhook URL
6. 填入 `.env` 的 `GOOGLE_CHAT_WEBHOOK`

---

## 修改排程腳本

### 修改 sendDelivery.sh

在 `sendDelivery.sh` 檔案**最後一行之後**新增：

```bash
# 發送執行結果通知
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

**完整修改後的 sendDelivery.sh：**

```bash
# Get the system time
documentRoot="$1"
year="$(date +"%Y")"
month="$(date +"%m")"
day="$(date +"%d")"
now="$(date +"%T")"
logDir="$documentRoot/log"
if [ ! -d "$logDir" ]
then
    mkdir $logDir
fi
logDir="$logDir/$year"
if [ ! -d "$logDir" ]
then
    mkdir $logDir
fi
logDir="$logDir/$month"
if [ ! -d "$logDir" ]
then
    mkdir $logDir
fi
logDir="$logDir/$day"
if [ ! -d "$logDir" ]
then
    mkdir $logDir
fi
/opt/lampp/bin/php $documentRoot/sendDelivery.php $documentRoot >> $documentRoot/log/$year/$month/$day/SendDelivery.log

# [新增] 發送執行結果通知
/opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot
```

---

## 測試驗證

### 1. 語法檢查

```bash
cd /var/www/YOUR_PROJECT
php -l src/Notifier.php
php -l src/LogAnalyzer.php
php -l src/Notifier/GoogleChatNotifier.php
php -l notifyResult.php
```

**預期結果：** 所有檔案 `No syntax errors detected`

### 2. 手動測試通知

```bash
# 確保今日有 log 檔案
ls log/$(date +%Y)/$(date +%m)/$(date +%d)/SendDelivery.log

# 執行通知程式
php notifyResult.php /var/www/YOUR_PROJECT

# 預期輸出：
# 通知發送成功
```

### 3. 除錯模式測試

如需查看詳細資訊，修改 `.env`：

```ini
DEBUG_MODE=true
```

重新執行：

```bash
php notifyResult.php /var/www/YOUR_PROJECT
```

### 4. 驗證 Google Chat 收到訊息

檢查 Google Chat 空間是否收到通知訊息。

**成功訊息範例：**
```
✅ SendDelivery 執行成功

📅 執行時間：2026-01-22 06:00:49
📊 處理筆數：13 筆
👤 銷售人員：70013, 00073
📂 Log 位置：log/2026/01/22/SendDelivery.log
```

---

## 回滾方式

如需移除通知功能：

### 1. 停用通知 (保留檔案)

修改 `.env`：

```ini
NOTIFY_ENABLED=false
```

### 2. 完全移除

```bash
cd /var/www/YOUR_PROJECT

# 移除 sendDelivery.sh 中新增的那一行
# /opt/lampp/bin/php $documentRoot/notifyResult.php $documentRoot

# 移除檔案
rm -rf src/
rm notifyResult.php

# 移除 .env 中的通知設定區塊
```

---

## 常見問題

### Q: 通知發送失敗

**檢查步驟：**

1. 確認 `NOTIFY_ENABLED=true`
2. 確認 `GOOGLE_CHAT_WEBHOOK` URL 正確
3. 啟用 `DEBUG_MODE=true` 查看詳細錯誤
4. 檢查網路連線：`curl -I https://chat.googleapis.com`

### Q: Log 檔案不存在

**確認 Log 路徑設定：**

- `LOG_DIRECTORY=log` (相對於專案根目錄)
- `LOG_FILENAME=SendDelivery.log`
- 日期目錄結構：`log/YYYY/MM/DD/SendDelivery.log`

### Q: 與現有 utility.php 衝突

Notifier 使用獨立命名空間 `Notifier\`，不會與現有 `Library/utility.php` 衝突。

如需使用現有 utility.php 的 `readIniFile` 函式，可修改 `notifyResult.php` 的降級載入區塊。

---

## 變更歷史

| 日期 | 版本 | 說明 |
|-----|------|------|
| 2026-01-22 | 1.0 | 初始版本 |
