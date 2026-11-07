<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/database.php';
try {
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $lock = $pdo->query("SELECT GET_LOCK('bella_vista_phase2_migration', 10)")->fetchColumn();
    if ((int)$lock !== 1) throw new RuntimeException('Another migration is running. Try again later.');
    try {
        foreach (['002_booking_engine','003_customer_management','005_demo_admin','006_therapist_preferences','007_admin_account_security'] as $version) {
        $query = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
        $query->execute([$version]);
        if ((int)$query->fetchColumn() === 1) {
            echo "$version already applied.\n";
        } else {
            $sql = file_get_contents(__DIR__ . '/../database/migrations/'.$version.'.sql');
            // The CLI follows configured DB_NAME. phpMyAdmin imports use the SQL's USE statement.
            $sql = preg_replace('/^USE bella_vista_spa;\s*$/m', '', $sql);
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
                if (trim($statement) !== '') $pdo->exec($statement);
            }
            echo "$version applied. Existing records preserved.\n";
        }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bella_vista_phase2_migration')");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\nBack up/inspect the database before retrying a partially applied migration.\n");
    exit(1);
}
