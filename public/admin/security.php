<?php
require __DIR__.'/../../app/bootstrap.php';
require __DIR__.'/../../app/modules/admin-security.php';

header('Referrer-Policy: no-referrer');
$user = require_role('admin');
$errors = [];
$actions = ['change_email','change_password','invite','regenerate','revoke','deactivate','reactivate'];
$action = $_SERVER['REQUEST_METHOD']==='POST' ? post_string('action') : ($_GET['action'] ?? '');
if (!in_array($action,$actions,true)) $action = '';
$targetId = $_SERVER['REQUEST_METHOD']==='POST' ? post_string('target_id') : ($_GET['target_id'] ?? '');
$invitation = null;
$notice = $_SESSION['security_notice'] ?? '';
unset($_SESSION['security_notice']);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $input = ['target_id'=>$targetId];
    foreach (['full_name','email','confirm'] as $field) $input[$field] = post_string($field);
    foreach (['current_password','password','password_confirmation'] as $field) {
        $input[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    try {
        $result = update_admin_security((int)$user['id'],(int)$user['session_version'],$action,$input);
        if (isset($result['invitation'])) {
            // The raw link is sent only in this response. A repeated POST has a stale CSRF token.
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $invitation = $result['invitation'];
            http_response_code(201);
            $action = '';
        } else {
            if (isset($result['session_version'])) sign_in((int)$user['id'],'admin',$result['session_version']);
            $_SESSION['security_notice'] = $result['message'];
            redirect('/admin/security.php');
        }
    } catch (AdminSecurityException $exception) {
        $errors[] = $exception->getMessage();
        http_response_code($exception->getCode());
        if ($exception->getCode()===429) header('Retry-After: 900');
    } catch (Throwable $exception) {
        error_log('Administrator account change could not be saved.');
        $errors[] = 'We could not save the change. Please try again shortly.';
        http_response_code(503);
    }
    unset($input,$result,$_POST['current_password'],$_POST['password'],$_POST['password_confirmation']);
}

$accounts = administrator_accounts();
$target = null;
foreach ($accounts as $account) {
    if (is_string($targetId) && (string)$account['id']===$targetId) $target = $account;
}
if (in_array($action,['regenerate','revoke','deactivate','reactivate'],true) && !$target) {
    $action = '';
    $errors[] = 'Choose an administrator account from the list.';
    if (http_response_code() === 200) http_response_code(422);
}
$pageTitle = 'Account & Security';
$pageScripts = ['/assets/js/admin-security.js'];
require __DIR__.'/../../app/components/header.php';
require __DIR__.'/../../app/components/admin-nav.php';
?>
<section class="container booking-heading">
    <p class="eyebrow">Bella Vista / Management</p>
    <h1 class="admin-title">Account &amp; Security</h1>
    <p>Manage your sign-in details, administrator access, and account activity.</p>
</section>
<div class="container admin-content account-security">
    <?php form_errors($errors); ?>
    <?php if ($notice): ?><p class="notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php require __DIR__.'/../../app/components/admin/security.php'; ?>
    <form method="post" action="<?= e(url('/auth/logout.php')) ?>" class="inline-actions"><?= csrf_field() ?><button class="text-link" type="submit">Sign out ↗</button></form>
</div>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
