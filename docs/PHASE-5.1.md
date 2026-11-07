# Bella Vista Oasis Spa — Phase 5.1

Phase 5.1 updates the existing PHP/MySQL application and Quiet Atlas layout. See the [exact file list](PHASE-5.1-FILE-CHANGES.md), [local setup](../README.md), and [defense walkthrough](DEFENSE-WALKTHROUGH.md).

| Area | What changed |
|---|---|
| Calendar | The room grid uses a time column and a separate column for each room. Blocks show occupied, pending or cleaning states, the booking status, a compact reference, start/end, and therapist. A daily appointment list also includes cancelled and completed records. Selecting a block opens details with the full selectable reference and a copy button. |
| Typography and themes | Body text and inputs are 16px. Forms and navigation are 15–16px, tables and operational labels are at least 14px, and the original serif headings and logo remain. Both toggles share the same saved preference. The browser reads it before rendering the styles and follows the device theme until the first manual choice. Wide tables scroll inside labelled panels. |
| Therapist selection | Choose a named therapist or use the default Any Available Therapist. The form shows only names and availability. A held appointment reserves the necessary staff and room under the scheduling lock. Checkout preserves the selected therapist and rejects unavailable requests instead of silently substituting another person. |
| Therapist management | Publish or edit shifts in 30-minute increments from 1:00 PM through 9:00 PM Philippine time. Staff qualifications, assigned appointments, and flagged shifts are visible in Administration. Overlapping working shifts or overlapping breaks are rejected. A break can sit inside a working shift and prevents reservations during that break. Edits and qualification removals cannot invalidate upcoming assigned appointments. |
| Payments | Completion records a test transaction and confirms the appointment atomically. Customer history displays recorded amounts and balances. Administration → Payments searches by booking, customer or transaction reference and filters Test Mode and verified provider records. The reports show test amounts by payment date and appointment balances by appointment date. |

## Database upgrade and preservation

Run `tools/migrate.php` against the configured database after taking a backup. [Migration 006](../database/migrations/006_therapist_preferences.sql) adds `appointments.preferred_therapist_id` and `therapist_service_exclusions`. It preserves the existing `appointment_therapists` links and defaults existing therapists to eligible for all services until an administrator changes their qualifications. The fresh SQL schema includes the migration. The existing database on port 3307 was backed up before the migration.

The local installation audit found seven valid future shifts, zero invalid shift entries and zero overlapping shift pairs. Legacy invalid working shifts are excluded from new availability and flagged for administrator correction. Existing bookings still occupy their saved room and therapist time. The final audit preserved two accounts, three therapist records, two appointments, five health declarations, and one previously saved test transaction. Temporary test records were removed.

The payment tables were retained. The original `demo_payments` ledger stores the booking/customer IDs, integer centavos, type, method, timestamp, unique receipt reference, and test marker. The view now reads it directly alongside verified `payments` records, while `payment_attempts` and `payment_events` retain their provider purposes. Test balance columns represent the balance when each transaction was recorded. Historical verified transactions have no comparable balance snapshot, so that column is blank for those records. The booking summary shows the current balance.

The default downpayment is a configurable 30% test setting. On an ₱800 booking, the server records ₱800 and zero remaining for full payment, or ₱240 and ₱560 remaining for a downpayment. Late cancellations record the applicable downpayment forfeiture. They do not transfer funds or automatically issue refunds.

## Actual verification

Verified 6 November 2026 on Windows, PHP 8.2.12 and XAMPP MariaDB 10.4.32. The local site used port 8088 and the separate test server used port 8098.

| Suite | Passed | Result |
|---|---:|---|
| `tests/phase5.php` | 59 | Pass. Includes simultaneous named requests, availability recheck, exact amounts, idempotency, and late downpayment forfeiture. |
| `tests/phase5-http.php` on the isolated database | 98 | Pass. 97 workflow checks plus preservation verification. Includes full/short shifts, malformed ranges, overlap, end-of-shift bookings, and qualifications. |
| `tests/phase5-http.php --allow-live-qa` on 8088 | 70 | Pass. 69 checks plus preservation verification on the configured spa database. |
| `tests/availability.php`, `tests/booking.php` | 78 | Pass. Availability, prices/capacity, cleaning and booking concurrency. |
| `tests/management.php`, `tests/management-http.php` | 74 | Pass. Customer changes, ownership, the six-hour boundary and retained unpaid booking behavior. |
| `tests/booking-http.php --test-db-failure` | 54 | Pass. Includes a forged therapist ID, accurate booking redirects, and availability error recovery. |
| `tests/integration.php` | 143 | Pass. Public routes, registration, login, sessions, roles and catalog. |
| **Total** | **576** | **All final automated checks passed.** |

The legacy HTTP test originally included a made-up therapist ID in its successful request because Phase 5 ignored that field. Phase 5.1 correctly rejects it. The regression now tests the forged request separately from the valid one. The first isolated HTTP attempt also found a test setup issue where the previous fixture switched off Test Mode. The fixture now restores the original setting.

| Requested workflow | Result |
|---|---|
| A. Specific therapist | **Pass.** Availability listed eligible names. Both live HTTP verification and the isolated browser confirmed the requested therapist and displayed the assignment to customer and administrator. |
| B. Any Available Therapist | **Pass.** The server assigned an eligible therapist and stored the actual ID without a named preference. |
| C. Full and downpayment history | **Pass.** Receipt, booking and balance matched in both dashboards after refresh. Repeat submission returned the same receipt and created no provider payment or attempt. |
| D. Scheduling | **Pass.** 13:00–21:00 and 13:00–17:00 saved. Morning, overnight, after-close, backwards, equal, off-grid and overlapping working shifts were rejected. A treatment ending at 17:00 was allowed, while a 16:30–17:30 appointment was rejected for a 17:00 shift end. |
| E. UI and accessibility | **Pass for the checks performed.** The browser switched both themes and retained the preference after refresh and across customer/admin routes. A 390px viewport kept horizontal scrolling inside the calendar/payment panels. References measured 14px in calendar blocks, stayed within the cell, and opened the correct details. The copy button placed the full reference on the clipboard. Public pages and therapist administration were checked for page-level overflow. |
| F. End-to-end | **Pass.** The live site accepted registration and login, saved a declaration, reserved an eligible room and therapist, and showed the same confirmed appointment and receipt across the dashboards. An independent browser run confirmed a named therapist with a test card payment. |

Browser evidence uses isolated fictional records. See dark checkout (screenshot retained privately), dark administrator calendar (screenshot retained privately), mobile calendar (screenshot retained privately) and light payment history (screenshot retained privately).

## Remaining limits

- The prototype does not move funds. Live provider credentials, webhooks, and actual refunds were outside these tests.
- This installation uses posted staff dates. Dates after November 12, 2026 need another available shift before customers can book them. A fresh installation needs staff and shifts from an administrator or the optional labelled development seed.
- The interactive named-therapist list needs JavaScript. The booking form retains the server-validated Any Available Therapist path without it.
- Browser verification covered the Windows in-app browser at desktop and mobile widths. Apache, a separate Oracle MySQL 8 installation, and formal accessibility certification were not part of this run.
