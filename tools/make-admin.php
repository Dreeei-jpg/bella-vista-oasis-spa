<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit("Usage: php tools/make-admin.php existing-account@example.com\n");
$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/modules/admin-security.php';
try {
    with_admin_security_lock(function (PDO $pdo) use ($email): void {
        admin_security_transaction($pdo, function () use ($pdo,$email): void {
            if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0) {
                throw new AdminSecurityException('An administrator already exists. Use Account & Security to invite another administrator.');
            }
            $query = $pdo->prepare("SELECT id FROM users WHERE email=? AND role='customer' AND account_status='active'");
            $query->execute([$email]);
            $id = $query->fetchColumn();
            if (!$id) throw new AdminSecurityException('Account not found. Register the first administrator account first.');
            $query = $pdo->prepare("UPDATE users SET role='admin',session_version=session_version+1 WHERE id=?");
            $query->execute([$id]);
            admin_security_event($pdo,'administrator_bootstrapped',(int)$id,(int)$id);
        });
    });
    echo "First administrator account enabled. Sign out and sign in again.\n";
} catch (AdminSecurityException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL); exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Could not enable the first administrator. Check the database and apply migrations first.\n"); exit(1);
}
