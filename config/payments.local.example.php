<?php
// Copy to payments.local.php only after implementing a reviewed provider adapter.
return [
    'enabled' => false,
    'provider' => null,
    'environment' => 'sandbox',
    'public_origin' => null,
    'checkout_hosts' => [],
    // Read provider-specific secrets from getenv() inside the adapter.
];
