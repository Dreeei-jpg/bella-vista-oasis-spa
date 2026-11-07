# Phase 3 implementation and source map

## Created files

| Path | Purpose |
|---|---|
| `database/migrations/003_customer_management.sql` | Additive upgrade, price/duration snapshots, holds/revisions and financial/audit tables |
| `app/modules/management.php` | Ownership, six-hour eligibility, revision checks, cancellation and atomic rescheduling |
| `app/modules/money.php` | Exact integer-centavo conversion, formatting and percentage rounding |
| `app/modules/payments.php` | Proposals, readiness checks, attempts, holds, provider verification orchestration, replay/reconciliation and history |
| `app/payments/Provider.php` | Typed provider interface and request/session/event/observation objects |
| `app/payments/registry.php` | Empty server-owned adapter allowlist until provider selection |
| `config/payments.php`, `config/payments.local.example.php` | Disabled-by-default public-safe settings and private override example |
| `public/customer/payment.php` | Payment summary, full/downpayment options and protected initiation |
| `public/customer/payment-result.php` | Stored pending/verified/failed/cancelled/expired/assistance states |
| `public/api/payment-webhook.php` | POST-only, body-limited provider-authenticated verification entry |
| `public/customer/cancel.php` | Cancellation review, reason and CSRF-protected confirmation |
| `public/customer/reschedule.php` | Replacement date/slot search, review and final atomic update |
| `app/components/payment-records.php` | Owner-scoped actual transaction/attempt history table |
| `public/assets/css/management.css` | Quiet Atlas management/payment styling |
| `tools/reconcile-payments.php` | CLI expiry, local ledger/status rebuild, provider reconciliation and review-case listing |
| `tests/phase3-fixtures.php` | Isolated disposable test data helpers |
| `tests/management.php`, `tests/management-worker.php` | Deadline, ownership, preservation, exact-money, disabled-payment and concurrency tests |
| `tests/management-http.php` | Customer page/API/CSRF/ownership workflow checks |
| `docs/PAYMENT-CONFIGURATION.md`, `docs/PHASE-4.md` | Integration dependencies, pending tests and next-phase boundaries |

## Modified files

`app/modules/booking.php`, `app/database.php`, `app/helpers.php`, `app/components/header.php`, `public/booking.php`, `public/customer/create-booking.php`, `public/customer/appointment.php`, `public/customer/dashboard.php`, `public/admin/therapists.php`, `public/auth/register.php`, `public/terms.php`, `database/schema.sql`, `tools/migrate.php`, `.gitignore`, `tests/booking-http.php`, README and documentation. Existing photos, fonts, SVG logo and core public/authentication pages remain intact. The full archive contents are listed in `FILE-LIST.txt`.

## Cancellation

The signed-in owner opens a dedicated review page with the reference/service/time, verified amount paid, local deadline and refund policy explanation. Confirmation requires a reason, CSRF token, request key and expected appointment revision.

Inside the same scheduling mutex used by booking, the service rereads ownership and current state, checks the revision and compares Philippine server time with `start - 6 hours`. Equality is permitted. A late request is rejected and directed to the administrator. No late-cancellation/refund policy is guessed.

Successful cancellation writes the cancellation reason/timestamps/deadline, changes appointment state, terminates active attempts and records an audit action. Assignment rows remain historical, while the cancelled status excludes them from active room/therapist availability. Existing payments are never modified. When verified money or a reconciliation case exists, a refund request is created with an unknown amount and pending policy review. A timely self-service cancellation has zero known downpayment forfeiture; the separate late rule is displayed but not silently applied to a timely action.

## Rescheduling

Only date/time can change. The controller exposes no price/service/guest/room editing workflow. It displays replacement slots from the same server availability logic, excluding only the current appointment, then requires a separate review/confirmation POST.

The transaction acquires the scheduling mutex, rereads the owner/revision/deadline, validates the replacement with the saved duration, checks room occupancy through the new cleaning end and finds available therapists. The original reservation is not removed first. Only after all checks pass are times and staff assignments updated together, revision incremented, and before/after times/staff saved in the audit. Failure rolls back. Price, service, room, guest count, payment records and appointment ID remain unchanged. Duplicate requests return their applied action; stale requests with another key cannot overwrite newer changes.

## Financial consistency

All calculations use integer centavos or MySQL DECIMAL strings. Full payment proposes the outstanding balance. An approved percentage is converted to integer basis points and rounded half up; the remaining balance is calculated on the server. NULL percentage disables downpayment. The original price and base/extra portions are saved as snapshots.

The existing `payments` table remains the immutable received-money ledger. `payment_attempts` tracks attempted checkout separately. `payment_webhook_events` provides provider/event uniqueness and a payload hash; raw webhook bodies or card data are not stored. Review cases preserve ambiguous/late/mismatched receipts rather than automatically crediting or refunding them.

When checkout is configured, new online-paid appointments use expiring `awaiting_payment` holds. Both room and therapist SQL ignore expired holds immediately. Failed/cancelled/expired attempts release online holds; earlier Phase 2 confirmed reservations retain their behavior. Configuration is intentionally unset in this delivery, so new normal bookings remain confirmed/unpaid with disabled checkout.

The webhook authenticates the raw provider event through the reviewed adapter and fetches an independent server receipt. Verification checks attempt/client identity, amount, currency, transaction uniqueness and hold/resource validity under the scheduling mutex. Browser redirect flags never prove payment. Actual provider-specific signature/API work is pending; no adapter has been invented. Read `PAYMENT-CONFIGURATION.md` for the exact contract and test status.

## UI and audit

Dashboard and detail pages show real appointment/payment records, verified paid/remaining figures, cancellation/reschedule eligibility and private health data only for the owner. Cancelled appointments remain in history. New state styles use forest/stone/clay without replacing the established design. Operation audits contain scheduling/state/staff identifiers, not medical declarations or payment secrets.

## Deferred work

Real gateway selection/implementation/configuration and provider sandbox tests are still required. Full administrative operations and other reserved features are in `PHASE-4.md`.
