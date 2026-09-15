# WDOD WooCommerce Connector

[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](https://wordpress.org/)
[![WooCommerce 8.2+](https://img.shields.io/badge/WooCommerce-8.2%2B-96588a.svg)](https://woocommerce.com/)
[![HPOS compatible](https://img.shields.io/badge/HPOS-compatible-2ea44f.svg)](#hpos-high-performance-order-storage)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A production-style WooCommerce integration plugin. When an order reaches a configured status, the plugin queues a background job that pushes the order to an external HTTP API as a **signed JSON webhook**, retries transient failures with **exponential backoff**, and records the outcome on the order so shop staff can see it at a glance.

It also demonstrates the day-to-day pieces of WooCommerce customisation: extra checkout fields stored via the CRUD API, order screen/email/My Account output, list-table columns on both the legacy and HPOS screens, a WooCommerce settings tab, a REST endpoint and unit tests that run without WordPress.

## What it does

1. **Checkout** — adds an optional *Preferred delivery date* and *Gift note* below the order notes, validates them server-side and stores them as order meta (`_wdod_delivery_date`, `_wdod_gift_note`).
2. **Trigger** — listens to `woocommerce_order_status_{status}` for the statuses selected in settings (default: *Processing*).
3. **Queue** — schedules a single Action Scheduler action (`wdod_woo_connector_sync_order`); falls back to WP-Cron when Action Scheduler is unavailable.
4. **Sync** — builds a payload from a plain `Order_Data` DTO, signs it with HMAC-SHA256 and POSTs it to your endpoint.
5. **Outcome** — stores `_wdod_sync_status` (`pending` / `synced` / `failed`), attempt count, last error and sync timestamp on the order, writes to the WooCommerce log (`WooCommerce > Status > Logs > wdod-woo-connector`) and adds an order note.
6. **Retry** — network errors, HTTP 429 and 5xx responses are retried after `300 s x 2^attempt` (5 min, 10 min, 20 min, ...) until *Max attempts* is reached; 4xx responses fail immediately.

## Features

- Settings tab under **WooCommerce > Settings > WDOD Connector** with a *Send test ping* button (AJAX, nonce and capability protected).
- Background delivery via **Action Scheduler** (bundled with WooCommerce), WP-Cron fallback, duplicate-job protection.
- **Signed requests**: `X-WDOD-Signature` (HMAC-SHA256 of the raw body), `X-WDOD-Timestamp`, `X-WDOD-Event`.
- **Exponential backoff** with a configurable attempt limit; decision logic isolated in a pure method (`Sync_Service::decide()`) and unit tested.
- **Order list column** "WDOD Sync" (badge, attempt count, last error on hover) on the legacy *and* HPOS screens, plus a **"Sync now"** row action.
- **Admin order box**, **email fields** and **customer order details** for delivery date, gift note and sync state.
- **REST API** `wdod/v1/orders/{id}/sync` (GET status, POST force sync) guarded by `manage_woocommerce`.
- **HPOS compatible** — all order reads/writes go through `WC_Order` CRUD methods.
- Clean architecture: constructor-injected services, `Api_Client_Interface`, DTO + pure payload builder, `Result` value object, no-op logger for tests.
- WordPress Coding Standards + PHPCompatibility (7.4) enforced with PHP_CodeSniffer; PHPUnit 9 + Brain Monkey unit tests.
- Translation-ready (`languages/wdod-woo-connector.pot`), clean uninstall.

## Screenshots

| Settings tab | Orders list column | Order screen box |
| --- | --- | --- |
| _screenshots/settings.png_ | _screenshots/orders-column.png_ | _screenshots/order-box.png_ |

_(Placeholders — add screenshots after installing on a demo site.)_

## Requirements

- WordPress 6.4 or newer
- WooCommerce 8.2 or newer (tested up to 9.9)
- PHP 7.4 or newer

## Installation

1. Copy the `wdod-woo-connector` folder to `wp-content/plugins/` (or upload the zip via **Plugins > Add New**).
2. Activate **WDOD WooCommerce Connector**. WooCommerce must be active — the plugin shows an admin notice and does nothing otherwise.
3. Go to **WooCommerce > Settings > WDOD Connector** and configure the endpoint (see below).

No build step or Composer install is required at runtime; `vendor/` is only used for development tooling and tests.

## Configuration

**WooCommerce > Settings > WDOD Connector**

| Setting | Option name | Description |
| --- | --- | --- |
| Enable sync | `wdod_woo_connector_enabled` | Master switch. Nothing is queued or sent while disabled. |
| Endpoint URL | `wdod_woo_connector_endpoint` | HTTPS URL that receives the `POST` request. |
| API key | `wdod_woo_connector_api_key` | Shared secret used for the HMAC signature. |
| Sync on statuses | `wdod_woo_connector_statuses` | Order statuses that trigger a sync (default: Processing). |
| Max attempts | `wdod_woo_connector_max_attempts` | Total attempts before an order is marked *Failed* (default 5, 1–20). |
| Test connection | — | Sends a signed `ping` event using the values currently in the form. |

Tip: for a first test, paste a [webhook.site](https://webhook.site) URL as the endpoint, enable sync, place a Cash-on-Delivery order and mark it *Processing*. Within a minute the request shows up on webhook.site and the order list shows **Synced**.

## How sync works

```
 Customer checks out                Shop manager
 (delivery date + gift note)        clicks "Sync now" / calls REST POST
          |                                   |
          v                                   v
 woocommerce_order_status_{status}     Queue::enqueue( id, 0, force=true )
          |                                   |
          v                                   |
 Order_Hooks::maybe_enqueue()  --------------->+
   (skip if disabled or already synced)       |
                                              v
                              Action Scheduler / WP-Cron
                              hook: wdod_woo_connector_sync_order( id, attempt, force )
                                              |
                                              v
                                  Sync_Service::handle()
                                              |
                     Order_Data::from_order() -> Payload_Builder::build()
                                              |
                                      Api_Client::send()
                                              |
                          +-------------------+-------------------+
                          |                                       |
                       2xx OK                               error / non-2xx
                          |                                       |
              status = synced                          Result::is_retryable()?
              synced_at, attempts                 (0 / 429 / 5xx and attempts < max)
              order note, log info                        |               |
                                                         yes              no
                                                          |               |
                                          status = pending        status = failed
                                          enqueue(id, attempt+1,  order note, log error
                                            delay = 300 * 2^attempt)
```

Retry timeline with the default *Max attempts* = 5: immediate → +5 min → +10 min → +20 min → +40 min → failed.

## Webhook payload example

`POST {endpoint}` with `Content-Type: application/json`:

```json
{
  "event": "order.synced",
  "sent_at": "2026-09-14T10:15:42+00:00",
  "order": {
    "id": 1234,
    "number": "1234",
    "status": "processing",
    "currency": "EUR",
    "created": "2026-09-14T10:14:02+00:00",
    "totals": {
      "subtotal": 100.0,
      "discount": 15.0,
      "shipping": 5.0,
      "tax": 19.5,
      "total": 109.5
    },
    "customer": {
      "email": "jane@example.com",
      "first_name": "Jane",
      "last_name": "Doe",
      "phone": "+49 30 1234567"
    },
    "billing": {
      "first_name": "Jane",
      "last_name": "Doe",
      "company": "",
      "address_1": "Musterstr. 1",
      "address_2": "",
      "city": "Berlin",
      "state": "",
      "postcode": "10115",
      "country": "DE",
      "email": "jane@example.com",
      "phone": "+49 30 1234567"
    },
    "shipping": {
      "first_name": "Jane",
      "last_name": "Doe",
      "company": "",
      "address_1": "Musterstr. 1",
      "address_2": "",
      "city": "Berlin",
      "state": "",
      "postcode": "10115",
      "country": "DE",
      "phone": ""
    },
    "items": [
      {
        "product_id": 10,
        "variation_id": 0,
        "sku": "MUG-01",
        "name": "Mug",
        "qty": 2,
        "subtotal": 40.0,
        "total": 34.0
      }
    ],
    "meta": {
      "delivery_date": "2026-09-20",
      "gift_note": "Happy birthday!"
    },
    "customer_note": "Ring twice.",
    "payment_method": "cod"
  }
}
```

The *Test connection* button sends `{"event":"ping","sent_at":"...","site":"https://shop.example"}` with the same headers.

### Request headers

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json; charset=utf-8` |
| `X-WDOD-Signature` | `hash_hmac( 'sha256', <raw request body>, <API key> )` (hex) |
| `X-WDOD-Timestamp` | Unix timestamp when the request was built (use it to reject stale requests) |
| `X-WDOD-Event` | `order.synced` or `ping` |
| `User-Agent` | `WDODWooConnector/1.0.0; https://shop.example` |

## Signature verification (receiving side)

```php
<?php
// Example receiver — verify before trusting the payload.
$secret    = getenv( 'WDOD_API_KEY' );
$body      = file_get_contents( 'php://input' );
$signature = $_SERVER['HTTP_X_WDOD_SIGNATURE'] ?? '';
$timestamp = (int) ( $_SERVER['HTTP_X_WDOD_TIMESTAMP'] ?? 0 );

if ( abs( time() - $timestamp ) > 300 ) {
    http_response_code( 400 );
    exit( 'Stale request' );
}

$expected = hash_hmac( 'sha256', $body, $secret );

if ( ! hash_equals( $expected, $signature ) ) {
    http_response_code( 401 );
    exit( 'Invalid signature' );
}

$payload = json_decode( $body, true );

// ... process $payload['order'] ...

http_response_code( 200 );
echo json_encode( [ 'received' => $payload['order']['id'] ?? null ] );
```

Respond with any `2xx` status to acknowledge. Respond with `5xx` or `429` to ask for a retry; any other status (e.g. `400`, `422`) marks the order as *Failed* without further attempts.

## REST API

Namespace: `wdod/v1`. Both routes require a user with the `manage_woocommerce` capability (shop manager or administrator). Authenticate with an [application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (Users > Profile > Application Passwords), a logged-in cookie + nonce, or any other WordPress REST authentication method.

| Method | Route | Description |
| --- | --- | --- |
| `GET` | `/wp-json/wdod/v1/orders/{id}/sync` | Current sync state of the order. |
| `POST` | `/wp-json/wdod/v1/orders/{id}/sync` | Runs a forced sync **synchronously** and returns the resulting state. Returns HTTP `200` when synced, `502` when the delivery failed (body still contains the state and `last_error`). |

Response body:

```json
{
  "id": 1234,
  "number": "1234",
  "sync_status": "synced",
  "attempts": 1,
  "last_error": null,
  "synced_at": "2026-09-14T10:15:43+00:00"
}
```

`sync_status` is one of `none`, `pending`, `synced`, `failed`. Unknown orders return `404`, missing permission returns `401`/`403`.

```bash
# Status
curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  https://shop.example/wp-json/wdod/v1/orders/1234/sync

# Force a sync now
curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" -X POST \
  https://shop.example/wp-json/wdod/v1/orders/1234/sync
```

## HPOS (High-Performance Order Storage)

The plugin declares compatibility with `custom_order_tables` via `FeaturesUtil::declare_compatibility()` and never touches `wp_posts`/`wp_postmeta` directly: order data is read and written through `wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()` and `$order->save()`. The sync column and "Sync now" action are registered for both the classic `edit-shop_order` screen and the HPOS `woocommerce_page_wc-orders` screen, so switching storage modes in **WooCommerce > Settings > Advanced > Features** requires no changes.

## Hooks & Filters

| Filter | Arguments | Purpose |
| --- | --- | --- |
| `wdod_woo_connector_payload` | `array $payload, Order_Data $data` | Add, remove or rename payload keys before sending. |
| `wdod_woo_connector_request_args` | `array $args, array $payload, string $endpoint` | Change `wp_remote_post()` arguments (extra headers, timeout, basic auth...). |
| `wdod_woo_connector_sync_statuses` | `string[] $statuses` | Override which order statuses (without `wc-`) trigger a sync. |
| `wdod_woo_connector_retry_delay` | `int $delay, int $attempt, int $order_id` | Change the backoff delay in seconds. |
| `wdod_woo_connector_checkout_fields` | `array $fields` | Alter or remove the checkout field definitions (`woocommerce_form_field()` format). |

| Action | Arguments | Purpose |
| --- | --- | --- |
| `wdod_woo_connector_sync_order` | `int $order_id, int $attempt, bool $force` | The scheduled job. Fire it yourself to trigger a sync programmatically. |

Examples:

```php
// Add a custom field to the payload.
add_filter( 'wdod_woo_connector_payload', function ( array $payload, $data ) {
    $payload['order']['source'] = 'web';
    return $payload;
}, 10, 2 );

// Send a bearer token in addition to the signature.
add_filter( 'wdod_woo_connector_request_args', function ( array $args ) {
    $args['headers']['Authorization'] = 'Bearer ' . getenv( 'WDOD_TOKEN' );
    return $args;
} );

// Retry faster while developing.
add_filter( 'wdod_woo_connector_retry_delay', fn ( $delay, $attempt ) => 30 * ( $attempt + 1 ), 10, 2 );

// Disable the gift note field.
add_filter( 'wdod_woo_connector_checkout_fields', function ( array $fields ) {
    unset( $fields['wdod_gift_note'] );
    return $fields;
} );
```

### Order meta reference

| Meta key | Description |
| --- | --- |
| `_wdod_delivery_date` | `Y-m-d` from checkout. |
| `_wdod_gift_note` | Gift note text (max. 200 characters). |
| `_wdod_sync_status` | `pending`, `synced` or `failed`. |
| `_wdod_sync_attempts` | Number of delivery attempts. |
| `_wdod_sync_last_error` | Last error message (cleared on success). |
| `_wdod_synced_at` | ISO 8601 UTC timestamp of the last successful delivery. |

## Development

```bash
composer install        # dev tooling only; not needed in production
composer test           # PHPUnit unit tests (no WordPress needed, Brain Monkey stubs WP functions)
composer phpcs          # WordPress Coding Standards + PHPCompatibility (PHP 7.4+)
composer phpcbf         # auto-fix what phpcs can fix
composer lint           # php -l on every file
```

Project layout:

```
wdod-woo-connector.php            bootstrap, HPOS declaration, WooCommerce check
includes/
  class-autoloader.php            namespace -> class-*.php / interface-*.php mapping
  class-plugin.php                composition root (constructor injection)
  class-settings.php              option getters + WC settings tab registration
  admin/class-settings-page.php   WC_Settings_Page subclass (loaded only on the settings screen)
  class-checkout-field.php        checkout fields, validation, saving
  class-order-display.php         admin box, emails, customer order details
  class-order-column.php          list column + "Sync now" (legacy + HPOS)
  class-order-hooks.php           status transition -> queue
  class-queue.php                 Action Scheduler / WP-Cron
  class-sync-service.php          orchestration + pure decide()
  class-order-data.php            DTO built from WC_Order
  class-payload-builder.php       DTO -> array (pure)
  class-api-client.php            wp_remote_post + HMAC signature
  class-result.php                value object
  class-logger.php / class-null-logger.php
  class-rest-controller.php       wdod/v1 routes
  interfaces/interface-api-client.php
templates/admin/order-meta-box.php
tests/                            PHPUnit + Brain Monkey
languages/wdod-woo-connector.pot
```

## Uninstall

Deleting the plugin removes its options and pending jobs. Order meta is kept because it is part of the order history. To purge it as well, add `define( 'WDOD_WOO_CONNECTOR_REMOVE_ALL', true );` to `wp-config.php` before deleting the plugin — meta is then removed from both `postmeta` and `wc_orders_meta`.

## Roadmap

- Register the checkout fields for the **block-based checkout** through `woocommerce_register_additional_checkout_field()` (Additional Checkout Fields API) so they appear in both the shortcode and block checkout.
- Bulk action "Sync selected orders" on the orders list.
- Optional inbound webhook to update order status from the external system.
- WP-CLI command `wp wdod sync <order_id>`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
