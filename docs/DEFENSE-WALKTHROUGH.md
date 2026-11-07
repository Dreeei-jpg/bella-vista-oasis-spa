# College defense demonstration walkthrough

## Before presenting

1. Start XAMPP/MySQL and the configured application. On this computer, run `Start-Demo.ps1` if the existing http://127.0.0.1:8088/ server is stopped. Keep the terminal open. `tools/check-installation.php` should report connected/healthy and migrations 002/003/005/006/007.
2. Use separate customer and administrator accounts. The existing admin account/password was not changed. On a fresh installation, register your administrator and promote that explicitly chosen email with `tools/make-admin.php`; never publish a password in slides or source.
3. Ensure the intended future date has active staff and a full available shift. Keep real employee identities and shift schedules in the private database. Publish available dates through administration. For an intentionally fictional local development presentation, follow README's guarded `tools/seed-demo.php` instructions; those staff remain visibly `[DEMO]`.
4. Check **Business Settings**: checkout enabled, 30% prototype downpayment, 15-minute hold. These are demonstration assumptions, not approved payment policy.
5. Choose a fresh customer email. Use fictional medical answers and notes for the demonstration. Keep the original customer/admin records and reservations intact.

## Opening explanation

“Bella Vista Oasis Spa uses PHP, MySQL, HTML, CSS and vanilla JavaScript. Customer and administrator screens read the same database. The Quiet Atlas design is retained. Payments in this college prototype are simulated: no actual money is transferred, no real provider is contacted, and no card or wallet credentials are collected.”

## Main customer-to-admin scenario

1. Open **Register**, enter the presentation customer's details, accept the prototype terms, and create the account. Explain password hashing and duplicate-email prevention.
2. Sign out and sign in with that account to demonstrate the stored credentials and customer dashboard.
3. Open **Treatments → Single Room Massage → Book now**. Authentication and the health declaration gate the selection form. Complete the fictional health disclosure and consent. Explain that the form records information rather than medically approving treatment.
4. Select Single Room Massage, one guest, and a future date with a published shift.
5. Click **Find available times**. Show enabled half-hour starts and disabled unavailable starts with their actual reasons.
6. Select **1:00 PM**, then **SR-1**. Select a named available therapist and show **Any Available Therapist** as the alternative. Review the customer, room, therapist, 13:00–14:00, duration and ₱800 total.
7. Choose **Full Payment**, review policies, and click **Continue to Payment**.
8. Point to the **Test checkout — No actual funds will be transferred.** notice. Explain that the reservation is an expiring hold until completion.
9. Show the payment choices: GCash, Maya, and Card. Test checkout asks for no payment credentials.
10. Review **₱800 amount to record / ₱0 remaining balance after checkout**, accept the booking terms, and click **Confirm reservation**.
11. Show the confirmation reference and **Payment: Recorded** state. Explain that the backend rechecked room/staff/price under a database lock, wrote an `is_demo=1` receipt, and confirmed atomically.
12. Open the customer dashboard. Show the same reference, room/time, total, simulated paid amount, zero balance, and **Payment history** table with the test receipt.
13. Sign out. Sign in with the administrator account; it redirects to administration.
14. Open **Appointments**, search the reference, and show the exact same customer, SR-1, therapist, date, 13:00–14:00, ₱800 total, ₱800 demo amount and zero balance. Refresh to demonstrate persistence.
15. Open **Reservation Calendar**, choose that appointment day. Show SR-1 occupied at 13:00 and 13:30, cleaning at 14:00, and free at 14:30. Open the appointment block and use **Copy reference** to copy the full ID. The room grid and appointment list both show text statuses.
16. Open **Payments**, filter by reference, and show the matching `TEST-…` receipt and **Test Mode** label.
17. Open **Financial Reports**. Appointment counts use appointment date; simulated revenue uses transaction date. Select today to show the new simulated receipt in revenue, and the appointment day for booking counts/balance.
18. With another customer account, try SR-1 on that date at 13:00 and 14:00. Both the treatment and cleaning interval are unavailable. The server also rejects a stale/forged conflicting submission; JavaScript alone is not trusted.

## Additional short demonstrations

### Downpayment and balance

Create a separate Single Room booking at a genuinely free time. Select **30% Downpayment** and **Any Available Therapist** and update the checkout summary: **₱240 simulated now / ₱560 remaining** at the default 30%. Complete it and show the partial demo payment state in customer and admin views. The remaining balance can later be simulated through Payment & balance. Explain that the percentage is editable by an administrator and remains a prototype assumption.

### Administration and change management

- For a future confirmed appointment, assign another available therapist if one is scheduled. The same MySQL appointment and audit history update. A therapist cannot be assigned across another reservation or a published break.
- Safely reschedule to a free replacement time. Show the unchanged reference/price/receipt and the updated customer/admin calendar. A failed replacement keeps the original time reserved.
- Customer cancellation/rescheduling needs at least six hours' notice, including the exact boundary. A future appointment cannot be marked completed prematurely just for the presentation.
- Cancel a separate demo-paid appointment. Show its preserved history and explicitly simulated refund-policy request. An administrator can record review notes/state; no approved refund entitlement or automatic refund is invented.
- Add or edit staff, choose service qualifications, and publish a half-hour shift between 1:00 PM and 9:00 PM. Show the linked assigned appointments. A shift change that would leave an existing booking without coverage is rejected.
- Switch the theme in the header or in Administration. Refresh to show that the preference persists.

### Failure and retry safety

- Abandon an unpaid demo hold and show that its resources are released.
- Let a short, explicitly configured demo hold expire on a separate test booking. Completion is rejected; expired holds do not permanently occupy rooms.
- Explain the tested concurrency results: independent PHP workers could not acquire the same room/last therapist or duplicate a receipt.
- A server/database availability failure is shown as an error, not “No rooms available.” Failure injection was performed only on an isolated development copy; do not stop the real presentation database to demonstrate it.

## Architecture explanation for questions

- **One source of truth:** customers, calendar, monitoring and reports query the same appointment and receipt records.
- **Scheduling windows:** rooms include treatment plus 30-minute cleaning; therapists include treatment only. Half-open intervals allow exact boundaries.
- **Atomic operation:** a scheduling mutex row and transaction protect room/staff assignment and demo confirmation.
- **Payment separation:** demo receipts are in `demo_payments` with `is_demo=1`; the real verified ledger is separate and unchanged.
- **Server authority:** prices, capacity, expiry, identity, revision, CSRF and resource availability are revalidated on POST.
- **Privacy:** only the customer sees their saved health answers in booking details; the admin appointment interface omits them.
- **Evidence:** 482 automated checks passed, plus an actual browser completion and admin verification. QA data was cleaned up and original records preserved.

## Closing explanation

“The complete prototype runs through real PHP handlers and persistent MySQL records. Online money movement remains a simulation, and actual refunds require a separate approved policy/integration. Real staff dates must be maintained, or clearly labelled fictional development shifts can be used for the college presentation.”
