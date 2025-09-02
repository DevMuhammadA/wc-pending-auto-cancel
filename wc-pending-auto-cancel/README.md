# WooCommerce Pending Auto-Cancel

Automatically **cancels unpaid orders** after a configurable number of hours, per status:

- **Pending payment** → default 24 hours
- **On hold** → default 72 hours

Adds a settings page under **WooCommerce → Pending Auto-Cancel** with:
- Enable/disable toggle
- Choose target statuses (Pending, On hold)
- Hours threshold per status
- Optional private order note with placeholders: `{hours}`, `{status}`, `{order_id}`
- "Run now (test)" button to execute the task immediately

## Why
Reduce clutter and free up stock by auto-cancelling orders that remain unpaid. Keeps your order list clean and customers informed.

## Installation
1. Upload to `wp-content/plugins/` or install via **Plugins → Add New → Upload Plugin**.
2. Activate **WooCommerce Pending Auto-Cancel**.
3. Go to **WooCommerce → Pending Auto-Cancel** to configure.

## How it works
- A scheduled task runs **hourly** and scans orders in selected statuses that are older than your thresholds.
- Orders that are **not paid** (`$order->is_paid() === false`) are moved to **Cancelled** and an optional private note is added.
- Uses efficient batched queries (200/order batch).

## Requirements
- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+

## Changelog
- 1.0.0 — Initial release
