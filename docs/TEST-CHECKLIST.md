# Phase 1/2 regression and Phase 3 test checklist

Use a separate development database and disposable test data. No real appointment should be changed during verification.

## Setup and demonstration

- [ ] Fresh install: import `database/schema.sql`; confirm three services, seven rooms, no invented users/therapists/appointments/payments.
- [ ] Upgrade: back up a Phase 1 database, apply migration 002 once, confirm existing accounts and records remain. Running the CLI migrator again should report already on Phase 2.
- [ ] Register an administrator account, promote it with `tools/make-admin.php`, and register a separate customer account.
- [ ] As administrator, add actual staff and a published Available shift for the test date, e.g. 13:00–21:00.
- [ ] Before shifts are published, customer time slots must remain unavailable.
- [ ] Compare the enlarged header logo on desktop/mobile and the matching mark against the dark footer.

## Customer booking

- [ ] Anonymous Book a visit redirects to login; administrators cannot enter customer booking.
- [ ] A customer without a declaration is sent to the health step before service selection.
- [ ] All eight yes/no questions and consent are required; notes over 2,000 characters are rejected on the server.
- [ ] Save a declaration, then choose Single, Couples, and Family services; only matching rooms should be selectable.
- [ ] Single: ₱800 / 60 minutes / 1 person. Couples: ₱1,800 / 60 minutes / maximum 2. Family: ₱4,000 / 90 minutes / up to four included, six maximum.
- [ ] Family five guests = ₱4,750; six = ₱5,500. Editing browser price/payment fields must not change the stored total or unpaid status.
- [ ] Choose future date and time, accept terms, confirm, and see persisted reference, room, guest count, assigned therapist, price, and confirmed/unpaid statuses.
- [ ] The declaration and consent appear in that customer's private booking details; another customer receives 404 for the same reference.
- [ ] Refresh/resubmit a successful POST: it must return the same booking rather than create a duplicate.
- [ ] Dashboard shows the saved upcoming record and details link. A past record appears in history.
- [ ] Eligible cancellation/rescheduling actions open real review forms; ineligible actions are disabled and explain the six-hour/state restriction.

## Availability boundaries

- [ ] A 13:00 single booking blocks the same room until 14:30; exactly 14:30 is allowed.
- [ ] A 13:00 family booking blocks the same room until 15:00; exactly 15:00 is allowed.
- [ ] An earlier candidate whose own cleaning would overlap a later booking is rejected.
- [ ] Times outside 13:00–21:00, non-half-hour starts, past starts, invalid dates, invalid room/service combinations and capacity violations are rejected server-side.
- [ ] Latest single/couples start is 20:00; latest family start is 19:30. Cleaning may finish after 21:00.
- [ ] Therapist must be active with a shift covering the entire treatment and no overlapping break/assignment.
- [ ] Two rooms cannot use the same therapist at the same time. With no therapist available, room availability alone is insufficient.
- [ ] A therapist may start another treatment when the previous treatment ends, while the prior room is cleaning.
- [ ] Recheck a stale slot after another booking: the server rejects the conflict.
- [ ] Concurrent same-room submissions yield one confirmation. Concurrent different-room submissions for the last therapist also yield one confirmation.

## Security and responsive behavior

- [ ] Account, health, booking, staffing, and logout state changes enforce CSRF.
- [ ] Bad input arrays and HTML in notes/names cannot bypass validation or execute scripts.
- [ ] Customer cannot change staffing, assign a role/therapist, or forge booking/payment status.
- [ ] API returns JSON 401 without login, 409 without a declaration, and 422 for invalid criteria; responses contain no health answers.
- [ ] At 390px and desktop widths, verify the health form, selection blocks, slots, summary, confirmation and dashboard.
- [ ] Change service/date quickly: outdated availability responses must not replace newer results.
- [ ] Disable JavaScript: health saving, Find available times GET, slot radios, and final POST still work.
- [ ] Test missing/incorrect passwords, duplicate email, session expiry, logout and admin/customer access from Phase 1.

## Automated checks

Use a fresh **separate database ending in `_test`** and a local Apache instance connected to that same database. For example, import the fresh schema into `bella_vista_spa_phase2_test` after changing the creation/USE database name in a copy of the SQL. Do not import it over a customer database. Configure the PHP web server and CLI with the same DB_NAME and connection settings; `config/local.php` takes precedence over environment variables.

```powershell
$env:DB_NAME='bella_vista_spa_phase2_test'
# If using the isolated test MySQL server:
$env:DB_PORT='3307'
& 'C:\xampp\php\php.exe' tests/booking.php
& 'C:\xampp\php\php.exe' tests/booking-http.php 'http://127.0.0.1:8089/bella-vista-spa/public'
& 'C:\xampp\php\php.exe' tests/integration.php 'http://127.0.0.1:8089/bella-vista-spa/public'
```

Replace the URL/port with your actual local test server. `booking.php` runs real database transactions and two independent PHP workers for concurrency. `booking-http.php` exercises the forms/API and staffing page. The account regression suite covers Phase 1 behavior with the new protected booking entry. Fixtures are identified by their own IDs/emails and cleaned in `finally`; these scripts must not be pointed at production.

## Phase 3 customer management checks

- [ ] Cancellation more than six hours before start succeeds; exactly six hours also succeeds; one second late is rejected.
- [ ] Another customer receives 404/authorization rejection on details, payment, cancellation and rescheduling pages.
- [ ] Cancellation requires a reason/CSRF and preserves the payment ledger; room and therapist become available.
- [ ] Verified paid cancellations create an unknown-amount policy review request, without any automatic refund.
- [ ] Duplicate cancellation/reschedule POSTs do not create duplicate actions. Stale revisions cannot overwrite newer changes.
- [ ] Reschedule review shows the proposed date/time; successful confirmation retains appointment ID, service, room, guests, price, duration and payments.
- [ ] Invalid room/staff/cleaning replacement leaves the original allocation unchanged.
- [ ] Two independent rescheduling workers cannot take the same replacement room or last therapist.
- [ ] Dashboard contains upcoming/history sections, actual payment/attempt records, verified paid and remaining figures.
- [ ] Payment page shows base/extras/total/due/remaining, with disabled checkout and an unconfigured downpayment explanation.
- [ ] Forged payment POST, browser paid query flags and unsigned webhook JSON never create a successful payment.
- [ ] Expired holds stop blocking availability even before the cleanup utility runs.
- [ ] Verify desktop/mobile layouts, focus, native validation and server-rendered rescheduling with JavaScript disabled.

Additional suites, against the same separate `_test` database and matching local web server:

```powershell
& 'C:\xampp\php\php.exe' tests/management.php
& 'C:\xampp\php\php.exe' tests/management-http.php 'http://127.0.0.1:8093/bella-vista-spa/public'
```

Use the actual local URL/port for your configured test instance. The `_test` database must be imported/upgraded to Phase 3. Temporary fixture records are deleted after tests. Historical verified-ledger fixtures check preservation/arithmetic only; they are not simulated provider payments.

## Payment integration scenarios are pending

Full provider success, actual downpayment and remaining balance, failed/cancelled/pending gateway outcomes, duplicate authentic webhook/checkout, late payment after expiry and provider-specific invalid signatures are all pending selection/implementation of the official sandbox adapter. See PAYMENT-CONFIGURATION.md for the full table. Do not present these as passed.
