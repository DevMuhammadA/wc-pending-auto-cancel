# wc-pending-auto-cancel
Auto-cancel unpaid WooCommerce orders after X hours per status (Pending, On hold). Hourly cron + settings page.

### WooCommerce Pending Auto-Cancel v1.0.0

Automatically cancels **unpaid** orders after a configurable number of hours per status.

**Highlights**
- Target statuses: Pending payment, On hold
- Per-status hour thresholds (defaults: 24h / 72h)
- Hourly cron task
- Optional private order note with placeholders `{hours}`, `{status}`, `{order_id}`
- “Run now (test)” button

**How it works**
Runs hourly, finds orders older than your thresholds in selected statuses, cancels if not paid, and adds the note (optional).

**Requirements**
WordPress 6.0+, WooCommerce 6.0+, PHP 7.4+
