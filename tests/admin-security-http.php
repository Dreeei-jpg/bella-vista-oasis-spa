<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);

$config = require __DIR__.'/../config/config.php';
$base = rtrim($argv[1]??'http://127.0.0.1:8098','/');
if (!str_ends_with($config['db_name'],'_test') || !in_array($config['db_host'],['127.0.0.1','localhost'],true)
    || !in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true) || parse_url($base,PHP_URL_PORT)===8088) {
    fwrite(STDERR,"Use a separate local test database and its own PHP server.\n");
    exit(1);
}
require_once __DIR__.'/../app/database.php';
$pdo = db();
foreach (['users','appointments','therapists','admin_invitations','admin_security_events'] as $table) {
    if ((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()) {
        fwrite(STDERR,"Use an empty isolated test database.\n");
        exit(1);
    }
}
$oldMode = (int)$pdo->query('SELECT demo_payments_enabled FROM business_settings WHERE id=1')->fetchColumn();
require __DIR__.'/phase3-fixtures.php';
require_once __DIR__.'/../app/modules/admin-security.php';

final class SecurityClient
{
    private CurlHandle $curl;

    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl,[CURLOPT_COOKIEFILE=>'',CURLOPT_HEADER=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);
    }

    public function request(string $path, #[SensitiveParameter] ?array $body=null): array
    {
        curl_setopt($this->curl,CURLOPT_URL,$this->base.$path);
        if ($body===null) curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        else curl_setopt_array($this->curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body)]);
        $raw = curl_exec($this->curl);
        if ($raw===false) throw new RuntimeException('Local HTTP request failed.');
        $size = curl_getinfo($this->curl,CURLINFO_HEADER_SIZE);
        return ['status'=>curl_getinfo($this->curl,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
    }

    public function login(string $email, #[SensitiveParameter] string $password): array
    {
        return $this->request('/auth/login.php',['email'=>$email,'password'=>$password,'csrf'=>security_csrf($this->request('/auth/login.php'))]);
    }

    public function action(string $action, #[SensitiveParameter] string $password, #[SensitiveParameter] array $fields=[]): array
    {
        $csrf = security_csrf($this->request('/admin/security.php'));
        return $this->request('/admin/security.php',['action'=>$action,'current_password'=>$password,'csrf'=>$csrf]+$fields);
    }

    public function logout(): void
    {
        $this->request('/auth/logout.php',['csrf'=>security_csrf($this->request('/admin/security.php'))]);
    }

    public function setup(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password, array $override=[]): array
    {
        $csrf = security_csrf($this->request('/auth/admin-setup.php'));
        return $this->request('/auth/admin-setup.php',array_replace(['csrf'=>$csrf,'token'=>$token,'password'=>$password,'password_confirmation'=>$password,'terms'=>'1'],$override));
    }
}

function security_csrf(array $response): string
{
    if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/',$response['body'],$match)) throw new RuntimeException('Expected a session form.');
    return $match[1];
}

function security_assert(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
    echo "PASS: $label\n";
}

function security_cookie(array $response): ?string
{
    preg_match('/^Set-Cookie: bella_vista_session=([^;]+)/mi',$response['headers'],$match);
    return $match[1]??null;
}

function security_token(#[SensitiveParameter] array $response): string
{
    if ($response['status']!==201 || !preg_match('~value="[^"]*/auth/admin-setup\.php#token=([a-f0-9]{64})"~',$response['body'],$match)) {
        throw new RuntimeException('Expected a one-time invitation response.');
    }
    return $match[1];
}

function security_row(int $id): array
{
    $query=db()->prepare('SELECT * FROM users WHERE id=?');
    $query->execute([$id]);
    return $query->fetch();
}

function run_security_bootstrap(string $email): int
{
    $process = proc_open([PHP_BINARY,__DIR__.'/../tools/make-admin.php',$email],
        [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Could not run the first administrator command.');
    fclose($pipes[0]);
    foreach ([1,2] as $i) { stream_get_contents($pipes[$i]); fclose($pipes[$i]); }
    return proc_close($process);
}

$checks=0;
$fixture=null;
$newId=null;
$extraEmails=[];
try {
    $fixture = new Phase3Fixtures();
    [$owner,$customerId] = $fixture->users;
    [$oldEmail,$customerEmail] = $fixture->emails;
    $oldPassword = $fixture->password;
    $booking = $fixture->book('SR-1',0);
    $payment = $fixture->legacyPayment($booking,'240.00');
    $booking = owned_appointment($owner,$booking['booking_reference']);
    $originalCustomer = security_row($customerId);
    security_assert(run_security_bootstrap($oldEmail)===0,'First administrator setup keeps the existing account ID');
    security_assert(run_security_bootstrap($customerEmail)!==0 && security_row($customerId)['role']==='customer','An existing administrator must invite additional administrators through the dashboard');

    $admin = new SecurityClient($base);
    $otherSession = new SecurityClient($base);
    $customer = new SecurityClient($base);
    $guest = new SecurityClient($base);
    $recipient = new SecurityClient($base);
    $adminLogin = $admin->login($oldEmail,$oldPassword);
    security_assert($adminLogin['status']===303 && str_contains($adminLogin['headers'],'/admin/index.php'),'Existing administrator can sign in after migration');
    security_assert(security_row($owner)['last_login_at']!==null,'Successful sign-in records its timestamp');
    security_assert($otherSession->login($oldEmail,$oldPassword)['status']===303,'Second session can access the active account before a credential change');
    security_assert($guest->request('/admin/security.php')['status']===303 && $guest->request('/admin/security.php',['action'=>'invite'])['status']===303,'Anonymous account management and creation are blocked');
    security_assert($customer->login($customerEmail,$oldPassword)['status']===303,'Customer account still signs in');
    $customerPage = $customer->request('/customer/dashboard.php');
    security_assert($customer->request('/admin/security.php')['status']===403 &&
        $customer->request('/admin/security.php',['csrf'=>security_csrf($customerPage),'action'=>'invite','email'=>$customerEmail])['status']===403,'Customer role cannot open the page or create administrators');
    $page = $admin->request('/admin/security.php');
    security_assert($page['status']===200 && str_contains($page['body'],'Account &amp; Security') &&
        str_contains($page['body'],$oldEmail) && !str_contains($page['body'],security_row($owner)['password_hash']),'Account page shows identity and status without credentials');
    security_assert($admin->request('/admin/security.php',['action'=>'invite','csrf'=>'bad'])['status']===403,'Sensitive actions reject invalid CSRF');
    security_assert($admin->request('/admin/security.php?action=deactivate&target_id='.$owner)['status']===200 && security_row($owner)['account_status']==='active','GET can show a form and cannot change account state');
    security_assert($admin->action('deactivate',$oldPassword,['target_id'=>$owner,'confirm'=>'1'])['status']===422 && security_row($owner)['account_status']==='active','The final active administrator cannot deactivate their own account');

    $newEmail = 'phase53-'.bin2hex(random_bytes(6)).'@example.test';
    $extraEmails[] = $newEmail;
    security_assert($admin->action('change_email',$oldPassword,['email'=>'bad-address'])['status']===422,'Invalid email format is rejected');
    security_assert($admin->action('change_email',$oldPassword,['email'=>strtoupper($customerEmail)])['status']===422,'Case-insensitive uniqueness covers existing customers');
    $oldHash = security_row($owner)['password_hash'];
    $result = $admin->action('change_email',$oldPassword,['email'=>strtoupper($newEmail),'target_id'=>$customerId]);
    $row = security_row($owner);
    security_assert($result['status']===303 && $row['email']===$newEmail && $row['password_hash']===$oldHash,'Email change normalizes the address and keeps the same account and password');
    security_assert(security_row($customerId)['email']===$originalCustomer['email'],'A browser-supplied target ID cannot change another account');
    security_assert(security_cookie($result)!==null && security_cookie($result)!==security_cookie($adminLogin),'Email change rotates the current session ID');
    security_assert($otherSession->request('/admin/security.php')['status']===303,'Email change invalidates the other session');
    $admin->logout();
    security_assert(str_contains($admin->login($oldEmail,$oldPassword)['body'],'email address or password is incorrect'),'Previous email no longer signs in');
    $adminLogin = $admin->login($newEmail,$oldPassword);
    security_assert($adminLogin['status']===303 && $otherSession->login($newEmail,$oldPassword)['status']===303,'New email signs in with the original password');

    $newPassword = str_repeat('Quiet garden ',7).bin2hex(random_bytes(8));
    if (!in_array('argon2id',password_algos(),true)) $newPassword = 'Quiet garden '.bin2hex(random_bytes(16));
    security_assert($admin->action('change_password','wrong current password',['password'=>$newPassword,'password_confirmation'=>$newPassword])['status']===422,'Incorrect current password cannot change credentials');
    security_assert($admin->action('change_password',$oldPassword,['password'=>'short','password_confirmation'=>'short'])['status']===422,'Short password is rejected');
    security_assert($admin->action('change_password',$oldPassword,['password'=>$newPassword,'password_confirmation'=>'different password'])['status']===422,'Mismatched confirmation is rejected');
    security_assert($admin->action('change_password',$oldPassword,['password'=>$oldPassword,'password_confirmation'=>$oldPassword])['status']===422,'Identical password is rejected');
    $result = $admin->action('change_password',$oldPassword,['password'=>$newPassword,'password_confirmation'=>$newPassword]);
    $hash = security_row($owner)['password_hash'];
    security_assert($result['status']===303 && $hash!==$newPassword && password_verify($newPassword,$hash) && !password_verify($oldPassword,$hash),'New passphrase is hashed and replaces the original credential');
    security_assert(security_cookie($result)!==null && security_cookie($result)!==security_cookie($adminLogin),'Password change rotates the current session ID');
    security_assert($admin->request('/admin/security.php')['status']===200 && $otherSession->request('/admin/index.php')['status']===303,'Current session remains usable and the other session is invalidated');
    $admin->logout();
    security_assert(str_contains($admin->login($newEmail,$oldPassword)['body'],'email address or password is incorrect'),'Old password no longer signs in');
    security_assert($admin->login($newEmail,$newPassword)['status']===303,'New password works at normal sign-in');
    security_assert(owned_appointment($owner,$booking['booking_reference'])===$booking && (int)db()->query('SELECT COUNT(*) FROM payments WHERE id='.(int)$payment['id'])->fetchColumn()===1,'Existing appointment and payment relationships survive both credential changes');

    $inviteEmail = 'invite-'.bin2hex(random_bytes(6)).'@example.test';
    $extraEmails[] = $inviteEmail;
    $inviteInput = ['full_name'=>'Disposable Security QA Administrator','email'=>$inviteEmail,'role'=>'customer'];
    security_assert($admin->action('invite','wrong password',$inviteInput)['status']===422,'Invitation requires the creator’s password');
    security_assert($admin->action('invite',$newPassword,array_replace($inviteInput,['email'=>$customerEmail]))['status']===422,'Cannot invite an email already in use');
    $csrf = security_csrf($admin->request('/admin/security.php'));
    $post = ['csrf'=>$csrf,'action'=>'invite','current_password'=>$newPassword]+$inviteInput;
    $issued = $admin->request('/admin/security.php',$post);
    $query = db()->prepare('SELECT id FROM users WHERE email=?');
    $query->execute([$inviteEmail]);
    $newId = (int)$query->fetchColumn();
    $token = security_token($issued);
    $newAccount = security_row($newId);
    security_assert($newId!==$owner && $newAccount['role']==='admin' && $newAccount['account_status']==='pending' && $newAccount['password_hash']===null,'New administrator gets a unique pending account without a preset password');
    security_assert($admin->request('/admin/security.php',$post)['status']===403,'Repeating the invitation POST cannot reveal or reissue the link');
    $page = $admin->request('/admin/security.php');
    security_assert(str_contains($page['body'],$inviteEmail) && str_contains($page['body'],'Pending activation') && !str_contains($page['body'],$token),'Refresh shows pending status and never redisplays the invitation');
    security_assert($admin->action('invite',$newPassword,$inviteInput)['status']===422,'Duplicate invitation email is rejected');
    security_assert($admin->request('/admin/index.php')['status']===200,'Inviting another administrator leaves the creator signed in');
    security_assert(str_contains($recipient->login($inviteEmail,$newPassword)['body'],'email address or password is incorrect'),'Pending accounts cannot sign in');
    $stored = db()->prepare('SELECT token_hash,expires_at FROM admin_invitations WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $stored->execute([$newId]);
    $invitation = $stored->fetch();
    security_assert($invitation['token_hash']===hash('sha256',$token) && $invitation['token_hash']!==$token && strtotime($invitation['expires_at'].' UTC')>time(),'Only a hash and a future expiration are stored');
    security_assert(str_contains(strtolower($issued['headers']),'cache-control: no-store') && str_contains(strtolower($issued['headers']),'referrer-policy: no-referrer'),'Invitation response prevents caching and referrer sharing');
    security_assert($recipient->setup($token,$newPassword,['csrf'=>'bad'])['status']===403,'Password setup requires its own CSRF token');
    $pdo->prepare('UPDATE admin_invitations SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=?')->execute([$newId]);
    security_assert($recipient->setup($token,$newPassword)['status']===422,'Expired invitations are rejected');
    $secondToken = security_token($admin->action('regenerate',$newPassword,['target_id'=>$newId]));
    security_assert($secondToken!==$token && $recipient->setup($token,$newPassword)['status']===422,'Regenerating issues a fresh token and retires the old one');
    security_assert($admin->action('revoke',$newPassword,['target_id'=>$newId])['status']===303 && $recipient->setup($secondToken,$newPassword)['status']===422,'Revoking invalidates an unused invitation');
    $activeToken = security_token($admin->action('regenerate',$newPassword,['target_id'=>$newId]));
    $recipientPassword = 'Private garden '.bin2hex(random_bytes(16));
    security_assert($recipient->setup($activeToken,'short')['status']===422,'Weak password does not consume a valid invitation');
    security_assert($recipient->setup($activeToken,$recipientPassword,['password_confirmation'=>'other passphrase'])['status']===422,'Setup enforces password confirmation');
    $setup = $recipient->setup($activeToken,$recipientPassword);
    $newAccount = security_row($newId);
    security_assert($setup['status']===200 && (json_decode($setup['body'],true)['redirect']??'')==='/auth/login.php' && $newAccount['account_status']==='active' &&
        password_verify($recipientPassword,$newAccount['password_hash']),'Recipient sets a hashed password and activates their account');
    security_assert($recipient->request('/admin/index.php')['status']===303 && $recipient->setup($activeToken,$recipientPassword)['status']===422,'Setup does not sign in and the used token cannot be replayed');
    security_assert($recipient->login($inviteEmail,$recipientPassword)['status']===303 && $recipient->request('/admin/index.php')['status']===200,'New administrator signs in independently and opens the dashboard');
    security_assert($admin->action('deactivate',$newPassword,['target_id'=>$newId])['status']===422,'Deactivation requires an explicit confirmation');
    security_assert($admin->action('deactivate',$newPassword,['target_id'=>$owner,'confirm'=>'1'])['status']===422,'An administrator cannot deactivate their own account when others are active');
    security_assert($admin->action('deactivate',$newPassword,['target_id'=>$newId,'confirm'=>'1'])['status']===303 && $recipient->request('/admin/index.php')['status']===303,'Deactivation signs the other administrator out');
    security_assert(str_contains($recipient->login($inviteEmail,$recipientPassword)['body'],'email address or password is incorrect'),'Inactive administrator gets the generic sign-in error');
    security_assert($admin->action('reactivate',$newPassword,['target_id'=>$newId])['status']===303 && $recipient->login($inviteEmail,$recipientPassword)['status']===303,'Reactivation preserves the account and restores access with its existing password');
    $events = db()->query('SELECT * FROM admin_security_events ORDER BY id')->fetchAll();
    $types = array_column($events,'event_type');
    security_assert(!array_diff(['email_changed','password_changed','administrator_invited','invitation_regenerated','invitation_revoked',
        'password_setup_completed','administrator_activated','administrator_deactivated','administrator_reactivated'],$types),'Security events persist with actor, target, and event time');
    $audit = $admin->request('/admin/security.php')['body'];
    security_assert(str_contains($audit,'Recent security activity') && str_contains($audit,'Password setup completed') &&
        !str_contains($audit,$activeToken) && !str_contains($audit,$newPassword) && !str_contains($audit,$newAccount['password_hash']),'Admin activity is readable and excludes credentials and invitation tokens');
    for ($i=0;$i<5;$i++) {
        security_assert($admin->action('change_email','incorrect password',['email'=>$oldEmail])['status']===422,'Incorrect reauthentication attempt is rejected');
    }
    security_assert($admin->action('change_email',$newPassword,['email'=>$oldEmail])['status']===429 && security_row($owner)['email']===$newEmail,'Account-level throttle blocks repeated guessing even with a later correct password');
    $originalCustomer['last_login_at']=security_row($customerId)['last_login_at'];
    security_assert(security_row($customerId)===$originalCustomer,'Customer data is preserved apart from its own sign-in timestamp');
    echo "\n$checks administrator security HTTP checks passed.\n";
} finally {
    if ($fixture) {
        $ids = array_merge($fixture->users,$newId?[$newId]:[]);
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM admin_security_events WHERE actor_user_id=? OR target_user_id=?')->execute([$id,$id]);
            $pdo->prepare('DELETE FROM admin_invitations WHERE created_by=? OR user_id=?')->execute([$id,$id]);
            $pdo->prepare('DELETE FROM admin_security_throttles WHERE user_id=?')->execute([$id]);
        }
        if ($newId) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$newId]);
        $fixture->cleanup();
    }
    foreach ($extraEmails as $email) $pdo->prepare('DELETE FROM login_attempts WHERE email_hash=?')->execute([hash('sha256',$email)]);
    $pdo->prepare('UPDATE business_settings SET demo_payments_enabled=? WHERE id=1')->execute([$oldMode]);
}
