# Bella Vista Oasis Spa — Phase 5.2

The existing application now uses reservation and spa management language throughout. The Quiet Atlas design, official company description and history, and light and dark themes were retained.

| Area | Update |
|---|---|
| Public pages | Removed project language from the home page and gallery, kept the photography disclosure, and refined treatment, account, and visit policy wording. |
| Booking and account | Added clearer reservation and payment labels, renamed the confirmation heading, and removed change buttons for reservations that cannot be changed. Room, therapist, pricing and cancellation rules are enforced as before. |
| Payment Summary | The two-column desktop layout groups reservation details and totals alongside payment choices and method. Mobile shows the summary first. One restrained notice reads “Test checkout — No actual funds will be transferred.” The checkout collects no card numbers, wallet PINs, or banking credentials. |
| Management | Navigation now uses Overview, Appointments, Reservation Calendar, Room Management, Therapist Management, Customer Records, Payments, Financial Reports, and Business Settings. Customer Records is searchable and links to the customer’s actual reservations. Health declarations are excluded from this view. |
| Financial reporting | Test amounts and verified provider receipts are shown separately. Payment History displays legacy test references with a `TEST-` prefix. Administrators can search by either prefix. The saved references, bookings and transaction records are preserved. |
| Error and privacy handling | Public availability and payment errors use plain language. The local server denied access to private configuration, SQL and backup files. The project-level Apache rules also deny source archives and common private file types. |

No database migration is required for Phase 5.2. See the [exact file changes](PHASE-5.2-FILE-CHANGES.md) and [local setup instructions](../README.md).

## Verification

Verified on November 6, 2026 using XAMPP PHP 8.2 and MariaDB. Temporary accounts, appointments and payment records were removed after testing.

| Suite | Passed | Coverage |
|---|---:|---|
| Local site on port 8088 | 84 | 83 HTTP workflow checks plus the database preservation check. Includes registration, sign-in, treatment and therapist selection, room availability, checkout, booking confirmation, both dashboards and financial reporting. |
| Isolated reservation workflow | 112 | 111 HTTP checks plus cleanup and preservation. Includes the no-funds disclosure, terminology, private routes, customer search, test reference aliases, payment retries, role boundaries, shift rules and appointment changes. |
| Booking and payment services | 59 | Concurrent booking, payment idempotency, amount calculations, availability and cancellations. |
| Unavailable checkout and appointment changes | 30 | Customer ownership, CSRF, cancellation and rescheduling, and unavailable checkout without inactive payment controls. |
| Booking HTTP and failure recovery | 54 | Room and staff validation, cleaning, history and a handled database connection failure. |
| Public routes, account and catalog integration | 143 | Public navigation/assets, registration, login, password storage, role checks and service prices. |
| **Total** | **482** | **All final checks passed.** |

The browser confirmed a named therapist and a Card downpayment at a 390px viewport. The recorded ₱240, outstanding ₱560, booking reference, and payment reference matched the customer history and administrator payment row. Customer Records opened the matching appointment list. The mobile calendar kept scrolling inside the room grid. The theme persisted between the customer and management pages. See desktop checkout (screenshot retained privately), mobile dark checkout (screenshot retained privately), and Financial Reports (screenshot retained privately).

## Launch limits

- This delivery was verified locally. Production HTTPS, hosting configuration, backups and recovery, and external security review are separate launch checks.
- Live payments remain disabled. A verified gateway, HTTPS callbacks, approved policies, and reconciliation tests are required before accepting funds. Historical provider records do not carry a per-transaction environment marker. Confirm their origin before treating them as live revenue.
- Gallery photos illustrate the atmosphere. Replace them with approved property photos when available.
- The existing published therapist shifts run through November 12, 2026. Later reservations require new shifts.
