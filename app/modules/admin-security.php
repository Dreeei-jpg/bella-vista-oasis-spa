<?php
declare(strict_types=1);

final class AdminSecurityException extends RuntimeException {}

/** Serialize administrator membership, credentials, and invitation changes per database. */
function with_admin_security_lock(callable $work): mixed
{
    global $config;
    $pdo = db();
    $key = 'bv-admin-'.substr(hash('sha256', $config['db_name']), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$key]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new AdminSecurityException('Account management is busy. Please try again shortly.', 503);
    }
    try {
        return $work($pdo);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$key]);
    }
}

function admin_security_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function admin_security_event(PDO $pdo, string $type, int $actor, int $target): void
{
    $event = $pdo->prepare('INSERT INTO admin_security_events (event_type,actor_user_id,target_user_id) VALUES (?,?,?)');
    $event->execute([$type,$actor,$target]);
}

/** Failed attempts are committed separately so rolling back a change cannot reset the limit. */
function reauthenticate_admin(PDO $pdo, int $id, int $version, #[SensitiveParameter] string $password): array
{
    $query = $pdo->prepare("SELECT id,email,password_hash,session_version FROM users WHERE id=? AND role='admin' AND account_status='active'");
    $query->execute([$id]);
    $actor = $query->fetch();
    if (!$actor || (int)$actor['session_version'] !== $version) {
        throw new AdminSecurityException('Your session has changed. Please sign in again.', 403);
    }
    $query = $pdo->prepare('SELECT attempts FROM admin_security_throttles WHERE user_id=? AND started_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE');
    $query->execute([$id]);
    $attempts = (int)$query->fetchColumn();
    if ($attempts >= 5) {
        throw new AdminSecurityException('Too many password attempts. Please wait up to 15 minutes before trying again.', 429);
    }
    if (!verify_account_password($password, $actor['password_hash'])) {
        if ($attempts) {
            $query = $pdo->prepare('UPDATE admin_security_throttles SET attempts=attempts+1 WHERE user_id=?');
        } else {
            $query = $pdo->prepare('INSERT INTO admin_security_throttles (user_id,attempts,started_at) VALUES (?,1,UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE attempts=1,started_at=UTC_TIMESTAMP()');
        }
        $query->execute([$id]);
        throw new AdminSecurityException('The current password is incorrect.', 422);
    }
    $query = $pdo->prepare('DELETE FROM admin_security_throttles WHERE user_id=?');
    $query->execute([$id]);
    return $actor;
}

function new_admin_password_hash(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): string
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12 ||
        mb_strlen($password, 'UTF-8') > 128 || strlen($password) > 512 || str_contains($password, "\0")) {
        throw new AdminSecurityException('Use a password or passphrase of 12 to 128 characters.', 422);
    }
    if ($password !== $confirmation) throw new AdminSecurityException('The new passwords do not match.', 422);
    if (in_array('argon2id', password_algos(), true)) return password_hash($password, PASSWORD_ARGON2ID);
    if (strlen($password) > 72) throw new AdminSecurityException('This installation supports passwords of up to 72 bytes. Try a shorter passphrase.', 422);
    return password_hash($password, PASSWORD_DEFAULT);
}

function validated_admin_email(string $input): string
{
    $email = normalize_account_email($input);
    if (strlen($email) > 254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        throw new AdminSecurityException('Enter a valid email address.', 422);
    }
    return $email;
}

function admin_security_save_email(PDOStatement $query, array $parameters): void
{
    try {
        $query->execute($parameters);
    } catch (PDOException $exception) {
        if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
            throw new AdminSecurityException('That email address already belongs to an account.', 422);
        }
        throw $exception;
    }
}

function revoke_open_admin_invitations(PDO $pdo, int $userId): int
{
    $query = $pdo->prepare('UPDATE admin_invitations SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL');
    $query->execute([$userId]);
    return $query->rowCount();
}

function issue_admin_invitation(PDO $pdo, int $actor, int $target): array
{
    global $config;
    $minutes = max(5, min(1440, (int)$config['admin_invitation_minutes']));
    revoke_open_admin_invitations($pdo, $target);
    $token = bin2hex(random_bytes(32));
    $query = $pdo->prepare("INSERT INTO admin_invitations (user_id,token_hash,expires_at,created_by)
        VALUES (?,?,UTC_TIMESTAMP()+INTERVAL $minutes MINUTE,?)");
    $query->execute([$target,hash('sha256',$token),$actor]);
    return ['token'=>$token,'minutes'=>$minutes];
}

function change_admin_credentials(PDO $pdo, array $actor, string $action, #[SensitiveParameter] array $input): array
{
    $id = (int)$actor['id'];
    if ($action === 'change_email') {
        $email = validated_admin_email($input['email']);
        if ($email === $actor['email']) throw new AdminSecurityException('Enter a different email address.', 422);
        $query = $pdo->prepare('UPDATE users SET email=?,session_version=session_version+1 WHERE id=?');
        admin_security_save_email($query, [$email,$id]);
        $message = 'Email address updated. Use your new address the next time you sign in.';
        $event = 'email_changed';
    } else {
        $hash = new_admin_password_hash($input['password'], $input['password_confirmation']);
        if (verify_account_password($input['password'], $actor['password_hash'])) {
            throw new AdminSecurityException('Choose a password different from your current one.', 422);
        }
        $query = $pdo->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?');
        $query->execute([$hash,$id]);
        $message = 'Password updated. Your other sessions have been signed out.';
        $event = 'password_changed';
    }
    admin_security_event($pdo,$event,$id,$id);
    return ['message'=>$message,'session_version'=>(int)$actor['session_version']+1];
}

function manage_admin_account(PDO $pdo, int $actor, string $action, #[SensitiveParameter] array $input): array
{
    if ($action === 'invite') {
        $name = $input['full_name'];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new AdminSecurityException('Enter a name of 2 to 100 characters.',422);
        $email = validated_admin_email($input['email']);
        $query = $pdo->prepare("INSERT INTO users (full_name,email,role,account_status) VALUES (?,?,'admin','pending')");
        admin_security_save_email($query, [$name,$email]);
        $id = (int)$pdo->lastInsertId();
        $invitation = issue_admin_invitation($pdo,$actor,$id);
        admin_security_event($pdo,'administrator_invited',$actor,$id);
        return ['invitation'=>$invitation];
    }

    $id = filter_var($input['target_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) throw new AdminSecurityException('Choose an administrator account.',422);
    $query = $pdo->prepare("SELECT account_status FROM users WHERE id=? AND role='admin'");
    $query->execute([$id]);
    $status = $query->fetchColumn();
    if (!$status) throw new AdminSecurityException('Choose an administrator account.',422);

    if (in_array($action,['regenerate','revoke'],true)) {
        if ($status !== 'pending') throw new AdminSecurityException('Only accounts awaiting activation can change an invitation.',422);
        if ($action === 'regenerate') {
            $invitation = issue_admin_invitation($pdo,$actor,$id);
            admin_security_event($pdo,'invitation_regenerated',$actor,$id);
            return ['invitation'=>$invitation];
        }
        if (!revoke_open_admin_invitations($pdo,$id)) throw new AdminSecurityException('There is no unused invitation to revoke.',422);
        admin_security_event($pdo,'invitation_revoked',$actor,$id);
        return ['message'=>'The invitation has been revoked.'];
    }

    if ($action === 'deactivate') {
        if ($id === $actor) throw new AdminSecurityException('You cannot deactivate your own account.',422);
        if ($input['confirm'] !== '1') throw new AdminSecurityException('Confirm that you want to deactivate this administrator.',422);
        if ($status !== 'active') throw new AdminSecurityException('Only an active account can be deactivated.',422);
        if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND account_status='active'")->fetchColumn() <= 1) {
            throw new AdminSecurityException('Keep at least one active administrator.',422);
        }
        $next = 'inactive';
        $event = 'administrator_deactivated';
    } else {
        if ($status !== 'inactive') throw new AdminSecurityException('Only an inactive account can be reactivated.',422);
        $next = 'active';
        $event = 'administrator_reactivated';
    }
    $query = $pdo->prepare('UPDATE users SET account_status=?,session_version=session_version+1 WHERE id=?');
    $query->execute([$next,$id]);
    admin_security_event($pdo,$event,$actor,$id);
    return ['message'=>$next==='active'?'Administrator reactivated.':'Administrator deactivated and signed out.'];
}

function update_admin_security(int $actorId, int $version, string $action, #[SensitiveParameter] array $input): array
{
    if (!in_array($action,['change_email','change_password','invite','regenerate','revoke','deactivate','reactivate'],true)) {
        throw new AdminSecurityException('Choose a valid account action.',422);
    }
    return with_admin_security_lock(function (PDO $pdo) use ($actorId,$version,$action,$input): array {
        $actor = reauthenticate_admin($pdo,$actorId,$version,$input['current_password']);
        return admin_security_transaction($pdo, fn() => in_array($action,['change_email','change_password'],true)
            ? change_admin_credentials($pdo,$actor,$action,$input)
            : manage_admin_account($pdo,$actorId,$action,$input));
    });
}

function complete_admin_setup(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): void
{
    $invalid = 'This invitation is invalid, expired, or already used. Ask an administrator for a new link.';
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new AdminSecurityException($invalid,422);
    with_admin_security_lock(function (PDO $pdo) use ($token,$password,$confirmation,$invalid): void {
        admin_security_transaction($pdo, function () use ($pdo,$token,$password,$confirmation,$invalid): void {
            $query = $pdo->prepare("SELECT i.id,i.user_id FROM admin_invitations i JOIN users u ON u.id=i.user_id
                WHERE i.token_hash=? AND i.used_at IS NULL AND i.revoked_at IS NULL AND i.expires_at>UTC_TIMESTAMP()
                AND u.role='admin' AND u.account_status='pending'");
            $query->execute([hash('sha256',$token)]);
            $invite = $query->fetch();
            if (!$invite) throw new AdminSecurityException($invalid,422);
            $id = (int)$invite['user_id'];
            $hash = new_admin_password_hash($password,$confirmation);
            $query = $pdo->prepare('UPDATE admin_invitations SET used_at=UTC_TIMESTAMP() WHERE id=? AND expires_at>UTC_TIMESTAMP()');
            $query->execute([$invite['id']]);
            if ($query->rowCount() !== 1) throw new AdminSecurityException($invalid,422);
            $query = $pdo->prepare("UPDATE users SET password_hash=?,account_status='active',session_version=session_version+1,
                terms_accepted_at=UTC_TIMESTAMP(),terms_version='admin-setup-v1' WHERE id=?");
            $query->execute([$hash,$id]);
            revoke_open_admin_invitations($pdo,$id);
            admin_security_event($pdo,'password_setup_completed',$id,$id);
            admin_security_event($pdo,'administrator_activated',$id,$id);
        });
    });
}

function administrator_accounts(): array
{
    return db()->query("SELECT u.id,u.full_name,u.email,u.account_status,u.created_at,u.last_login_at,
        CASE WHEN i.id IS NULL THEN NULL WHEN i.revoked_at IS NOT NULL THEN 'Revoked'
            WHEN i.used_at IS NOT NULL THEN 'Used' WHEN i.expires_at<=UTC_TIMESTAMP() THEN 'Expired' ELSE 'Awaiting use' END AS invitation_status,
        i.expires_at
        FROM users u LEFT JOIN admin_invitations i ON i.id=(SELECT latest.id FROM admin_invitations latest WHERE latest.user_id=u.id ORDER BY latest.id DESC LIMIT 1)
        WHERE u.role='admin' ORDER BY u.full_name,u.id")->fetchAll();
}

function recent_admin_security_events(): array
{
    return db()->query('SELECT e.event_type,e.created_at,actor.full_name AS actor_name,target.full_name AS target_name
        FROM admin_security_events e JOIN users actor ON actor.id=e.actor_user_id JOIN users target ON target.id=e.target_user_id
        ORDER BY e.id DESC LIMIT 50')->fetchAll();
}
