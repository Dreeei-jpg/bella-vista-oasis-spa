<?php
declare(strict_types=1);
class PaymentUnavailable extends RuntimeException {}
class InvalidPaymentSignature extends RuntimeException {}
final class CheckoutRequest
{
    public function __construct(public string $publicId, public string $idempotencyKey,
        public int $amountCentavos, public string $currency, public string $returnUrl,
        public string $webhookUrl, public ?string $existingReference) {}
}
final class CheckoutSession
{
    public function __construct(public string $reference, public string $url) {}
}
final class WebhookEnvelope
{
    // Return only after checking the official provider signature against the RAW body.
    public function __construct(public string $eventId, public string $attemptReference) {}
}
final class PaymentObservation
{
    // Authenticated server-to-provider API evidence, never browser-supplied JSON.
    public function __construct(public string $clientReference, public string $attemptReference,
        public string $transactionReference, public string $status,
        public int $amountCentavos, public string $currency) {}
}
interface PaymentProvider
{
    public function isConfigured(): bool;
    // MUST send the same idempotency key on retries and after uncertain timeouts.
    public function createCheckout(CheckoutRequest $request): CheckoutSession;
    public function authenticateWebhook(string $rawBody, array $headers): WebhookEnvelope;
    public function fetchPayment(string $attemptReference): PaymentObservation;
}
