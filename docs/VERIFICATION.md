# Phase 3 verification

Verified on **5 November 2026**, using XAMPP PHP **8.2.12**, MariaDB **10.4.32**, and Apache **2.4.58** on Windows. Tests used separate `_test` databases and local Apache port 8093, without touching normal XAMPP customer databases.

## 295 automated application checks passed

| Suite | Checks | Coverage |
|---|---:|---|
| `tests/management.php` | 44 | Exact centavos/rounding, full/downpayment proposals, six-hour boundaries, ownership/revisions, cancellations, ledger preservation, refund-review records, rescheduling, unavailable therapist rejection, expired holds and disabled-payment boundaries |
| `tests/management-http.php` | 30 | Customer forms, review/confirm workflow, CSRF, authorization, forged fields/redirect flags, disabled checkout/webhook, history and late-action UI |
| `tests/booking.php` | 50 | Original room/capacity/pricing/cleaning/staffing rules and concurrent booking races |
| `tests/booking-http.php` | 42 | Original booking/health/availability/staff setup endpoints and customer detail privacy, updated to expect real cancellation links |
| `tests/integration.php` | 129 | Existing public pages/assets, account creation, hashing, duplicate email, login/logout/session rotation, roles, CSRF/XSS and throttling |

Independent PHP worker races verified that concurrent reschedules cannot acquire the same replacement room or the last therapist across different rooms. The losing room request retained its original allocation. Explicit unavailable-staff replacement also preserved the old schedule.

Cancellation tests checked more than six hours, exactly six hours and one second late. Historical ledger fixture rows remained byte-for-byte unchanged during cancellation/rescheduling. Timely paid cancellation generated a refund request with unknown amount and pending policy review, not a refund transaction. Those historical ledger fixtures exist only in the disposable test database; they do not simulate provider payment success.

## Migration and reconciliation

The fresh schema imported successfully. The Phase 2-to-3 migration preserved an existing account/password hash, declaration, appointment, payment row and legacy timestamps/values in a separate upgrade test. A repeated CLI migration skipped completed versions. No table was dropped or original reservation converted into a payment hold.

The reconciliation utility ran with the provider unconfigured and reported no provider attempts processed, no open cases and no expired holds after cleanup. Its provider-call branches remain subject to the selected gateway's integration tests.

## Visual verification

Completed date/time rescheduling and cancellation through the browser with an unpaid, clearly labelled temporary test appointment. Confirmed updated date/time, unchanged five-person family price of ₱4,750, cancellation reason/timestamp, audit history, released eligibility and empty financial history.

Reviewed the payment, cancellation, rescheduling and detail screens at **1440×1000** and **390×844**. Payment layout also had no horizontal overflow at **320×800**. Checkout remained visibly disabled, with exact due/remaining figures and an explanation of missing configuration. Browser console reported no application errors/warnings during this flow. Existing logo, fonts and palette were preserved.

Screenshots under `screenshots/phase-3/` show unpaid disposable QA data, not real customers or financial transactions. Fixtures are removed after review. Prior-phase screenshots are historical artifacts.

## Gateway tests are pending, not passed

No provider or credentials were selected. The registry is empty, actual checkout is disabled, and no external charge, payment success, refund, or signed gateway event was simulated. All ten provider-dependent scenarios requested in the brief remain **pending official sandbox integration**: full payment, downpayment, balance after actual payment, failed/cancelled/pending provider results, authentic duplicate webhook/checkout, payment after hold expiry, and provider-specific invalid-signature verification. See PAYMENT-CONFIGURATION.md.

Local checks prove exact arithmetic, persistence and fail-closed boundaries; they do not certify an unimplemented provider adapter. MySQL Server 8 was not separately run (database tests used XAMPP MariaDB), the full session-idle period was not waited out, and production deployment/compliance remain deferred.
