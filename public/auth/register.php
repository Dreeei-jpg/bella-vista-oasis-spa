<?php
require __DIR__ . '/../../app/bootstrap.php';
if ($user = current_user()) redirect($user['role']==='admin' ? '/admin/index.php' : '/customer/dashboard.php');
$errors=[];
$data=['full_name'=>'','email'=>'','phone'=>'','terms'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach(array_keys($data) as $key) $data[$key]=post_string($key);
    $data['email']=normalize_account_email($data['email']);
    $data['password']=is_string($_POST['password']??null) ? $_POST['password'] : '';
    $data['password_confirmation']=is_string($_POST['password_confirmation']??null) ? $_POST['password_confirmation'] : '';
    $errors=validate_registration($data);
    if (!$errors) {
        try {
            $query=db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,role,terms_accepted_at,terms_version) VALUES (?,?,?,?,'customer',UTC_TIMESTAMP(),'phase-5.1-test')");
            $query->execute([$data['full_name'],$data['email'],$data['phone']?:null,password_hash($data['password'],PASSWORD_DEFAULT)]);
            sign_in((int)db()->lastInsertId());
            $_SESSION['flash_success']='Welcome to Bella Vista. Your account is ready.';
            redirect(authentication_destination('customer'));
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1]??0) === 1062) $errors[]='An account with this email already exists. Please sign in.';
            else { error_log('Registration failed: '.$exception->getMessage()); $errors[]='We could not create your account right now. Please try again shortly.'; http_response_code(503); }
        }
    }
    unset($data['password'],$data['password_confirmation']);
}
$pageTitle='Create your account';
require __DIR__ . '/../../app/components/header.php';
?>
<section class="auth-layout container">
<div class="auth-story">
<p class="eyebrow">A space of your own</p>
<h1>A little time.<br>Just <em>for you.</em>
</h1>
<img src="<?= e(url('/assets/images/single.jpg')) ?>" alt="A quiet spa ritual — illustrative photography" width="700" height="850">
<p>Your next chapter of calm begins here.</p>
</div>
<div class="auth-panel">
<p class="eyebrow">Welcome to Bella Vista</p>
<h2>Create an account.</h2>
<p>Save your contact details, make a reservation, and keep track of your visits.</p>
<?php form_errors($errors); ?>
<form method="post" data-validate>
<?= csrf_field() ?>
<div class="field">
<label for="full_name">Full name</label>
<input id="full_name" name="full_name" autocomplete="name" required minlength="2" maxlength="100" value="<?= e($data['full_name']) ?>">
</div>
<div class="field">
<label for="email">Email address</label>
<input type="email" id="email" name="email" autocomplete="email" required maxlength="254" value="<?= e($data['email']) ?>">
</div>
<div class="field">
<label for="phone">Phone number <span>Optional</span>
</label>
<input type="tel" id="phone" name="phone" autocomplete="tel" maxlength="25" pattern="\+?[0-9 \(\)\-]{7,25}" value="<?= e($data['phone']) ?>">
</div>
<div class="field">
<label for="password">Password</label>
<div class="password-field">
<input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required aria-describedby="password-help">
<button type="button" data-toggle-password="password" aria-label="Show password">Show</button>
</div>
<small id="password-help">12–72 characters. For non-English characters, the 72-byte limit may be shorter.</small>
</div>
<div class="field">
<label for="password_confirmation">Confirm password</label>
<input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>
</div>
<label class="check-label">
<input type="checkbox" name="terms" value="1" required <?= $data['terms']==='1'?'checked':'' ?>>
<span>I agree to the <a href="<?= e(url('/terms.php')) ?>" target="_blank" rel="noopener">terms & conditions (opens in a new tab)</a>.</span>
</label>
<button class="button full-width" type="submit">Create my account <span>↗</span>
</button>
<p class="form-note">Already have an account? <a href="<?= e(url('/auth/login.php')) ?>">Sign in</a>
</p>
</form>
</div>
</section>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
