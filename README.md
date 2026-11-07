# Bella Vista Oasis Spa — Phase 5.3
Working PHP/MySQL college-project prototype with Quiet Atlas branding, customer registration/login, health declarations, real availability, simulated checkout, appointment history, cancellation/rescheduling, and integrated administration.

Test checkout — No actual funds will be transferred. GCash, Maya and Card are available as test payment options. Checkout collects no payment credentials. Payment History identifies test transactions separately from verified provider records.

## Open the current installation

- Website: **http://127.0.0.1:8088/**
- Customer/admin sign-in: http://127.0.0.1:8088/auth/login.php
- Registration: http://127.0.0.1:8088/auth/register.php
- Admin overview: http://127.0.0.1:8088/admin/index.php
- Appointment management: http://127.0.0.1:8088/admin/appointments.php

The existing database is `bella_vista_spa`, with this computer's connection configured privately at `127.0.0.1:3307`. The existing administrator and original appointment were preserved. There is no hardcoded administrator password.

Manage real staff and their private schedules through **Administration → Therapist Management**. One scheduled therapist permits one appointment at a time under the current staffing default.

## Start locally on Windows

Open PowerShell in the project folder:

```powershell
cd C:\Users\USER\Downloads\Wellness
.\Start-Demo.ps1
```

Keep the terminal open, then visit http://127.0.0.1:8088/. If that port already serves this application, use the running site rather than starting another copy. Ctrl+C stops the launcher-owned processes. The launcher can reuse the existing database only after checking that its data directory belongs to this workspace.

The launcher uses installed XAMPP binaries, an isolated MySQL/MariaDB data directory at `var/mysql`, port 3307, and PHP port 8088. It imports a fresh schema or applies missing versioned migrations, validates the installation, and serves `public/` with `router.php`. It does not reset an existing database.

If MySQL is already running, the equivalent manual web command, from the project folder, is:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8088 -t public router.php
```

The public document root is required. Old `/public/` links redirect to the corrected routes. Private application/configuration/database/runtime files are outside the served directory.

## Fresh XAMPP setup / sharing with friends

1. Install XAMPP with PHP 8+, Apache, MySQL/MariaDB, PDO MySQL, mbstring and sessions. PHP cURL is required for the included HTTP tests.
2. Extract the ZIP into `C:\xampp\htdocs\bella-vista-spa`.
3. Start Apache and MySQL in XAMPP Control Panel.
4. Import `database/schema.sql` through phpMyAdmin **only for a fresh database**. It includes migrations 002, 003, 005, 006 and 007; do not import those again afterward.
5. Default XAMPP connection is `127.0.0.1:3306`, database `bella_vista_spa`, user `root`, empty local password. Copy `config/local.example.php` to ignored `config/local.php` only when overriding these settings.
6. Open http://localhost/bella-vista-spa/public/ and register separate customer and administrator accounts.
7. Promote the explicitly chosen administrator account using the CLI command below. Add real therapists/shifts, or use the clearly labelled development-only fixture setup below for a college demo installation.

Each friend runs a separate local database. `localhost` is not a shared internet address. All fonts, logo and images are bundled; no npm or frontend build is required.

## Existing database upgrade

Export/back up the database first. Replace application files while retaining private `config/local.php`, `config/payments.local.php`, and the existing database/runtime data. Run:

```powershell
& 'C:\xampp\php\php.exe' tools/migrate.php
& 'C:\xampp\php\php.exe' tools/check-installation.php
```

The CLI follows local configuration and applies only unapplied migrations. Migration 007 adds administrator account security. Migration 005 adds the separate test payment ledger and settings. Phase 5.1 adds `database/migrations/006_therapist_preferences.sql`, which stores the optional customer preference and per-service therapist qualifications without changing existing assignments or transactions.

Do not import the fresh schema over existing tables. MySQL DDL commits individually: inspect or restore a backup before retrying a partially applied migration. This computer's pre-upgrade backup is private at `var/phase53-before-migration.sql` and is excluded from the ZIP.

## First administrator setup

For a fresh installation with no administrator, register the first account, then promote it from the project folder:

```powershell
& 'C:\xampp\php\php.exe' tools/make-admin.php 'your-own-registered-admin@example.com'
```

Replace the email with the actual chosen account. This uses the configured database and never sets or prints a password. Sign out and sign in again. Admin accounts go to the admin area; customer accounts go to their dashboard or resume a requested booking. Use a separate customer account for reservations. Additional administrators use **Account & Security → Add Administrator**.

Admin navigation includes Overview, Appointments, Reservation Calendar, Room Management, Therapist Management, Customer Records, Payments, Financial Reports, and Business Settings. New bookings appear from the same MySQL tables on refresh. Appointment management supports search/date/status filtering, details, valid status transitions, cancellation, available-therapist assignment, safe rescheduling, reschedule review and refund-policy review. Future treatments cannot be prematurely completed; awaiting-payment bookings cannot be manually confirmed to bypass checkout.

## Account & Security

Open the page from the management navigation or **Business Settings**. You can update your email and password, create and revoke one-time invitations, manage administrator status, and review security activity. Changes require your current password. Invitees set their own password and sign in separately. Bella Vista does not send invitation or verification email. See [Phase 5.3](docs/PHASE-5.3.md) for the workflow and limits.

## Optional fictional development staff

This is an explicit fixture operation for a local development/demo installation. Set `development_mode` to true in local configuration, or use `APP_ENV=development` when no local override disables it. For the launcher's database on 3307:

```powershell
$env:APP_ENV='development'
$env:DB_PORT='3307'
& 'C:\xampp\php\php.exe' tools/seed-demo.php --development-only --enable-rooms
```

Normal Apache/XAMPP installations use port 3306 instead. Ignored `config/local.php` takes precedence over environment defaults.

The script inserts missing services/rooms safely. If no active staff exist, it creates three **[DEMO] Fictional Therapist** records with `is_demo=1` and 30 days of 13:00–21:00 Philippine-time shifts. Repeated runs do not duplicate them. It does not create login accounts, invent real employee details or overwrite real staff schedules. With real active staff already present, fictional staff are not added automatically; an explicit `--add-demo-staff` option exists for an intentionally fictional development presentation. The demo labels must remain visible during the defense.

## Customer workflow

1. Register or sign in as a customer.
2. Complete the health declaration and consent. Answers are stored; the form does not medically approve treatment.
3. Select treatment, guest count and a scheduled future date.
4. Find times, select a valid start and choose an available room.
5. Choose a named available therapist or leave Any Available Therapist selected. Review the room, time, duration and price, then accept the booking terms.
6. Select Continue to Payment to reserve the room and therapist temporarily.
7. Review the exact full or downpayment amount, choose a test payment method and select Confirm reservation.
8. The server revalidates availability/price, writes the demo receipt and confirms atomically. The reference appears in customer history, admin management, the calendar and reports.

Single: ₱800 / 60 minutes / capacity 1. Couples: ₱1,800 / 60 minutes / capacity 2. Family: ₱4,000 / 90 minutes / four included, maximum six, ₱750 per extra guest. Six-person family total: ₱5,500.

Open Monday–Sunday 13:00–21:00, `Asia/Manila`, with 30-minute starts and 30-minute room cleaning. Treatments can end at 21:00; cleaning may end at 21:30. Therapists are released at treatment end. Administrators publish shifts in half-hour steps between 13:00 and 21:00, set service qualifications and edit flagged conflicts in Therapists & shifts. Room, staffing, capacity, price and concurrent-booking rules are enforced on the server under the scheduling lock.

## Demo payment and review policy

Default prototype settings: demo checkout enabled, **30% assumed downpayment**, **15-minute hold**. Edit them at `/admin/settings.php`. The demo percentage is not a business-approved policy and does not change the separate real payment settings.

For ₱800: demo full payment is ₱800 with zero balance; a 30% demo downpayment is ₱240 with ₱560 remaining. Customers may simulate the remaining balance later. Every receipt has `is_demo=1` and a `DEMO-…` reference in `demo_payments`; the real `payments`/provider-attempt ledger is not written by this flow.

Payment and booking retries are idempotent. Expired/abandoned holds release availability. Customer cancellation/rescheduling requires at least six hours' notice, including the exact deadline. Rescheduling secures the replacement before changing the original. Cancellation retains records and sends paid test cases to policy review. A late cancellation records the applicable downpayment forfeiture. Refunds require a separate decision.

Reports label all simulated amounts. Demo revenue uses transaction date; appointment counts and outstanding balances use appointment date. The room calendar distinguishes reservation/treatment, cleaning, blocks, inactive rooms and available physical intervals. Bookability also depends on staff.

## Appearance and verification

The sun/moon button and the administrator Dark mode switch share a preference across the site. The first visit follows your device theme. Calendar blocks show short references, time, therapist and status. Open a block for the full reference and a copy button.

**482 automated checks passed** for Phase 5.2. Browser verification covered a named therapist, a downpayment, matching customer and management records, and the desktop and mobile checkout layouts.

- `docs/PHASE-5.3.md` — account management, migration, instructions and test results
- `docs/PHASE-5.2.md` — terminology changes, checkout, management views, test results and launch limits
- `docs/PHASE-5.1.md` — earlier therapist, theme and calendar updates
- `docs/PHASE-5.md` and `docs/PHASE-5-TESTING.md` — earlier Phase 5 delivery and verification
- `docs/RUBRIC-CHECKLIST.md` — coverage mapped to the 100-point rubric
- `docs/DEFENSE-WALKTHROUGH.md` — presentation setup and customer-to-admin demonstration
- `docs/PHASE-5.3-FILE-CHANGES.md` / `docs/FILE-LIST.txt` — changed-file and full-distribution inventories

Use `tests/phase5-http.php http://127.0.0.1:8088 --allow-live-qa` only for explicitly authorized local QA: it creates and removes labelled temporary customer/admin accounts, appointments and simulated receipts using existing real staff. Run without simultaneous user activity because preservation checks compare records. Fictional-staff service tests require a fresh separate database ending in `_test`. Earlier unpaid-flow repair reports and tests are historical; current verification is documented in the Phase 5.2 guide.

## Troubleshooting and remaining scope

- No enabled times: check the date's active staff shifts/breaks, room blocks, capacity, existing reservations and closing time. Publish additional dates when shifts end.
- Availability error: the page distinguishes server/database failure from no room or therapist availability. Retry after restoring MySQL; no unverified selection can be confirmed.
- Expired demo hold: start a new available booking. A late completion cannot revive an expired hold automatically.
- Form expired / HTTP 403: refresh to obtain the current session/CSRF token. Sessions time out after inactivity.
- Login rate limit / HTTP 429: wait for the displayed retry period.
- HTTP 503: start the configured MySQL server and run `tools/check-installation.php`.

This delivery is the college demonstration prototype. Real merchant integration and actual refunds remain outside it. Verification used XAMPP MariaDB and the PHP server on 8088; standalone Oracle MySQL 8 and Apache were not separately re-run for Phase 5.1. Existing Apache/subdirectory support is retained. Private configuration, database backups, runtime data and test credentials are excluded from distribution.
