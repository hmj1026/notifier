# notification Specification

## Purpose
TBD - created by archiving change 2026-01-22-add-notification-service. Update Purpose after archive.
## Requirements
### Requirement: Notifier Abstract Interface

系統 **SHALL** 提供一個抽象的 `Notifier` 基底類別，定義通知器的標準介面。

- 所有通知器實作 **MUST** 繼承此抽象類別
- 抽象類別 **SHALL** 定義 `send()` 與 `formatMessage()` 兩個抽象方法
- 所有語法 **MUST** 相容 PHP 5.6

#### Scenario: 子類別必須實作抽象方法
- **GIVEN** 一個繼承 `Notifier` 的子類別
- **WHEN** 未實作 `send()` 或 `formatMessage()` 方法
- **THEN** PHP 應拋出 Fatal Error

#### Scenario: 通知器可被停用
- **GIVEN** 建立 Notifier 實例時傳入 `enabled = false`
- **WHEN** 呼叫 `send()` 方法
- **THEN** 不執行實際發送，直接回傳 `true`

---

### Requirement: Log Analyzer

系統 **SHALL** 提供 `LogAnalyzer` 類別，負責分析 log 檔案內容並判斷執行結果。

- 分析器 **MUST** 能判斷執行成功或失敗
- 分析器 **SHALL** 提取關鍵資訊 (處理筆數、錯誤類型等)
- 支援自訂失敗模式 (Failure Patterns) 擴展

#### Scenario: 偵測成功執行
- **GIVEN** log 內容包含 `============ Get Receipt End` 標記
- **AND** 不包含任何失敗關鍵字
- **WHEN** 呼叫 `analyze()` 方法
- **THEN** 回傳結果的 `success` 欄位為 `true`

#### Scenario: 偵測 SoapFault 錯誤
- **GIVEN** log 內容包含 `SoapFault` 關鍵字
- **WHEN** 呼叫 `analyze()` 方法
- **THEN** 回傳結果的 `success` 欄位為 `false`
- **AND** `errorType` 欄位為 `SOAP 通訊錯誤`

#### Scenario: 偵測 HTTP 連線失敗
- **GIVEN** log 內容包含 `Error Fetching http headers` 關鍵字
- **WHEN** 呼叫 `analyze()` 方法
- **THEN** 回傳結果的 `success` 欄位為 `false`
- **AND** `errorType` 欄位為 `HTTP 連線失敗`

#### Scenario: 提取處理筆數
- **GIVEN** log 內容包含 `本次執行總共取得 13 筆資料`
- **WHEN** 呼叫 `analyze()` 方法
- **THEN** 回傳結果的 `recordsProcessed` 欄位為 `13`

---

### Requirement: Google Chat Notifier

系統 **SHALL** 提供 `GoogleChatNotifier` 類別，透過 Webhook 發送通知至 Google Chat。

- 使用 cURL 發送 HTTP POST 請求
- 訊息格式 **SHALL** 為 JSON (`{"text": "..."}`)
- 支援自訂訊息格式

#### Scenario: 成功發送通知
- **GIVEN** 有效的 Webhook URL
- **AND** 正確的訊息內容
- **WHEN** 呼叫 `send()` 方法
- **THEN** HTTP 回應碼為 200
- **AND** 方法回傳 `true`

#### Scenario: Webhook URL 無效
- **GIVEN** 無效的 Webhook URL
- **WHEN** 呼叫 `send()` 方法
- **THEN** HTTP 回應碼非 200
- **AND** 方法回傳 `false`

#### Scenario: 格式化成功訊息
- **GIVEN** 分析結果 `success = true`, `recordsProcessed = 13`
- **WHEN** 呼叫 `formatMessage()` 方法
- **THEN** 訊息包含 `✅` 符號
- **AND** 訊息包含 `執行成功`
- **AND** 訊息包含 `處理筆數：13 筆`

#### Scenario: 格式化失敗訊息
- **GIVEN** 分析結果 `success = false`, `errorType = 'SOAP 通訊錯誤'`
- **WHEN** 呼叫 `formatMessage()` 方法
- **THEN** 訊息包含 `❌` 符號
- **AND** 訊息包含 `執行失敗`
- **AND** 訊息包含 `SOAP 通訊錯誤`

---

### Requirement: Notification Entry Point

系統 **SHALL** 提供 `notifyResult.php` 入口程式，整合 Log 分析與通知發送。

- 從環境變數讀取設定
- 支援透過命令列參數指定專案根目錄
- 當 `NOTIFY_ENABLED=false` 時不發送通知

#### Scenario: 完整通知流程
- **GIVEN** 環境變數 `NOTIFY_ENABLED=true`
- **AND** 有效的 `GOOGLE_CHAT_WEBHOOK` URL
- **AND** 存在今日的 log 檔案
- **WHEN** 執行 `php notifyResult.php /path/to/project`
- **THEN** 分析 log 檔案內容
- **AND** 發送通知至 Google Chat
- **AND** 輸出 `通知發送成功`

#### Scenario: 通知功能停用
- **GIVEN** 環境變數 `NOTIFY_ENABLED=false`
- **WHEN** 執行 `php notifyResult.php /path/to/project`
- **THEN** 輸出 `通知功能已停用`
- **AND** 不發送任何通知

#### Scenario: Log 檔案不存在
- **GIVEN** 今日的 log 檔案不存在
- **WHEN** 執行 `php notifyResult.php /path/to/project`
- **THEN** 輸出錯誤訊息 `Log 檔案不存在`
- **AND** 程式以 exit code 1 結束

---

### Requirement: Environment Configuration

系統 **SHALL** 支援透過 `.env` 檔案進行環境設定。

- 所有敏感資訊 (Webhook URL) **MUST** 透過環境變數設定
- **SHALL** 提供 `.env.example` 範本檔案
- 支援以下設定項目:
  - `NOTIFY_ENABLED` - 是否啟用通知 (true/false)
  - `NOTIFY_CHANNEL` - 通知渠道 (google_chat)
  - `GOOGLE_CHAT_WEBHOOK` - Webhook URL
  - `NOTIFY_STRATEGY` - 通知策略 (all/failure_only)

#### Scenario: 讀取環境變數
- **GIVEN** `.env` 檔案包含 `NOTIFY_ENABLED=true`
- **WHEN** 程式啟動並讀取設定
- **THEN** 通知功能應為啟用狀態

#### Scenario: 環境變數預設值
- **GIVEN** `.env` 檔案未設定 `NOTIFY_STRATEGY`
- **WHEN** 程式讀取設定
- **THEN** `NOTIFY_STRATEGY` 應預設為 `all`

---

### Requirement: PHP 5.6 Compatibility

所有程式碼 **MUST** 相容 PHP 5.6 語法。

- **禁止**使用以下 PHP 7+ 語法:
  - 空合併運算子 (`??`)
  - 太空船運算子 (`<=>`)
  - 匿名類別
  - 標量型別提示 (type hints)
  - 回傳型別宣告
  - Nullable types (`?string`)
  - Arrow functions (`fn() =>`)

#### Scenario: 語法相容性檢查
- **GIVEN** 所有 PHP 檔案
- **WHEN** 使用 PHP 5.6 進行語法檢查 (`php -l`)
- **THEN** 所有檔案必須通過無錯誤

---

### Requirement: Installation Documentation

系統 **SHALL** 提供完整的安裝與使用說明文件 (`README.md`)。

文件 **MUST** 包含:
- 系統需求 (PHP 版本、擴展)
- 安裝步驟
- 環境變數設定說明
- 與其他服務整合步驟
- 使用範例

#### Scenario: README 完整性
- **GIVEN** README.md 文件
- **WHEN** 新使用者閱讀文件
- **THEN** 可依指示完成安裝與設定
- **AND** 可成功發送測試通知

---

### Requirement: ANE072 Log Enhancement

系統 **SHALL** 增強對 ANE072 專案 Log 的處理能力，包含編碼修正與顯示優化。

#### Scenario: Unicode 解碼
- **GIVEN** ANE072 Log 包含 Unicode Escape 序列 (如 `\u55ae`)
- **WHEN** 分析 Log 並聚合錯誤訊息
- **THEN** 聚合結果中的 key 應顯示為解碼後的 UTF-8 中文
- **AND** 例如 `\u55ae\u64da` 應顯示為 `單據`

#### Scenario: 單據號碼正規化
- **GIVEN** 錯誤訊息包含 `單據號碼 [12345]已存在`
- **WHEN** 分析 Log 並聚合錯誤訊息
- **THEN** 聚合結果應將其歸類為 `單據號碼 [XXXX]已存在`

---

### Requirement: Google Chat UI Improvement

系統 **SHALL** 優化 Google Chat 通知的顯示格式，減少版面佔用。

#### Scenario: 詳細錯誤折疊顯示
- **GIVEN** 錯誤統計包含多筆不同類型的錯誤
- **WHEN** 產生 Google Chat 通知 Payload
- **THEN** 錯誤統計區塊應使用可折疊元件 (Collapsible Widget) 呈現
- **OR** 若 API 限制，應使用適當的分隔線與標題區隔，避免過長列表直接佔滿版面
- **AND** 標題應包含 `失敗原因統計`


