# Payment configuration and verification boundary

## Delivery state

**No provider, credentials, official provider documentation, or approved hold duration have been supplied.** The registry is empty and real checkout is disabled. There is no fake/sandbox-success adapter. The payment page and POST handler both enforce this boundary. A real selected-provider integration is still required; entering an API key alone does not enable a working gateway.

The supplied PHP code provides persistent attempts/transactions, exact amount proposals, idempotency, temporary-hold rules, result states, a signature-authentication interface, server verification orchestration, replay recording and reconciliation cases. Its live provider-dependent behavior is unverified until the official adapter is implemented and tested.

## Steps after selecting a provider

1. Obtain the chosen gateway's official sandbox API and webhook-signature documentation. Use its actual test environment and documented currency/amount units.
2. Implement `PaymentProvider` in `app/payments/` using an authenticated server HTTP client with TLS verification, timeouts and sanitized errors. No raw card data may pass through this application.
3. Register a server-owned factory in `app/payments/registry.php`. Do not accept adapter classes, files, provider names or URLs from customer requests.
4. Copy `config/payments.local.example.php` to `config/payments.local.php`. Set the registered provider slug, environment, an exact trusted HTTPS public origin/base path, and the provider's exact checkout-host allowlist. Keep `enabled=false` while validating. Store provider-specific credentials outside `public/`, preferably as environment variables read by that adapter. The local config is ignored and excluded from distribution.
5. In `business_settings`, enter the business-approved `checkout_hold_minutes`. It defaults to NULL and no deadline has been invented. Enter `downpayment_percentage` only after approval; NULL or zero keeps downpayment unavailable. The PHP calculation accepts an approved percentage greater than zero and at most 100, with centavo rounding half up.
6. Configure the provider's webhook URL as the trusted public base plus `/api/payment-webhook.php`, and its return address using the supplied `CheckoutRequest`. A local XAMPP address is not externally reachable; use an approved HTTPS sandbox development endpoint when gateway testing is arranged. Production deployment is deferred.
7. Complete all pending integration scenarios below before enabling checkout. Enable only the reviewed, tested adapter. Keep its webhook credentials available to verify late events even if new checkout is subsequently disabled.

Do not put keys in the ZIP, web pages, logs, URLs or Git. The source intentionally contains no example credentials or invented provider endpoints.

## Adapter contract

- `isConfigured()` checks the selected environment's required private configuration without making a payment.
- `createCheckout(CheckoutRequest)` sends the **same request idempotency key** on all retries, the exact integer centavo amount/currency, the public attempt ID as a provider-bound client reference, and trusted return/webhook URLs. Convert to the provider's documented unit exactly. An uncertain timeout must be recoverable using the same key; do not initiate another charge.
- `authenticateWebhook(rawBody, headers)` must follow the provider's official signature, timestamp and replay requirements against the exact raw bytes. Reject unauthenticated events with `InvalidPaymentSignature`. Do not copy a generic guessed HMAC algorithm.
- `fetchPayment(providerAttemptReference)` makes an authenticated server-to-provider verification request and normalizes its response into `PaymentObservation`. The client reference and attempt reference must be independently confirmed by the provider, not copied from a browser return query.

No generic signature implementation can honestly substitute for the selected provider's documented protocol. That implementation remains blocked on provider selection/documentation.

## Initiation and hold consistency

With checkout unconfigured, new reservations retain the Phase 2 confirmed/unpaid behavior. Existing reservations are preserved by migration. With checkout ready, new bookings are stored as `awaiting_payment` with a configured expiry clamped to the appointment start; they block rooms/therapists only while unexpired.

Payment initiation checks ownership, CSRF in the controller, terms, revision, state, amount and idempotency. One active payment attempt per appointment is reused. The provider call runs outside the database lock; afterward the service checks whether the attempt is still usable before exposing a checkout link. Returned destinations must use HTTPS and an exact configured host. Expired/failed/cancelled online checkout releases the temporary reservation; a failed attempt on an older confirmed reservation does not cancel that reservation.

An initiation timeout is recorded for reconciliation and is not labelled payment failure or success. An idempotent retry can recover a session with the same key. Browser return flags are ignored. The result page reads stored state; an explicit verification POST or the CLI may request authenticated provider verification.

## Verification and records

The webhook controller has a body-size limit and accepts POST only. It delegates raw-body authentication, then independently fetches provider evidence. The service checks the client/attempt association, transaction reference, actual amount/currency and outstanding balance. Event IDs are unique per provider; ledger transactions are unique per provider/reference and use deterministic idempotency keys.

A valid verified payment is stored with a server verification timestamp. A valid live hold must still have safe room and therapist availability before appointment confirmation. Amount/currency mismatches, duplicate-charge conflicts, expired/cancelled holds or unavailable resources go to reconciliation. Matched received money is retained as a verified transaction requiring allocation review instead of being silently discarded or credited to the wrong booking. An unknown receipt is retained in a reconciliation case until its owner/booking can be established.

`payment_summary()` counts only verified, allocated ledger records; pending/failed attempts never reduce the balance. Historical verified/refunded transactions and refund-type entries are accounted for without rewriting them. Review allocations do not automatically reduce the customer's balance. No refund is issued by this phase.

`tools/reconcile-payments.php` expires holds, rebuilds local status summaries from verified records, checks provider-backed attempts when verification is configured, and prints non-medical open review cases. It does not resolve refund policy decisions, charge money, or fabricate provider results. Provider-specific reference recovery after uncertain initiation is part of the selected adapter's integration work.

## Pending external integration tests

All of these remain **pending selected-provider sandbox integration**, not passed:

| Scenario | Current status |
|---|---|
| Full payment with provider-verified success | Pending |
| Approved downpayment accepted by gateway | Pending; percentage arithmetic is separately unit-tested |
| Remaining balance after actual provider payment | Pending; legacy-ledger arithmetic is separately tested |
| Gateway-declared failed payment | Pending |
| Actual provider checkout cancellation | Pending |
| Provider pending verification result | Pending |
| Duplicate authentic webhook delivery | Pending |
| Duplicate real checkout submission | Pending |
| Actual payment received after a hold expires | Pending |
| Provider-specific invalid signature rejection | Pending; unconfigured webhook rejection is separately tested |

Local tests use disposable historical ledger fixtures to verify preservation, balance arithmetic and refund-review behavior. They never contact a provider, simulate a successful gateway transaction, or claim real money was received.
