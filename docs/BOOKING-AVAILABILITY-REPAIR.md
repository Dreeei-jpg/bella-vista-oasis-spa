# Critical booking availability repair

Historical repair report. Phase 5 retains these availability rules and now uses explicitly simulated checkout. See `PHASE-5.md` and `PHASE-5-TESTING.md` for the current workflow and test commands.

Verified **6 November 2026**, using the existing XAMPP PHP 8.2.12/MariaDB 10.4.32 installation and actual website at **http://127.0.0.1:8088/**. The Quiet Atlas branding, logo, fonts, images and CSS remain unchanged. No Phase 4 features or payment provider were added.

## Exact diagnosis

The live database had **zero therapist records and zero therapist schedules**. Every service correctly requires one therapist by default, so an empty staff schedule blocked all starts on all dates. This was a genuine staffing prerequisite, not a reason to bypass validation.

The investigation also checked the room catalog, service links, active flags, capacity, appointments/statuses, operating hours, PHP logs, availability API, JavaScript requests, overlap queries, database timezone, and server booking validation:

- All seven required rooms were already present, active, and correctly linked. Single rooms have capacity 1, couples rooms 2, family rooms 6. Services are active with durations 60/60/90 minutes.
- There were no existing appointments causing the initial blanket block. No PHP/SQL availability exception in the inspected logs explained the no-staff result.
- Operating hours were already Monday–Sunday, 13:00–21:00, with 30-minute starts and 30-minute cleaning. These values were retained.
- Database connections explicitly use UTC; customer dates/times explicitly use `Asia/Manila`. The server's Asia/Singapore system timezone did not cause an offset bug.
- The existing overlap queries already block `[start, cleaning_end)` for rooms in both directions, including a proposed booking's cleaning before a later reservation. Therapist conflicts correctly use `[start, treatment_end)`. These rules were retained and tested.
- The frontend required choosing a room before a start and queried just that room. It did not discover available rooms for a selected time, and collapsed empty results into a generic combined room/staff message.
- The administrator could add active staff and shifts but could not reactivate an existing inactive therapist. The repaired roster now exposes activation/deactivation with protected POST actions.

## Live room and therapist status

| Treatment | Active rooms | Capacity | Duration |
|---|---|---:|---:|
| Single Room Massage | SR-1, SR-2, SR-3 | 1 each | 60 minutes |
| Couples Suite | CS-1, CS-2 | 2 each | 60 minutes |
| Barkada / Family Spa Lounge | FL-1, FL-2 | 6 each | 90 minutes |

The authorized staff record and available shifts were added to the existing private database. Employee identity and exact shift dates are withheld from this public source copy.

No breaks were specified. Administrators can add Unavailable shifts for actual breaks/leave, publish more dates, and activate/deactivate staff. A therapist with an upcoming active appointment cannot be deactivated or blocked across that appointment without first resolving it. Existing customer accounts were not promoted or replaced.

Dates outside published shifts remain unavailable until further shifts are published. There is one available therapist at a time, so concurrent appointments across the seven rooms still require enough scheduled staff. The application does not invent extra therapists to make every room bookable simultaneously.

## Backend and frontend repair

`service_availability()` validates service, guest count and date against MySQL, then evaluates every active eligible room at each start using the existing booking engine. Its response includes per-time room availability and explicit outcomes:

| Outcome | Meaning |
|---|---|
| `available` | At least one room and enough scheduled therapists are available |
| `no_rooms` | No active room can accommodate the treatment/party |
| `no_therapists` | A physically available room lacks sufficient scheduled staff |
| `room_occupied` | Eligible rooms are reserved, blocked, or being cleaned |
| `outside_hours` / `past_time` | Treatment cannot fit the local operating window or start is no longer future |
| `invalid_guest_count` / `past_date` / other validation codes | HTTP 422 validation error |
| `server_error` | HTTP 503 JSON error; no room-availability claim is made |

The existing fixed-room availability contract remains available when `room_id` is passed, preserving rescheduling and other callers.

The customer now selects **treatment → guest count → date → start time → available room**. Changing criteria invalidates stale times/rooms. The room dropdown shows only bookable rooms for that start and party, and confirmation remains disabled until a valid room/time is selected. Overlapping asynchronous responses cannot restore stale choices. Server/network/invalid-response failures clear unverified choices and show an error with retry instructions.

The booking handler continues to validate all inputs and prices under a database transaction. All scheduling writers acquire `business_settings.id=1 FOR UPDATE` before checking room and therapist conflicts. Two independent PHP-process race tests verified that only one contender can acquire the same room or last therapist.

Treatments may end at 21:00 and room cleaning may finish at 21:30. Therapists may start another appointment at treatment end in a different free room; room cleaning does not occupy the therapist.

## SQL and record safety

No schema change, table replacement, or destructive migration was required. All seven live rooms already existed, so their records were unchanged.

The optional **`database/seeds/required_rooms.sql`** inserts only missing required room codes by matching existing active service slugs. It can run repeatedly without duplicate rooms. It does not change existing room IDs, status decisions, mappings, or appointment records. Existing inactive/mismatched rooms or absent services require administrator review. Its idempotency and restoration of a missing room were tested only in the development database.

Live data changes were limited to the user-approved real therapist/shift setup and clearly labelled temporary QA customer/declaration/booking fixtures. The temporary fixtures were subsequently removed. Existing customer, health, appointment, payment, room, service, real-staff and shift records were compared before/after the live booking test and remained unchanged. No fictional staff were inserted into the live database.

A private backup was made before publishing the real staff setup: `var/availability-before-staff-20261106.sql`. Backups, database files, private configuration, sessions, and test credentials are excluded from distribution and are outside the web document root.

## Verification results

**147 automated checks passed, 0 failed.** Browser checks below are additional and are not included in that count.

| Suite | Checks | Environment |
|---|---:|---|
| `tests/booking.php` | 50 | Separate development-only MySQL database; original booking rules and true concurrent PHP-process races |
| `tests/availability.php` | 28 | Same isolated development database; service-specific room matrix, missing-room seed, outcomes and boundaries |
| `tests/booking-http.php` with `--test-db-failure` | 53 | Isolated PHP server on 8096 and development database; real forms/API, admin activation, error injection and recovery |
| `tests/live-booking.php` | 15 | Actual port 8088 and existing live database, using approved real staff |
| Live fixture cleanup/preservation verification | 1 | Exact pre-existing record preservation after QA cleanup |

Fictional therapists existed only in the separate development database `bella_vista_spa_availability_61b82e_test`. Development fixtures were cleaned up and its temporary PHP server was stopped. The actual 8088 website and MySQL server remained running.

| Required scenario | Result |
|---|---|
| Future date with available rooms | PASS: aggregate API and actual live schedule offer room/time choices |
| First appointment at opening | PASS: actual 7 November 13:00 family booking saved |
| Second appointment after cleaning | PASS: exact end-of-cleaning booking saved in development tests |
| Blocking a room during cleaning | PASS: FL-1 blocked at 14:30 while FL-2 was offered |
| Overlapping bookings | PASS: server rejects overlap and races serialize competing room/staff requests |
| No available therapists | PASS: inactive/unscheduled development staff and live unscheduled 13 November produce the correct outcome |
| Service-specific rooms | PASS: 3 single, 2 couples and 2 family rooms; room selection updates with start time |
| Family pricing | PASS: 4/5/6 guests calculate ₱4,000/₱4,750/₱5,500; live six-person booking stored ₱5,500 |
| Past dates | PASS: explicit HTTP 422 `past_date`; server refuses past bookings |
| Closing boundary | PASS: last single start 20:00; last family start 19:30; treatment ends 21:00 and cleaning 21:30 |
| API/database failure | PASS: isolated real connection failure returns JSON 503; browser shows server error, clears choices and disables confirmation; retry recovers after restoring the connection |
| Saved booking in dashboard | PASS: actual database booking reference and unpaid balance appeared in customer history |

The complete live QA booking was **BV-4F1D5DA139B5B615**, Family Lounge FL-1, six guests, 7 November 2026 at 13:00 Philippine time, assigned to an eligible therapist. The handler stored a real confirmed/unpaid record at ₱5,500 and ignored forged price/payment fields. A repeated POST returned the same record. It was then removed with its labelled QA account after verification, so it does not consume real availability.

### Browser evidence

On actual port 8088, the browser verified saved history, health gating, the ₱5,500 family total, FL-2 as the only available family room at 14:30 while FL-1 was being cleaned, both rooms at 15:00, disabled confirmation until a room is selected, and an explicit no-therapist message on an unscheduled date.

On isolated port 8096, a real failed database connection produced the expected visible server-error message, zero stale slots, and disabled room/confirmation controls. Restoring the connection and retrying recovered three available single rooms. At 390×844, selecting a room worked without horizontal overflow. No JavaScript exceptions were observed; the intentionally failed request generated an expected HTTP 503.

Evidence under `docs/screenshots/availability/`:

- `actual-saved-booking.png` — actual persisted QA booking in customer history
- `rooms-during-cleaning.png` — actual availability and server-calculated family price
- `database-error.png` — isolated failure clearly identified as a server/database problem
- `available-room-mobile.png` — existing responsive booking page

## Files changed

| File | Repair |
|---|---|
| `app/modules/booking.php` | Structured availability validation codes and aggregate room/time availability; existing save mutex/overlap rules retained |
| `public/api/availability.php` | Aggregate response without room ID, legacy fixed-room support, explicit validation and server-error JSON |
| `public/booking.php` | Time-first room selection, embedded initial availability and clear messages; existing form/branding retained |
| `public/assets/js/booking.js` | Dynamic room options, stale-response protection, valid-selection gating, explicit API/error handling |
| `public/admin/therapists.php` | Existing-staff activation/deactivation, roster state, empty-staff explanation, protection for scheduled appointments |
| `database/seeds/required_rooms.sql` | Optional idempotent missing-room repair; not needed/applied on live data |
| `tests/availability.php` | New development-only aggregate availability checks |
| `tests/booking-http.php` | Added room matrix, admin activation and optional isolated database-failure checks |
| `tests/live-booking.php` | Explicit local QA booking with existing real staff and exact preservation/cleanup verification |
| `tests/functionality-repair.php` | Restrict the historical fictional-staff suite to separate `_test` databases; direct live verification to the real-staff suite |
| `README.md`, `docs/FUNCTIONALITY-REPAIR.md`, this report, `docs/FILE-LIST.txt` | Current workflow, results, historical-report clarification and file inventory |

## Verify locally

1. Open **http://127.0.0.1:8088/**. If stopped, run `Start-Demo.ps1` from the project folder and keep its terminal open.
2. Sign in as a customer and open **Book a visit**. Complete the health declaration.
3. Choose a treatment, valid guest count, and a scheduled date between **6 and 12 November 2026**. Use a future start for the current date.
4. Click **Find available times**, choose an enabled start, then select one of its available rooms. For a six-person Family Lounge visit, the total is **₱5,500**.
5. Review the terms and submit. The saved reference appears in appointment details and your dashboard, with **payment unpaid** while online checkout is unconfigured.
6. In **Administration → Therapists & shifts**, publish more real shifts and Unavailable breaks as needed. A registered administrator is required; the existing `tools/make-admin.php` command in the README can promote an explicitly chosen registered administrator account.

Read-only database check:

```powershell
& 'C:\xampp\php\php.exe' tools/check-installation.php
```

For repeatable real-server QA using only existing real staff:

```powershell
& 'C:\xampp\php\php.exe' tests/live-booking.php --allow-live-qa
```

That explicit QA command creates and removes its own labelled customer/booking; run without simultaneous customer activity because preservation checks compare existing records. Development suites require a fresh separate database ending in `_test` and an isolated source/configuration copy; never point fictional-staff fixtures at the live database. The `--test-db-failure` option temporarily changes only that isolated copy's local connection settings and restores them in `finally`.

## Remaining limits

- Publish shifts beyond **12 November** and actual breaks/leave. Staffing requirements remain configurable at one therapist per appointment by default; availability stays honest when scheduled capacity is exhausted.
- Online payment remains disabled because no gateway adapter/credentials are configured. No payment success, charge, webhook, or refund was simulated.
- Verification used XAMPP MariaDB and PHP's actual local server. Standalone Oracle MySQL 8 and Apache were not separately re-run for this repair; existing XAMPP/Apache-compatible routes and SQL were retained.
