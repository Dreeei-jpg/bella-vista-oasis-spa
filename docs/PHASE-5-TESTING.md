# Phase 5 verification — actual results

Verified **6 November 2026** with XAMPP PHP **8.2.12**, MariaDB **10.4.32**, Windows and the existing application at **http://127.0.0.1:8088/**. Real staff were used for the actual installation test; fictional staff were confined to separate `_test` databases.

## Final automated results

| Suite | Passed | Environment / coverage |
|---|---:|---|
| `tests/phase5.php` | 53 | Isolated DB: exact full/down/balance amounts, ownership, holds, resources/price revalidation, concurrency, customer changes, admin actions, room states, reports |
| `tests/phase5-http.php` | 74 | Isolated server/DB: complete UI handlers, role boundaries, receipt/booking retries, settings, staff editing, customer/admin cancellation/rescheduling/review, abandoned checkout |
| Isolated HTTP cleanup preservation check | 1 | Original fixture-environment records/settings exactly preserved |
| `tests/phase5-http.php --allow-live-qa` | 59 | Actual 8088 and `bella_vista_spa`: registration/login → real available hold → full/down demo payment → MySQL → customer/admin/calendar/reports |
| Actual-installation cleanup preservation check | 1 | Existing administrator, original appointment, real staff, settings and payment records exactly preserved |
| `tests/booking.php` | 50 | Retained unpaid-mode scheduling, capacity/pricing, cleaning and independent PHP-process races |
| `tests/availability.php` | 28 | Aggregate room matrix, room seed idempotency, staffing/error reasons, cancelled/expired reservations and closing boundaries |
| `tests/management.php` | 44 | Retained non-demo cancellation/rescheduling, centavos, six-hour boundaries, audit/ledger preservation and payment boundaries |
| `tests/management-http.php` | 30 | Retained customer management forms, ownership, CSRF, late-change UI and disabled real checkout |
| `tests/booking-http.php --test-db-failure` | 53 | Retained availability/forms, admin staffing protections, JSON database failure and recovery |
| `tests/integration.php` | 129 | Public pages/assets, registration, hashing, duplicate email, session/role/CSRF/XSS boundaries, logout/throttling and catalog |
| **Total** | **522** | **Zero final failed checks** |

Repeated executions while repairing assertions are not counted twice. The historical empty-payment assertion was updated to distinguish real and simulated ledgers; the registration redirect assertion now expects the already-implemented booking-intent resume behavior. Both suites were rerun successfully.

## Mandatory Phase 5 checklist

| Area | Required checks | Final result / evidence |
|---|---|---|
| Accounts | Registration, customer login/logout, admin login, customer denial from admin routes | PASS — actual HTTP run plus 129 account/security checks. Passwords hashed, roles re-read from MySQL; no default admin password was created. |
| Availability | Valid future rooms/times, disabled unavailable rooms, capacities, cleaning, therapist conflicts, correct prices | PASS — 50 booking + 28 aggregate checks, real available SR-1 opening slot and browser room controls. |
| Confirmation | Reference generation, actual confirmation and persistence | PASS — holds remain awaiting payment until committed demo completion. Details/history retrieve saved records. |
| Simulated payment | Full, downpayment, balance, separate MySQL receipts, no real gateway, retry safety | PASS — ₱800 full; ₱240 down / ₱560 remaining at 30%; subsequent balance simulation; `is_demo=1`; no real payment/attempt rows created. |
| Failed/expired holds | Reject expired, changed price, missing staff, conflicting room, stale quote; release after abandonment/expiry | PASS — no receipt on failure; expired state persists; availability returns after release. |
| Concurrency | Same room / last therapist / same receipt key / competing payment keys | PASS — separate PHP workers released at a barrier; one allocation/receipt, or idempotent shared result. |
| Customer dashboard | Upcoming/history/details, six-hour cancellation, safe rescheduling, ownership | PASS — actual saved bookings, separate demo history, exact deadline and one-second-late rejection, failed replacement leaves original intact. |
| Administration | New appointments, calendar, room states, assignments, demo records, counts/revenue, persistence | PASS — same database reference shown across pages; valid changes persisted after refresh. Premature completion and payment-hold bypass rejected. |
| Policy review | Simulated refund marker, review note/state, no invented refund | PASS — `is_demo=1` request with unknown requested amount; saved review; receipt retained and no refund issued. |
| Full integration | Customer → Booking → Demo Payment → MySQL → Customer Dashboard → Admin Dashboard | PASS on actual 8088, plus independent browser completion and admin verification. |

## Actual installation evidence

The final automated actual-server run created full demo booking **BV-8CEB020AE7267971** and downpayment demo booking **BV-7A309CE1D9F0E096**. It verified opening-time SR-1, ₱800, duplicate submission behavior, customer ownership, admin assignment persistence, daily count/revenue changes and separate demo receipt data.

An independent browser flow created **BV-0B491152366F50F4**, SR-1, **9 November 2026, 13:00–14:00 Philippine time**, and completed **Demo Card** for ₱800. Admin management showed the same customer, reference, room, therapist, price, simulated amount and zero balance. The calendar showed occupied cells at 13:00/13:30 and cleaning at 14:00, then available at 14:30. The receipt appeared as **DEMO-271F8DC8764A93904160**, with `is_demo=1`, in Demo / Simulated Transactions. Daily reporting showed the downpayment case's ₱560 outstanding balance on its appointment day.

These were real application requests and actual MySQL records, not rendered mock bookings. They were labelled QA and removed with their own declarations/receipts/accounts after verification. Existing records and the approved real staff schedule remained unchanged.

Private test outputs remain under `var/phase5-*.txt`; they are excluded from the ZIP and web document root. Screenshots under `docs/screenshots/phase-5/` include checkout, mobile checkout, customer confirmation, overview, filtered admin booking, mobile administration, calendar and demo payment monitoring. Medical answers in these QA screens are fictional.

## Browser and static checks

- Completed registration-backed customer login, health → selection → summary → hold → Demo Card completion → confirmation → administrator login/management/calendar/monitoring/report flow on the actual server.
- Verified full/downpayment examples from saved customer history and the separate simulated ledger.
- On the isolated site, switching from the ₱800 full payment to the 30% downpayment automatically refreshed the server quote to ₱240 due and ₱560 remaining. The selected Demo Card method stayed selected.
- Checked **390×844** checkout, admin table and calendar layouts; document-level horizontal overflow was absent. Wide administrative tables remain horizontally scrollable within their containers.
- Seven-day calendar rendered seven room tables. No application JavaScript errors/warnings were observed during the successful browser flow.
- PHP syntax and JavaScript syntax checks passed for the changed application files. Original logo/font/image assets were retained; administration CSS was extended within the existing Quiet Atlas palette and components.

## Repeat verification locally

For the configured installation with existing staff and a posted future shift:

```powershell
& 'C:\xampp\php\php.exe' tools/check-installation.php
& 'C:\xampp\php\php.exe' tests/phase5-http.php http://127.0.0.1:8088 --allow-live-qa
```

This explicit test creates three uniquely named QA accounts (including a temporary administrator), two customer demo bookings and simulated receipts, then removes only those fixtures. It does not insert fictional staff into the actual installation or alter the existing admin account. Run without simultaneous customer activity because exact preservation assertions compare the database around the fixture lifecycle. No test password is printed.

For service tests, use a **fresh separate database ending in `_test`** and a separate source/configuration copy. Import `database/schema.sql` after replacing only its database name with the chosen test name, then configure that copy's ignored `config/local.php` for the test database. Do not point these fixtures at the real database.

```powershell
# Run in the isolated source copy before adding demo staff.
& 'C:\xampp\php\php.exe' tests/phase5.php
```

For isolated HTTP tests, enable development configuration and seed labelled staff there, then run its PHP server on a separate local port:

```powershell
& 'C:\xampp\php\php.exe' tools/seed-demo.php --development-only
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8098 -t public router.php
# From another terminal in that same isolated source copy:
& 'C:\xampp\php\php.exe' tests/phase5-http.php http://127.0.0.1:8098
```

Older suites use `tests/legacy-mode.php` to explicitly exercise retained unpaid/real-provider branches in `_test` databases. Their fictional-staff fixtures never belong on the live database. The optional old booking HTTP `--test-db-failure` changes only the isolated copy's local DB port, tests JSON 503, then restores its configuration in `finally`.

The separate development PHP servers used for verification were stopped during teardown; the actual 8088 server remained available.

## Verification limits

Real merchant transactions, provider signatures, external webhooks and actual refunds were not exercised or claimed. The selected product is a local payment simulation. Tests used XAMPP MariaDB rather than a separate Oracle MySQL 8 process. Apache was not independently re-run for this phase. The full idle-session timeout was not waited out; session/security behavior otherwise has automated coverage. Rubric scores and formal accessibility certification remain evaluator judgments.
