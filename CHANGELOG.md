# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-19

### Added
- **Core Notification Service**: Universal notification service framework supporting Google Chat Webhook integration.
- **Log Analyzers**: Built-in `LogAnalyzer` factory supporting both `KPMC` and `ANE072` log parsing formats.
- **Notification Strategies**: Configurable notification delivery strategies (`all`, `failure_only`).
- **CLI Entry Point**: `notifyResult.php` for flexible execution and multi-schedule monitoring.
- **Documentation**: Comprehensive deployment and integration guides (`docs/DEPLOY_TARGET_PROJECT.md`, `docs/USAGE.md`).
- **Environment Configuration**: Template `.env.example` for notification settings and debug mode.
