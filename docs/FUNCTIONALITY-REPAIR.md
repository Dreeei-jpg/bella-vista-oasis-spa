# Bella Vista Oasis Spa — functionality repair

Historical report. The later `BOOKING-AVAILABILITY-REPAIR.md` records the approved real therapist/shift setup and revised time-first room selection. Its current staffing status supersedes the zero-staff state documented below. The old fictional-staff QA suite now requires a separate development database ending in `_test`; use `tests/live-booking.php --allow-live-qa` for current verification on the real installation.

Verified 5–6 November 2026 on the existing workspace application. Quiet Atlas branding, logo, fonts, colors, page structure, and responsive CSS were retained. No Phase 4 features were added.

## Outcome and actual installation

The repaired site runs at **http://127.0.0.1:8088/** using XAMPP PHP and the existing MySQL-compatible MariaDB database `bella_vista_spa`, bound to `127.0.0.1:3307`. The data directory remains `var/mysql`. This was a repair of the real installation, not a replacement project or a disconnected preview.

Direct customer entry points:

- Sign in: http://127.0.0.1:8088/auth/login.php
- Register: http://127.0.0.1:8088/auth/register.php
- Treatments: http://127.0.0.1:8088/services.php
- Booking: http://127.0.0.1:8088/booking.php

## Errors found and repaired

| Failure before repair | Cause | Repair |
|---|---|---|
| Sign-in, register, and treatments routes returned 404 | Port 8088 had been started with the project root and no public document root | Serve `public/`; add a restricted development router and compatibility redirects for old `/public/` URLs |
| Root/public pages returned 503 | MySQL was stopped and local settings did not select the existing workspace database | Restart the existing data directory on 3307 and configure the local connection |
| Booking/dashboard queries could not use Phase 2/3 fields | The actual database was still at Phase 1 | Back up the database and apply existing migrations 002 then 003 |
| All treatment buttons lost the selected service | Every button opened plain `booking.php` | Include the database service ID and retain a validated service preference through authentication and health declaration |
| Registration/login discarded booking navigation | Session rotation cleared the navigation context and success always went to a dashboard | Retain only the allowlisted booking intent and service ID while rotating the session; ordinary login still opens the dashboard |
| Registration lacked a clear success notice | Account creation redirected without feedback | Display a one-time escaped success notice using the existing notice styling |
| My account could route an administrator to a restricted customer dashboard | Shared navigation assumed every signed-in account was a customer | Use the existing authentication entry point, which redirects by the authenticated account's actual role |
| Booking form described an appointment as already confirmed | Pre-submit payment explanation used completed-action wording | Explain that submitting an available time saves the appointment; show actual confirmation only from the saved record |

Registration and login handlers already contained prepared queries, backend validation, unique-email enforcement, `password_hash`/`password_verify`, session rotation, CSRF, timeout, role checks, and protected logout. The audit retained these mechanisms and tested them against the repaired actual installation. Frontend HTML constraints and JavaScript validation/password controls were also inspected and exercised.

## Database inspection and migration safety

The existing `users`, `services`, `rooms`, `therapists`, `appointments`, and `health_declarations` tables were present. Phase 2/3 columns and management tables were missing before upgrade. The existing additive scripts are supplied here:

- `database/migrations/002_booking_engine.sql`
- `database/migrations/003_customer_management.sql`

These scripts contain no table drops, truncation, or record deletion. The versioned CLI `tools/migrate.php` applies only unapplied versions. A partially applied SQL migration must be inspected before retrying because MySQL DDL commits individually. Do not import the fresh `database/schema.sql` over an existing installation.

Before migration, the actual database was exported to the private local file:

`var/repair-before-migrations-20261105-224112.sql`

The backup, runtime database, session files, and local configuration are excluded from the distribution ZIP and are inaccessible through the corrected public document root.

The final installation inspection reported connected/healthy, no missing required columns, and both migration versions applied. Final normal records: 1 user, 3 services, 7 rooms, 0 therapists, 0 appointments, and 2 health declarations. Existing customer/declaration records were preserved; all uniquely labelled QA customers, declarations, staff, shifts, appointments, assignments, and test login-attempt records were removed. Tests compare pre-existing customer and declaration rows before/after without printing their contents.

## Actual port-8088 end-to-end results

**81 automated checks passed; 0 failed.** These checks made real HTTP requests to the existing PHP server and read the same configured database. They did not mock registration, login, availability, or persistence. The suite creates uniquely labelled disposable QA records and removes only its own records in `finally` cleanup.

| Feature | Result | Evidence |
|---|---|---|
| Public navigation and assets | PASS | All collected local links/assets from public pages resolve; compatibility redirects work; private paths return 404 |
| Registration | PASS | Invalid information rejected; account stored in MySQL; password hash verifies; success message; duplicate mixed-case email prevented |
| Login and logout | PASS | Wrong password rejected; new account signs in; ordinary login opens dashboard; booking-intent login resumes booking; protected session cookies; POST logout; logged-out dashboard denied |
| Form protection | PASS | Invalid CSRF rejected; incomplete health answers rejected |
| Health declaration | PASS | Both booking UI and availability API enforce declaration first; completed declaration saves and unlocks treatment controls |
| Database treatment information | PASS | Single ₱800/60 minutes/1 guest; couples ₱1,800/60 minutes/2 guests; family ₱4,000/90 minutes/4 included, 6 maximum, ₱750 per extra guest |
| Selected treatment | PASS | Database service ID survives registration, login, logout/re-entry, and health navigation |
| Rooms and capacity | PASS | Mismatched treatment/room and excess capacity rejected by the server |
| Real availability | PASS | No staff gives no bookable times; a temporary scheduled QA therapist permits actual slots; saved room reservations block conflicting slots |
| Start intervals and closing | PASS | 30-minute start grid; forged 13:15 rejected; 90-minute treatment cannot start at 20:00 |
| Cleaning | PASS | 13:00 family appointment ends at 14:30, cleaning ends at 15:00; same room unavailable at 14:30 and available at 15:00 |
| Therapist availability | PASS | Therapist unavailable for overlapping treatment in another room; available at treatment end while the previous room is being cleaned |
| Price and payment state | PASS | Six-person family booking stores server-calculated ₱5,500 despite forged price/payment fields; remains unpaid; no payment transaction fabricated |
| Confirmation and persistence | PASS | Valid booking stored in MySQL; details and customer history retrieve its actual reference; repeated submission returns the same booking without duplicates |
| Pre-submit wording | PASS | Form no longer claims a booking is already confirmed |

Automated output is retained privately at `var/repair-test-result.txt`. Run only on an authorized local QA installation, with the server on 8088 and the configured database running:

```powershell
& 'C:\xampp\php\php.exe' tools/check-installation.php
& 'C:\xampp\php\php.exe' tests/functionality-repair.php --allow-local-qa
```

The explicit QA flag authorizes temporary fixtures in the configured local database. Run with no simultaneous customer activity; cleanup verification compares existing records and would flag unrelated concurrent changes. Historical Phase 3 test results in `VERIFICATION.md` used separate test databases and are distinct from these 81 actual-installation checks.

All 55 application/configuration/test PHP files passed syntax checks. The PowerShell launcher parsed successfully. A separate launcher smoke test reused the verified workspace database, skipped both already-applied migrations, passed installation inspection, and served HTTP 200 on temporary port 8095. Only that temporary PHP process was then stopped; the actual 8088 site and MySQL server stayed available.

## Browser verification

The browser exercised treatment-to-login navigation, registration field validation state, show/hide password, the family price estimator, actual login, health save, retained family selection, live availability fetch, room selection, six-person ₱5,500 summary, customer dashboard, and logout. Unavailable times remained disabled with the actual reason “No therapist available.” No application console errors or warnings were observed during this flow.

At 390×844, the existing mobile menu opens, exposes the navigation links, and closes with Escape. No responsive CSS or branding changes were needed. Public gallery/filter/lightbox and other action handlers were inspected in the existing JavaScript; unavailable booking/payment controls explain their state instead of acting as placeholder links.

Browser evidence (disposable QA data only):

- `screenshots/repair/booking-summary.png`
- `screenshots/repair/login-mobile.png`

The saved booking/confirmation test was performed through real HTTP forms by the automated suite. The interactive browser check stopped at live availability; it did not make a payment or submit a commercial booking.

## Files repaired or added

| File | Change |
|---|---|
| `router.php` | New restricted PHP development router and legacy public-prefix redirect |
| `Start-Demo.ps1` | Correct public root/router, verify/reuse only workspace database, schema preflight, preserve local config support |
| `config/local.php` | Ignored machine-local connection to existing port 3307; not distributed |
| `app/modules/auth.php` | Retain allowlisted booking intent during secure sign-in and choose correct post-login destination |
| `app/modules/catalog.php` | Validate and remember active service ID from treatment navigation |
| `app/components/header.php` | Escaped success notice and role-aware account entry |
| `public/auth/register.php` | Success feedback and booking-aware destination |
| `public/auth/login.php` | Booking-aware success destination |
| `public/services.php` | Service-specific Book now links |
| `public/booking.php` | Capture/restore selected service before authentication; truthful pre-submit explanation |
| `public/customer/health.php` | Retain booking intent when sign-in is required |
| `public/customer/create-booking.php` | Clear stored treatment preference after a successful saved booking |
| `tools/check-installation.php` | New read-only CLI schema/connection inspection without credentials |
| `tests/functionality-repair.php` | New actual port-8088 end-to-end checks and isolated fixture cleanup |
| `README.md`, `docs/FUNCTIONALITY-REPAIR.md`, `docs/FILE-LIST.txt` | Updated running instructions, audit/results, distribution inventory |

Existing migration SQL was reused unchanged. Existing assets, CSS, logo, and JavaScript were retained.

## Remaining setup and test limits

**Real appointment availability needs real staff.** There are currently zero real therapists. The booking system correctly refuses unavailable times. Register an administrator, promote that existing account using `tools/make-admin.php`, sign in again, and use **Therapists & shifts** to add the actual staff and publish Available shifts. No invented permanent staff or reservations were left behind. See the README for the exact administrator setup command.

**Real online checkout remains unavailable.** No gateway, adapter, or credentials have been selected. Payment pages visibly explain this and keep checkout disabled. Existing Phase 2 booking mode saves validated appointments as confirmed/unpaid. Actual payment success, gateway failures, signatures/webhooks, and refunds remain pending provider integration and were not marked passed.

The repair was executed with XAMPP PHP/MariaDB and the built-in PHP server on 8088. Existing Apache/XAMPP subdirectory support was retained; Apache was not re-run for this repair. Standalone Oracle MySQL 8 was not separately tested. The existing longer session timeout and concurrent race suites were inspected/prior-phase evidence, not repeated as part of these 81 checks.

If the computer or development processes restart, run `Start-Demo.ps1` from the project folder and keep its terminal open. Bare `php -S 127.0.0.1:8088` from the project root reproduces the original routing problem; use the launcher or `php -S 127.0.0.1:8088 -t public router.php` with MySQL already running.
