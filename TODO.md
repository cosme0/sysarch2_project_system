# Progress Report #2 — Implementation Checklist

Instructor feedback for this activity, and where each item now lives.

## A. Comprehensive log audit

- [x] Audit schema carries user, module, action, severity, entity, details, IP, user agent, request URI — `setup.sql`
- [x] Safe upgrade path for existing databases — `migration_progress2.sql`
- [x] Central logger `log_activity()` with severity levels — `auth.php`
- [x] Page-view logger with throttling — `auth.php` (`log_page_view()`)
- [x] Readable action labels for the UI — `auth.php` (`audit_action_label()`)
- [x] **Dedicated log viewer** with filters, search, date range, pagination, stats, and CSV export — `audit-log.php`
- [x] Activity summary report (by module, action, severity, account) — `reports.php?type=audit`
- [x] Summary panel on the admin dashboard — `admin.php` (Activity tab)
- [x] Navigation entry for administrators and staff — `includes/site-header.php`
- [x] Instrumented: sign-in, sign-out, registration, failed login, lockout
- [x] Instrumented: password reset requested, verified, rejected
- [x] Instrumented: catalogue search, checkout started, payment completed, payment voided, payment failed, receipt viewed
- [x] Instrumented: inventory add/update/delete, purchase recorded/confirmed/cancelled, cycle count, write-off
- [x] Instrumented: user created, role changed, account removed
- [x] Instrumented: ticket created, updated, closed, deleted
- [x] Instrumented: database backup created/restored
- [x] Instrumented: report generated, report exported

## B. Payment simulation

- [x] Cash, GCash, and Maya as the supported methods — `PAYMENT_METHODS` in `auth.php`
- [x] Server-side repricing so the cart total cannot be forged — `checkout.php`
- [x] Cash tendered, change calculated, shortfall rejected
- [x] E-wallet reference numbers generated and stored
- [x] Sales and line items recorded at the price actually charged — `sales`, `sale_items`
- [x] Printable receipt with payment breakdown — `receipt.php`
- [x] Offline simulation so the system is demonstrable without hardware — `includes/dispensing.php`
- [x] Card now hands off to checkout before dispensing — `includes/dispense-cart.php`

## C. Report generation and analytics

- [x] Daily sales report with payment mix and hourly revenue
- [x] Monthly sales report with day-by-day revenue and top sellers
- [x] Sales ledger over a date range, with a status column
- [x] Inventory report with valuation, reorder flags, and expiry risk
- [x] System activity report
- [x] Analytics: top medications, staff performance, category and channel value, payment mix, expiry outlook
- [x] Only the selected report is queried, so page load stays fast
- [x] Revenue counts completed sales only; voided and stuck sales are shown but excluded
- [x] CSV export and print layout for every report
- [x] Charts for revenue, trends, and payment mix

## D. Improved GUI

- [x] Shared page shell, skip link, and visible focus states
- [x] Redesigned header with icons, admin dropdown, and activity log entry
- [x] Two-column checkout with payment method selection
- [x] Receipt styling with a print-only layout
- [x] Table helpers, status and module chips, action code chips
- [x] Pagination and filter bar styling
- [x] Dark mode support for every new component
- [x] Responsive layout down to phone width

## E. Housekeeping

- [x] `setup.sql` is the single authoritative schema
- [x] Superseded SQL files clearly marked, with `DATABASE.md` explaining the order
- [x] Shared `db()` connection replaces per-page `mysqli_connect`
- [x] Full PHP lint passes

## Still to verify manually

- [ ] Repair or rebuild the local MariaDB instance, then run the schema
- [ ] Click through every page at desktop, tablet, and phone widths
- [ ] Run a real checkout with the dispenser bridge both online and offline
- [ ] Confirm charts render (they need the Chart.js CDN, i.e. an internet connection)
