# Final 100-point rubric coverage checklist

This maps the completed prototype to the supplied rubric. Points are the rubric's maximum weights, not an instructor-awarded grade.

| Category | Maximum | Compliance evidence |
|---|---:|---|
| System Functionality & Business Logic | 35 | Working account/booking/demo-payment/admin flow; server prices/capacities; hours, cleaning and staffing; transactional resource and receipt idempotency; customer/admin management |
| UI/UX Design & Branding | 25 | Quiet Atlas logo/palette/type/assets retained; editorial customer forms; responsive labelled checkout/admin controls; clear simulated-money notices; functional role-aware navigation |
| Database & Backend Integration | 20 | Same MySQL data across customer/admin; safe migration; PDO/foreign keys/indexes; real availability; separate flagged demo ledger; persistence, ownership and concurrent-operation tests |
| Documentation & Presentation | 20 | About/history/terms retained and updated where required; README/architecture; actual test results; defense walkthrough; explicit simulation and limitations |
| **Rubric coverage** | **100** | All supplied categories have implemented, documented demonstration evidence |

## System Functionality & Business Logic — 35-point category

- [x] Public website, registration, secure password hashing, customer/admin login and logout work.
- [x] Customer authentication and health declaration gate reservations.
- [x] SR-1/SR-2/SR-3, CS-1/CS-2, FL-1/FL-2 are active and linked to the proper treatments.
- [x] ₱800 single, ₱1,800 couples, ₱4,000 family plus ₱750 for each extra guest beyond four, maximum six.
- [x] Monday–Sunday 13:00–21:00 Philippine time, 30-minute starts, 60/60/90-minute durations and 30-minute room cleaning.
- [x] Both directions of room-cleaning overlap are enforced; therapists are released at treatment end.
- [x] Actual available room/time choices; separate invalid-input, staff, occupied-room and server-error messages.
- [x] Temporary holds, simulated full/down/balance payment, saved reference and confirmed appointment.
- [x] Customer history/details/change history; six-hour customer changes; safe replacement resource allocation.
- [x] Admin appointments, calendar, room status, staff editing/schedules/assignment, reviews, demo monitoring/settings/reports.
- [x] Room/therapist races, repeated booking submissions and payment idempotency pass real MySQL/PHP-process tests.

Evidence: `tests/phase5.php`, `tests/phase5-http.php`, retained scheduling/management suites and actual 8088/browser flow in `PHASE-5-TESTING.md`.

## UI/UX Design & Branding — 25-point category

- [x] Original Bella Vista logo, Linen/Forest/Stone/Clay/Ink design system and editorial type retained.
- [x] Existing marketing/story/treatment layouts remain; admin views extend the same components and palette.
- [x] Customer summary displays name, treatment, guests, room, date, start/end, duration, price and payment selection.
- [x] Checkout displays total, full/down choice, amount due, remaining balance, labelled simulated method and completion action.
- [x] Obvious **DEMO PAYMENT ONLY — No actual money will be transferred** notice; no card/PIN/CVV/wallet-password inputs.
- [x] Labelled form controls, native validation, skip link, meaningful status messages and protected POST actions.
- [x] 390×844 checkout/admin/calendar checks have no document-level horizontal overflow; wide tables scroll inside containers.
- [x] Customer and admin navigation lead to implemented routes, with policy/availability constraints explained.

Evidence: `docs/screenshots/phase-5/`, `app/components/admin/`, `public/assets/css/management.css`, browser verification. Formal scoring and accessibility certification are evaluator judgments.

## Database & Backend Integration — 20-point category

- [x] All customer/admin views read the same `bella_vista_spa` database.
- [x] Additive migration 005 and versioned CLI preserve existing administrator/customer/appointment/verified-payment data.
- [x] Existing PDO prepared statements, sessions, CSRF, escaping, password hashing and database-role checks remain.
- [x] Health answers are customer-scoped and omitted from administrative appointment details.
- [x] Server-side prices, percentage arithmetic, temporary hold/resource checks and atomic confirmation.
- [x] `demo_payments.is_demo=1` and `DEMO-…` references; separate real verified-payment ledger.
- [x] Saved bookings, receipt totals, report counts, calendar cells and status/assignment changes persist after refresh.
- [x] Exact preservation checks remove only labelled QA fixtures; no permanent mock appointments or revenue counters remain.

Evidence: `database/migrations/005_demo_admin.sql`, `database/schema.sql`, `app/modules/demo.php`, `app/modules/admin.php`, 522-check report.

## Documentation & Presentation — 20-point category

- [x] About Us/company history retained in `public/about.php`.
- [x] Terms describe simulated checkout, prototype percentage, expiry and policy review in `public/terms.php`.
- [x] README covers current server, fresh XAMPP setup, safe upgrade, admin promotion and development-only staff fixtures.
- [x] `PHASE-5.md` explains architecture, processing, repaired gaps, migration, real/development staffing and limits.
- [x] `PHASE-5-TESTING.md` reports actual passed suites and verification limits.
- [x] `DEFENSE-WALKTHROUGH.md` gives the exact customer → demo checkout → customer/admin demonstration.
- [x] `PHASE-5-FILE-CHANGES.md` and `FILE-LIST.txt` identify delivered source changes.
- [x] Presentation explicitly calls every payment a simulation and distinguishes it from verified GCash/Maya/card transactions.

No real gateway integration, approved business refund entitlement, or production deployment is claimed by this prototype checklist.
