<?php
declare(strict_types=1);
$paymentConfig = [
    'enabled' => false,
    'provider' => null,
    'environment' => 'sandbox',
    'public_origin' => null, // Trusted HTTPS origin plus optional app/public base path.
    'checkout_hosts' => [], // Exact hosts from the selected provider's official docs.
];
if (is_file(__DIR__.'/payments.local.php')) {
    $paymentConfig = array_replace($paymentConfig, require __DIR__.'/payments.local.php');
}
return $paymentConfig;
