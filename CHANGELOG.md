# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-14

### Added
- WooCommerce > Settings > **WDOD Connector** tab: enable toggle, endpoint URL, API key, trigger statuses, max attempts, "Send test ping" button (AJAX + nonce).
- Background delivery through Action Scheduler (WP-Cron fallback) with exponential backoff (300 s x 2^attempt) and a configurable attempt limit.
- HMAC-SHA256 signed JSON webhooks (`X-WDOD-Signature`, `X-WDOD-Timestamp`, `X-WDOD-Event` headers).
- Checkout fields: preferred delivery date (validated, not in the past) and gift note (max. 200 characters), stored as order meta through the CRUD API.
- Admin order box, email fields and customer-facing order details for the checkout fields and sync state.
- "WDOD Sync" column with status badge on both the legacy and HPOS order lists, plus a "Sync now" row action.
- REST API: `GET`/`POST /wp-json/wdod/v1/orders/{id}/sync`.
- HPOS (custom order tables) compatibility declaration.
- Unit tests (PHPUnit 9 + Brain Monkey) for the payload builder, result classification and retry decision logic.
- Filters: `wdod_woo_connector_payload`, `wdod_woo_connector_request_args`, `wdod_woo_connector_sync_statuses`, `wdod_woo_connector_retry_delay`, `wdod_woo_connector_checkout_fields`.
