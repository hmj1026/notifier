# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-19

### Added
- **Service Health Monitoring Subsystem (`ServiceMonitor`)**:
  - Independent CLI entrypoint `monitorServices.php` supporting `check`, `report`, and `status` commands.
  - Pluggable health checkers supporting HTTP (`HttpServiceChecker`, `ApacheServiceChecker`), CLI/Shell commands (`CommandServiceChecker`), and MySQL (`MySqlServiceChecker`).
  - Incident state machine (`IncidentManager`) managing anomaly alerts, repeated reminders, and recovery notifications.
  - Daily report generator (`DailyReportGenerator`) with Cards V2 formatting.
  - On-demand status snapshot via `--notify-now` flag for instant notification channel validation.
  - Atomic JSON state storage and JSONL log repository (`MonitorStateRepository`, `MonitorLogRepository`).
  - Comprehensive documentation and standalone host deployment guide (`docs/SERVICE_MONITOR.md`, `docs/DEPLOY_SERVICE_MONITOR.md`).

## [1.0.0] - 2026-08-19

### Added
- **Core Notification Service**: Universal notification service framework supporting Google Chat Webhook integration.
- **Log Analyzers**: Built-in `LogAnalyzer` factory supporting both `KPMC` and `ANE072` log parsing formats.
- **Notification Strategies**: Configurable notification delivery strategies (`all`, `failure_only`).
- **CLI Entry Point**: `notifyResult.php` for flexible execution and multi-schedule monitoring.
- **Documentation**: Comprehensive deployment and integration guides (`docs/DEPLOY_TARGET_PROJECT.md`, `docs/USAGE.md`).
- **Environment Configuration**: Template `.env.example` for notification settings and debug mode.
