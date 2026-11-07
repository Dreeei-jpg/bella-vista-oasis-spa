<?php
declare(strict_types=1);

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = str_replace('\\', '/', dirname($script));
    if (preg_match('~/(auth|customer|admin|api)$~', $base)) { $base = str_replace('\\', '/', dirname($base)); }
    return rtrim($base, '/.') . '/' . ltrim($path, '/');
}
function redirect(string $path): void { header('Location: ' . url($path), true, 303); exit; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        http_response_code(403);
        $pageTitle = 'Please refresh';
        require __DIR__ . '/components/header.php';
        echo '<section class="container page-intro"><p class="eyebrow">Session protection</p><h1>Please refresh.</h1><p>This form has expired. Reload the page and try again.</p></section>';
        require __DIR__ . '/components/footer.php';
        exit;
    }
}
function post_string(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function money(int|string $amount): string { return preg_replace('/\.00$/', '', peso_amount(decimal_centavos($amount))); }
function form_errors(array $errors): void
{
    if (!$errors) return;
    echo '<div class="notice error" role="alert" tabindex="-1"><strong>Please check the following:</strong><ul>';
    foreach ($errors as $error) echo '<li>' . e($error) . '</li>';
    echo '</ul></div>';
}
function appointment_status_label(string $status): string
{
    return $status === 'awaiting_payment' ? 'Pending payment' : ucfirst(str_replace('_', ' ', $status));
}

function short_booking_reference(string $reference): string
{
    return strlen($reference) > 12 ? substr($reference, 0, 3).'…'.substr($reference, -6) : $reference;
}

function appointment_change_label(string $action): string
{
    return match ($action) {
        'demo_payment_completed' => 'Test payment recorded',
        'demo_hold_expired' => 'Checkout hold expired',
        'demo_checkout_cancelled' => 'Checkout abandoned',
        default => ucfirst(str_replace('_', ' ', $action)),
    };
}

