<?php
require __DIR__.'/../../app/bootstrap.php';
require __DIR__.'/../../app/modules/admin-security.php';

header('Referrer-Policy: no-referrer');
$signedIn = current_user() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($signedIn) throw new AdminSecurityException('Open the invitation in a private window or sign out first.',403);
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(),$_POST['csrf'])) {
            throw new AdminSecurityException('This form has expired. Reopen your invitation link and try again.',403);
        }
        $attempts = $_SESSION['admin_setup_attempts'] ?? ['started'=>time(),'count'=>0];
        if ($attempts['started'] <= time()-300) $attempts = ['started'=>time(),'count'=>0];
        if ($attempts['count'] >= 10) throw new AdminSecurityException('Please wait five minutes before trying again.',429);
        $attempts['count']++;
        $_SESSION['admin_setup_attempts'] = $attempts;
        if (post_string('terms') !== '1') throw new AdminSecurityException('Please accept the terms and conditions.',422);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        complete_admin_setup(post_string('token'),$password,$confirmation);
        session_regenerate_id(true);
        $_SESSION = ['flash_success'=>'Your administrator account is active. Sign in with the password you just set.'];
        echo json_encode(['redirect'=>url('/auth/login.php')], JSON_THROW_ON_ERROR);
    } catch (AdminSecurityException $exception) {
        http_response_code($exception->getCode());
        if ($exception->getCode()===429) header('Retry-After: 300');
        echo json_encode(['error'=>$exception->getMessage()], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Administrator password setup could not be completed.');
        http_response_code(503);
        echo '{"error":"We could not finish setting up your account. Please try again shortly."}';
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit; }

$pageTitle = 'Set up administrator access';
$pageScripts = ['/assets/js/admin-security.js'];
require __DIR__.'/../../app/components/header.php';
?>
<section class="container booking-heading">
    <p class="eyebrow">Bella Vista / Administrator invitation</p>
    <h1 class="admin-title">Set your <em>password</em></h1>
    <p>Choose the password you will use to sign in to the management dashboard.</p>
</section>
<div class="container admin-content">
    <?php if ($signedIn): ?>
        <p class="notice">Open the invitation link in a private browser window, or sign out of your current account first.</p>
        <form method="post" action="<?= e(url('/auth/logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="text-link">Sign out ↗</button></form>
    <?php else: ?>
        <noscript><p class="notice">Enable JavaScript and reopen your invitation link to set your password.</p></noscript>
        <p data-admin-setup-message role="alert" class="notice error" hidden></p>
        <form method="post" action="<?= e(url('/auth/admin-setup.php')) ?>" class="booking-sheet security-action" data-admin-setup data-validate hidden>
            <?= csrf_field() ?>
            <?php require __DIR__.'/../../app/components/new-admin-password.php'; ?>
            <label class="check-label"><input type="checkbox" name="terms" value="1" required><span>I accept the <a href="<?= e(url('/terms.php')) ?>" target="_blank" rel="noopener">terms and conditions</a>.</span></label>
            <button class="button" type="submit">Activate my account ↗</button>
        </form>
    <?php endif; ?>
    <a class="text-link" href="<?= e(url('/auth/login.php')) ?>">Back to sign in ↗</a>
</div>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
