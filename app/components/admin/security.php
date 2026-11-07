<?php
$statusLabels = ['active'=>'Active','pending'=>'Pending activation','inactive'=>'Inactive'];
$actionLabels = [
    'change_email'=>'Change Email','change_password'=>'Change Password','invite'=>'Add Administrator',
    'regenerate'=>'Create a new invitation','revoke'=>'Revoke invitation',
    'deactivate'=>'Deactivate administrator','reactivate'=>'Reactivate administrator',
];
$events = recent_admin_security_events();
?>
<?php if ($invitation): ?>
<section class="notice security-invitation" aria-labelledby="invitation-title">
    <h2 id="invitation-title">Invitation ready</h2>
    <p>Copy this link now and share it privately with the intended recipient. It is shown once and expires in <?= (int)$invitation['minutes'] ?> minutes.</p>
    <div class="field"><label for="invitation-link">One-time password setup link</label>
        <input id="invitation-link" data-admin-invitation readonly spellcheck="false"
            value="<?= e(url('/auth/admin-setup.php').'#token='.$invitation['token']) ?>">
    </div>
    <button type="button" class="button" data-copy-admin-invitation hidden>Copy invitation link</button>
    <p role="status" data-invitation-status></p>
</section>
<?php unset($invitation); endif; ?>

<?php if ($action !== ''): ?>
<section class="booking-sheet security-action" id="security-action">
    <h2><?= e($actionLabels[$action]) ?></h2>
    <form method="post" action="<?= e(url('/admin/security.php#security-action')) ?>" <?= $action==='change_password'?'data-validate':'' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <?php if ($target): ?>
            <input type="hidden" name="target_id" value="<?= e($target['id']) ?>">
            <p><?= e($target['full_name']) ?> · <?= e($target['email']) ?></p>
        <?php endif; ?>
        <?php if ($action==='change_email'): ?>
            <div class="field"><label for="current-email">Current email address</label><input id="current-email" value="<?= e($user['email']) ?>" readonly></div>
            <div class="field"><label for="new-email">New email address</label><input id="new-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e(post_string('email')) ?>"></div>
            <p class="small-note">The new address takes effect immediately. Bella Vista does not send a verification email.</p>
        <?php elseif ($action==='invite'): ?>
            <div class="field"><label for="admin-name">Full name</label><input id="admin-name" name="full_name" required minlength="2" maxlength="100" value="<?= e(post_string('full_name')) ?>"></div>
            <div class="field"><label for="admin-email">Email address</label><input id="admin-email" name="email" type="email" required maxlength="254" value="<?= e(post_string('email')) ?>"></div>
            <div class="field"><label for="admin-role">Account role</label><input id="admin-role" value="Administrator" readonly></div>
            <p class="small-note">The recipient sets their own password. Share the invitation link privately after creating the account.</p>
        <?php elseif ($action==='deactivate'): ?>
            <p>This signs the administrator out and prevents new sign-ins. Their account and past activity remain in the records.</p>
            <label class="check-label"><input type="checkbox" name="confirm" value="1" required><span>Confirm deactivation for <?= e($target['full_name']) ?></span></label>
        <?php elseif ($action==='regenerate'): ?>
            <p>Creating a new link makes the previous unused link invalid.</p>
        <?php elseif ($action==='revoke'): ?>
            <p>The recipient will need a new link to finish setting up this account.</p>
        <?php endif; ?>
        <div class="field"><label for="current-password">Your current password</label>
            <div class="password-field"><input id="current-password" name="current_password" type="password" autocomplete="current-password" required maxlength="128">
                <button type="button" data-toggle-password="current-password" aria-label="Show current password">Show</button></div>
        </div>
        <?php if ($action==='change_password') require __DIR__.'/../new-admin-password.php'; ?>
        <div class="inline-actions">
            <button type="submit" class="button"><?= e(match ($action) {
                'change_email'=>'Save changes','change_password'=>'Update password','invite'=>'Create administrator',
                default=>$actionLabels[$action],
            }) ?> ↗</button>
            <a class="text-link" href="<?= e(url('/admin/security.php')) ?>">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>

<section id="my-account">
    <h2>My Account</h2>
    <dl class="details-grid">
        <div><dt>Display name</dt><dd><?= e($user['full_name']) ?></dd></div>
        <div><dt>Current email</dt><dd><?= e($user['email']) ?></dd></div>
        <div><dt>Role</dt><dd>Administrator</dd></div>
        <div><dt>Status</dt><dd>Active</dd></div>
        <div><dt>Created</dt><dd><?= e(local_time($user['created_at'])->format('F j, Y')) ?></dd></div>
    </dl>
    <div class="inline-actions">
        <a class="button" href="<?= e(url('/admin/security.php?action=change_email#security-action')) ?>">Change Email ↗</a>
        <a class="text-link" href="<?= e(url('/admin/security.php?action=change_password#security-action')) ?>">Change Password ↗</a>
    </div>
</section>

<section id="manage-administrators">
    <h2>Manage Administrators</h2>
    <div class="inline-actions"><a class="button" href="<?= e(url('/admin/security.php?action=invite#security-action')) ?>">Add Administrator ↗</a></div>
    <div class="table-scroll security-table" tabindex="0" role="region" aria-label="Administrator accounts">
        <table><thead><tr><th scope="col">Administrator</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col">Last sign-in</th><th scope="col">Actions</th></tr></thead>
            <tbody><?php foreach ($accounts as $account):
                $own = (int)$account['id']===(int)$user['id'];
                $choices = match ($account['account_status']) {
                    'pending'=>$account['invitation_status']==='Awaiting use'?['regenerate','revoke']:['regenerate'],
                    'inactive'=>['reactivate'],
                    default=>$own?[]:['deactivate'],
                };
            ?>
                <tr>
                    <td><?= e($account['full_name']) ?><?= $own?' (you)':'' ?><small><?= e($account['email']) ?></small></td>
                    <td><?= e($statusLabels[$account['account_status']]) ?>
                        <?php if ($account['account_status']==='pending' && $account['invitation_status']): ?>
                            <small>Invitation: <?= e($account['invitation_status']) ?>
                                <?php if ($account['invitation_status']==='Awaiting use'): ?> · Expires <?= e(local_time($account['expires_at'])->format('M j, g:i A')) ?><?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(local_time($account['created_at'])->format('M j, Y')) ?></td>
                    <td><?= $account['last_login_at']?e(local_time($account['last_login_at'])->format('M j, Y · g:i A')):'—' ?></td>
                    <td>
                        <?php foreach ($choices as $choice): ?><a class="text-link" href="<?= e(url('/admin/security.php?'.http_build_query(['action'=>$choice,'target_id'=>$account['id']]).'#security-action')) ?>"><?= e($actionLabels[$choice]) ?></a><?php endforeach; ?>
                        <?php if (!$choices): ?>Your account<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
    <p class="small-note">Dates and times are shown in Philippine time.</p>
</section>

<section id="account-activity">
    <h2>Account Security</h2>
    <p>Your current password is required for account changes. Changing your email or password signs out your other sessions.</p>
    <h3>Recent security activity</h3>
    <?php if (!$events): ?><p>No account changes have been recorded yet.</p><?php else: ?>
        <div class="table-scroll security-table" tabindex="0" role="region" aria-label="Recent security activity"><table>
            <thead><tr><th scope="col">Event</th><th scope="col">By</th><th scope="col">Account</th><th scope="col">Philippine time</th></tr></thead>
            <tbody><?php foreach ($events as $event): ?>
                <tr><td><?= e(ucfirst(str_replace('_',' ',$event['event_type']))) ?></td><td><?= e($event['actor_name']) ?></td>
                    <td><?= e($event['target_name']) ?></td><td><?= e(local_time($event['created_at'])->format('M j, Y · g:i A')) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
