# Architecture and database notes

Public PHP entry points load `app/bootstrap.php`, which configures strict cookie-only sessions, security headers, shared modules and generic exception handling. Public pages use reusable header/footer components. `e()` escapes user and database text, including medical notes. PDO uses prepared statements for all external values.

Authentication stores a user ID in the session and rereads the account's current role on protected requests. Login rotates session ID and CSRF token; inactivity expires after 30 minutes. Cookies are HttpOnly, SameSite=Lax, and Secure on HTTPS. Logout is POST-only with CSRF. Passwords use PHP's password hashing/verification functions. Emails are lowercased and have a case-insensitive unique constraint. A persistent email/IP login-attempt limit protects sign-in.

The app has customer and admin system accounts only. Therapists are staff records without login accounts. Administrator setup tools are CLI-only or role/CSRF protected. The web document root should be `public/`; the repository `.htaccess` also blocks private app/config/database/docs/tests/tools/runtime paths on XAMPP Apache.

## Data model

| Table | Purpose |
|---|---|
| users | Customer/admin details, secure password hashes, accepted account terms |
| services / rooms | Authoritative prices, durations, capacities and seven room codes |
| therapists | Real staff names, active status and update timestamp |
| service_staffing_requirements | Required therapist count; Phase 2 default is one |
| therapist_schedules | Explicit available/unavailable UTC work windows |
| health_declarations | Owner, immutable versioned yes/no answers/notes and consent timestamp |
| appointments | Customer/room/service/declaration, UTC windows, price snapshot, booking status, separate payment status, accepted terms and timestamps |
| appointment_therapists | One or more assigned staff per appointment |
| room_blocks | Maintenance or manual room windows considered in availability |
| business_settings / operating_hours | Timing rules, seven daily hours, unresolved nullable payment/grace/refund policies |
| payments / payment_events | Future gateway records and replay/idempotency identifiers; no online payment integration yet |
| login_attempts | Persistent authentication throttling |
| schema_migrations | Applied schema version tracking |

Composite foreign keys ensure the room belongs to the appointment's service and the declaration belongs to its customer. Unique keys protect email, room code, booking reference, booking request key, and gateway references/idempotency keys. Amounts use DECIMAL in MySQL; Phase 2 catalog amounts are whole pesos. Old appointments have a nullable request key, preserving migration compatibility.

Health answers use a structured JSON object with fixed question keys and notes. The form performs disclosure validation, not clinical assessment. Customer detail access always filters records by the signed-in customer's ID; another user's reference receives 404. The availability endpoint returns scheduling information only.

## Transaction and time model

Scheduling uses Asia/Manila at UI boundaries and UTC for persisted appointment/shift windows. Confirmation locks the settings row, validates all rules using database catalog data, assigns available staff, and commits appointment plus assignments atomically. Staffing writes use the same mutex. Read-only slot checks do not reserve resources. See `PHASE-2.md` for the full interval algorithm and examples.

With checkout disabled, booking preserves the confirmed/unpaid Phase 2 flow. With a configured official adapter and hold duration, a new online-paid booking is awaiting_payment until server verification and valid resource confirmation. The supplied registry is empty: actual provider integration remains pending. Browser flags cannot create payment evidence; raw cards are never collected.

## Migration and operations

Fresh installs use `database/schema.sql`; Phase 1 upgrades use migrations 002 then 003, and Phase 2 upgrades use 003, after backup. The CLI runner checks `schema_migrations` and takes a migration advisory lock. MySQL schema changes are not one atomic transaction, so interrupted upgrades need inspection/backup recovery before retrying.

No users, therapists, health declarations, appointments or payments are seeded. All QA fixtures are created only in a separate `_test` database and removed after testing. A real installation requires staff and shift setup. Payment/downpayment/grace/refund values that have not been confirmed remain unset.

Production deployment, audit/retention tools, final privacy review, and broader operational management are future work. Tests use XAMPP MariaDB; use MySQL 8.0.16+ if relying on enforced CHECK constraints on MySQL Server.

## Phase 3 extension

The `management` module applies owner/revision checks and the six-hour deadline under the existing scheduling mutex. Cancellation releases active allocations by state while retaining staff/payment history. Rescheduling validates replacement resources before atomically swapping schedule/staff; only date/time changes are accepted. `appointment_changes` stores non-medical before/after audit data.

New financial tables are `payment_attempts`, `payment_webhook_events`, `payment_reconciliation_cases`, `refund_requests`, and `cancellation_records`; the original `payments` ledger is extended instead of replaced. Exact centavo helpers handle proposal/balance calculations. Payment attempts, ledger transactions and appointment states are separate. Expired holds never permanently block rooms or therapists.

The provider boundary delegates official raw-body authentication and authenticated receipt retrieval to a reviewed adapter. No adapter/credentials are bundled, and all external provider integration scenarios remain pending. Checkout is disabled in both UI and initiation service. Late/ambiguous received money is queued for review rather than automatically refunded or discarded. See PHASE-3.md and PAYMENT-CONFIGURATION.md.
