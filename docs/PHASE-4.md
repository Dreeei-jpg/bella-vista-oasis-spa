# Phase 4 and unresolved decisions

This is the historical Phase 4 plan. [Phase 5](PHASE-5.md) describes the implemented administration tools and separate payment simulation. The real gateway and production payment policy decisions below are still open.

## Remaining integration/configuration dependencies

- Select a gateway and implement its official sandbox checkout/API/signature adapter, configure private credentials/trusted URLs, and pass the provider integration tests. Real checkout remains disabled until then.
- Approve the downpayment percentage and checkout hold duration; both remain unset by default.
- Finalize refund eligibility/calculation rules and administrator review/refund operations. No automatic refund or blanket full-payment forfeiture is implemented.
- Confirm any late-arrival grace period and any replacement for the one-therapist default.

## Reserved Phase 4 features

- Full administrator appointment calendar and operational appointment management.
- Advanced room occupancy and cleaning dashboard.
- Expanded administrator therapist scheduling tools; the existing small Phase 2 staff/shift setup is preserved.
- Administrative payment monitoring, review-case resolution and daily revenue reports.
- Email verification, password recovery and automated booking notifications.
- Advanced health suitability/clinical approval.
- Production deployment, privacy/retention tooling and final compliance review.

Customer cancellation/rescheduling and persistent audit/history are implemented in Phase 3. Date/time-only changes retain the room/service/guests/duration/price and financial history. Price-changing alterations need a separately defined payment-adjustment workflow rather than an automatic charge/refund.
