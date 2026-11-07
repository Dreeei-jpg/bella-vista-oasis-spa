<?php
require __DIR__ . '/../../app/bootstrap.php';
if ($user=current_user()) redirect($user['role']==='admin'?'/admin/index.php':'/customer/dashboard.php');
$errors=[]; $email='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $email=normalize_account_email(post_string('email'));
    $password=is_string($_POST['password']??null)?$_POST['password']:'';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || $password==='' || strlen($password)>512) $errors[]='Enter a valid email address and password.';
    if (!$errors) {
        try {
            $emailHash=hash('sha256',$email); $ipHash=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
            $query=db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE AND (email_hash = ? OR ip_hash = ?)');
            $query->execute([$emailHash,$ipHash]);
            if ((int)$query->fetchColumn() >= 10) { $errors[]='Too many sign-in attempts. Please wait 15 minutes before trying again.'; http_response_code(429); header('Retry-After: 900'); }
            else {
                $attempt=db()->prepare('INSERT INTO login_attempts (email_hash,ip_hash,attempted_at) VALUES (?,?,UTC_TIMESTAMP())'); $attempt->execute([$emailHash,$ipHash]);
                $query=db()->prepare('SELECT id,password_hash,role,account_status,session_version FROM users WHERE email = ?'); $query->execute([$email]); $user=$query->fetch();
                $valid=verify_account_password($password,$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                if (!$user || !$valid || $user['account_status']!=='active') $errors[]='The email address or password is incorrect.';
                else {
                    $hash=$user['password_hash'];
                    if (password_get_info($hash)['algoName']==='bcrypt' && password_needs_rehash($hash,PASSWORD_DEFAULT)) {
                        $hash=password_hash($password,PASSWORD_DEFAULT);
                    }
                    // A simultaneous credential change must win over an older sign-in or rehash.
                    $update=db()->prepare("UPDATE users SET password_hash=?,last_login_at=UTC_TIMESTAMP() WHERE id=? AND session_version=? AND account_status='active' AND password_hash=?");
                    $update->execute([$hash,$user['id'],$user['session_version'],$user['password_hash']]);
                    $clear=db()->prepare('DELETE FROM login_attempts WHERE email_hash = ? AND ip_hash = ?'); $clear->execute([$emailHash,$ipHash]);
                    sign_in((int)$user['id'],$user['role'],(int)$user['session_version']); redirect(authentication_destination($user['role']));
                }
            }
        } catch(PDOException $exception) { error_log('Login failed: '.$exception->getMessage()); $errors[]='Sign-in is temporarily unavailable. Please try again shortly.'; http_response_code(503); }
    }
}
$pageTitle='Welcome back'; require __DIR__ . '/../../app/components/header.php';
?>
<section class="auth-layout container">
<div class="auth-story">
<p class="eyebrow">Return to your quiet</p>
<h1>Welcome<br>
<em>back.</em>
</h1>
<img src="<?= e(url('/assets/images/couples.jpg')) ?>" alt="A tranquil spa setting — illustrative photography" width="700" height="850">
<p>A familiar place. A fresh moment of calm.</p>
</div>
<div class="auth-panel">
<p class="eyebrow">Your Bella Vista account</p>
<h2>Make yourself at home.</h2>
<p>Sign in to view your reservations and manage an upcoming visit.</p>
<?php form_errors($errors); ?>
<form method="post" data-validate>
<?= csrf_field() ?>
<div class="field">
<label for="email">Email address</label>
<input type="email" id="email" name="email" autocomplete="email" required maxlength="254" value="<?= e($email) ?>">
</div>
<div class="field">
<label for="password">Password</label>
<div class="password-field">
<input type="password" id="password" name="password" autocomplete="current-password" required maxlength="128">
<button type="button" data-toggle-password="password" aria-label="Show password">Show</button>
</div>
</div>
<button class="button full-width" type="submit">Sign in <span>↗</span>
</button>
<p class="form-note">New to Bella Vista? <a href="<?= e(url('/auth/register.php')) ?>">Create an account</a>
</p>
</form>
<p class="account-note">For your privacy, we sign you out after 30 minutes of inactivity.</p>
</div>
</section>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
