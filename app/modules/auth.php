<?php
declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $query = db()->prepare('SELECT id, full_name, email, phone, role, created_at, last_login_at, account_status, session_version FROM users WHERE id = ?');
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch();
    if (!$user || $user['account_status'] !== 'active' || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 1)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['user_role']=$user['role']; // Navigation only; authorization always re-reads the database.
    return $user;
}
function require_role(string $role, ?string $destination = null): array
{
    $user = current_user();
    if (!$user) {
        if ($destination === 'booking') $_SESSION['after_login'] = 'booking';
        redirect('/auth/login.php');
    }
    if ($user['role'] !== $role) {
        http_response_code(403);
        $pageTitle = 'Access restricted';
        require __DIR__ . '/../components/header.php';
        echo '<section class="container page-intro"><p class="eyebrow">403 · Restricted</p><h1>A private space.</h1><p>Your account does not have access to this page.</p></section>';
        require __DIR__ . '/../components/footer.php';
        exit;
    }
    return $user;
}
function sign_in(int $id, string $role='customer', int $version=1): void
{
    $intent = array_intersect_key($_SESSION, array_flip(['after_login','booking_service_id']));
    session_regenerate_id(true);
    $_SESSION = $intent + ['user_id'=>$id, 'user_role'=>$role, 'session_version'=>$version, 'last_activity'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
}

function normalize_account_email(string $email): string
{
    return strtolower(trim($email));
}

function verify_account_password(#[SensitiveParameter] string $password, string $hash): bool
{
    if ($password === '' || strlen($password) > 512 || str_contains($password, "\0")) return false;
    if (str_starts_with($hash, '$2') && strlen($password) > 72) return false;
    return password_verify($password, $hash);
}
function authentication_destination(string $role): string
{
    $next = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    if ($role === 'admin') return '/admin/index.php';
    return $next === 'booking' ? '/booking.php' : '/customer/dashboard.php';
}
function validate_registration(array $data): array
{
    $errors = [];
    if (mb_strlen($data['full_name']) < 2 || mb_strlen($data['full_name']) > 100) $errors[] = 'Enter your full name using 2–100 characters.';
    if (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($data['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{7,25}$/D', $data['phone'])) $errors[] = 'Enter a valid phone number, or leave it blank.';
    if (strlen($data['password']) < 12 || strlen($data['password']) > 72) $errors[] = 'Use a password between 12 and 72 bytes (usually 12–72 characters).';
    if ($data['password'] !== $data['password_confirmation']) $errors[] = 'The passwords do not match.';
    if ($data['terms'] !== '1') $errors[] = 'Please accept the terms and conditions.';
    return $errors;
}
