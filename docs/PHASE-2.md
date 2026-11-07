> Historical Phase 2 implementation notes. Current customer management and payment boundaries are documented in PHASE-3.md and PHASE-4.md.

# Phase 2 — implementation guide

## New source files

| File | Responsibility |
|---|---|
| `app/modules/booking.php` | Health validation, time conversion, price/room checks, availability, booking transaction, owner-scoped lists |
| `public/customer/health.php` | Eight-question health declaration and consent before service selection |
| `public/booking.php` | Replaced the Phase 1 entry notice with the real booking interface |
| `public/customer/create-booking.php` | Authenticated, CSRF-protected final booking POST handler |
| `public/api/availability.php` | Authenticated JSON availability endpoint |
| `public/customer/appointment.php` | Saved confirmation/details including therapist and private health answers |
| `public/admin/therapists.php` | Protected real-staff and date-specific shift setup |
| `public/assets/js/booking.js` | Room filtering, price summary, slot fetching, stale-response protection |
| `public/assets/css/booking.css` | Booking, health, confirmation, staffing and high-contrast branding styles |
| `database/migrations/002_booking_engine.sql` | Non-destructive Phase 1 upgrade |
| `tools/migrate.php` | Version-checked CLI migration runner |
| `tools/make-admin.php` | CLI promotion of an existing registered account |
| `tests/booking.php` | Booking business-rule and simultaneous-request tests |
| `tests/booking-worker.php` | Independent worker for concurrency tests |
| `tests/booking-http.php` | Form/API, staffing and private-record integration checks |
| `docs/PHASE-3.md` | Deferred features and unresolved business policies |

Updated files: `app/bootstrap.php`, `app/helpers.php`, `app/modules/catalog.php`, `app/components/header.php`, `app/components/footer.php`, `public/customer/dashboard.php`, `public/admin/index.php`, `public/terms.php`, `public/assets/images/mark.svg`, `database/schema.sql`, `.htaccess`, `Start-Demo.ps1`, `tests/integration.php`, README and documentation. All original public pages, authentication files, photos, fonts, and base/page styles remain in place.

## Booking flow

1. Anonymous visitors are redirected to login. Only customer-role sessions can enter the flow.
2. The health page requires explicit yes/no answers for pregnancy, injury, chronic pain, high blood pressure, skin condition, allergies, surgery, and other conditions. Notes are limited to 2,000 characters. Consent is mandatory.
3. Answers are saved as a versioned, immutable declaration belonging to the customer. Its ID and a random booking request key are stored in the session before treatment selection is unlocked. Reviewing and resaving creates a new declaration and draft key.
4. The customer chooses service, guest count, matching room, date and an available half-hour start. The same values can be rendered server-side without JavaScript by choosing Find available times.
5. The final handler checks CSRF, session draft ownership, health declaration ownership and final terms acceptance. Client price, payment status, appointment status and therapist IDs are ignored.
6. Successful storage creates a confirmed appointment, unpaid payment status, one therapist assignment by default, price snapshot, accepted terms/version, UTC times and a unique reference. The customer is redirected to the saved details page.
7. A retry with the same request key returns the existing customer-owned appointment instead of inserting twice. A new appointment requires a new health/draft step.

## Prices and capacity

The service and room are joined in MySQL, including active status and room/service capacities. Guest counts must be positive integers and cannot exceed either capacity. Family price is `base_price + max(0, guests - base_capacity) × extra_person_fee`: four = ₱4,000, five = ₱4,750, six = ₱5,500. Browser calculations are convenience only; the server computes the stored amount from the catalog.

## Availability and cleaning

All input/output appointment and shift times use **Asia/Manila**. The engine strictly parses calendar dates/times, rejects past starts, reads the weekday's operating hours, and checks the 30-minute grid. Treatments last the service's 60 or 90 minutes and must finish by 21:00.

Room occupancy uses the half-open interval **[start, cleaning end)**. A candidate is rejected when an existing blocking appointment starts before the candidate cleaning end and its cleaning end is after the candidate start. The same overlap check applies to maintenance/room blocks. This checks both earlier and later neighbors, including the new appointment's own cleaning buffer.

| Treatment | Occupied for treatment | Cleaning | Next start in the same room |
|---|---|---|---|
| 60 minutes at 13:00 | 13:00–14:00 | 14:00–14:30 | 14:30 |
| 90 minutes at 13:00 | 13:00–14:30 | 14:30–15:00 | 15:00 |

Exact end boundaries are allowed. The latest 60-minute start is 20:00; the latest 90-minute start is 19:30. A treatment finishing at 21:00 may have its room cleaned until 21:30 because the supplied rule concerns treatment finish time.

Pending, confirmed and completed appointments block their stored windows. Cancelled/no-show records do not block future availability. The application currently creates confirmed records only; cancellation/rescheduling endpoints are not provided.

## Therapist assignment

The practical default is **one therapist per appointment**, stored per service in `service_staffing_requirements`. The engine can select the configured number later without rewriting the transaction.

An eligible therapist must be active, have an explicit available shift covering the entire treatment period, have no overlapping unavailable shift, and have no overlapping treatment assignment. The first eligible staff record by ID is assigned. Room cleaning does not block the therapist; their treatment assignment ends when the treatment ends. Adjacent partial shifts are not merged automatically.

No therapists or shifts are invented. The administrator must enter real staff and publish available shifts. If none qualify, the slot is disabled and the final server check rejects it. Staff have no login accounts.

## Concurrency and failure handling

Every booking transaction first locks the single `business_settings` row with `SELECT ... FOR UPDATE`. The staffing setup page takes the same lock before schedule writes. That deliberately simple scheduling mutex serializes all writers for this seven-room spa; different rooms cannot race for the same last therapist.

Only after taking the lock does the transaction check the request key, room availability and therapist availability, then insert the appointment and assignments and commit. Validation errors or database exceptions roll back. Availability responses are advisory: a stale slot is always rechecked on submission. Unrelated external SQL writes must follow the same lock protocol to receive these concurrency guarantees.

The browser aborts superseded availability requests and discards out-of-order responses. Changing the service, room, guest count or date clears selected slots and disables confirmation until fresh times are available. API responses contain no medical answers. Errors use generic visitor messages; technical details stay in server logs.

## Remaining work

See `PHASE-3.md`. Payment processing and cancellation/rescheduling are explicitly unfinished, and the interface performs no simulated success for them.
