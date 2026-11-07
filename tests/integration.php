<?php
declare(strict_types=1);
// Run only against your development database and local PHP server.
// Creates a uniquely named test account and removes only its own records in finally.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/modules/catalog.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8088', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1','localhost','::1'], true)) exit("Use a local test server.\n");
$email = 'qa-' . bin2hex(random_bytes(6)) . '@example.test';
$throttleEmail = 'throttle-' . bin2hex(random_bytes(6)) . '@example.test';
$password = 'Test-only-' . bin2hex(random_bytes(12));
$cookie = tempnam(__DIR__ . '/../var', 'qa-cookie-');
$checks = 0;

function check(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    ++$checks; echo "PASS: $description\n";
}
function request(string $path, ?array $data = null): array {
    global $base, $cookie;
    $basePath = parse_url($base, PHP_URL_PATH) ?: '';
    $target = $basePath !== '' && str_starts_with($path, $basePath . '/')
        ? substr($base, 0, -strlen($basePath)) . $path
        : $base . $path;
    $curl = curl_init($target);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>15]);
    if ($data !== null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $response = curl_exec($curl);
    if ($response === false) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    $result = ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'headers'=>substr($response,0,$size),'body'=>substr($response,$size)];
    curl_close($curl);
    return $result;
}
function token(array $response): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $response['body'], $matches)) throw new RuntimeException('Missing CSRF token');
    return $matches[1];
}

try {
    foreach (['/','/services.php','/about.php','/gallery.php','/terms.php','/auth/login.php','/auth/register.php'] as $page) {
        $response = request($page); check($response['status']===200, "$page returns 200");
        check(!str_contains($response['body'],'Warning:') && !str_contains($response['body'],'Fatal error'), "$page has no PHP error output");
        preg_match_all('/(?:src|href)="([^"#]+)"/', $response['body'], $matches);
        foreach (array_unique($matches[1]) as $asset) {
            $asset = html_entity_decode($asset);
            if (str_contains($asset,'/assets/')) check(request($asset)['status']===200, "Asset available: $asset");
        }
    }
    check(request('/customer/dashboard.php')['status']===303, 'Anonymous customer dashboard redirects');
    check(request('/booking.php')['status']===303, 'Anonymous booking redirects to login');
    check(request('/admin/index.php')['status']===303, 'Anonymous admin page redirects');
    check(request('/auth/logout.php')['status']===405, 'GET logout is rejected');
    check(request('/auth/register.php',[])['status']===403, 'Missing registration CSRF rejected');
    $form=request('/auth/register.php');
    check(str_contains(strtolower($form['headers']),'cache-control: no-store'), 'Authentication pages prevent caching');
    $invalid=request('/auth/register.php',['csrf'=>token($form),'full_name'=>'A','email'=>'not-email','password'=>'short','password_confirmation'=>'different','phone'=>'bad-phone']);
    foreach (['full name','valid email','phone number','password between','do not match','accept the terms'] as $message) check(str_contains($invalid['body'],$message), 'Server validates '.$message);
    $form=request('/auth/register.php');
    $data=['csrf'=>token($form),'full_name'=>'QA <script>alert(1)</script>','email'=>strtoupper($email),'phone'=>'+63 912 345 6789','password'=>$password,'password_confirmation'=>$password,'terms'=>'1','role'=>'admin'];
    $registered=request('/auth/register.php',$data);
    check($registered['status']===303 && str_contains($registered['headers'],'/booking.php'),'Registration resumes the previously requested booking');
    check(str_contains(strtolower($registered['headers']),'httponly') && str_contains(strtolower($registered['headers']),'samesite=lax'),'Session cookie is HttpOnly and SameSite');
    $query=db()->prepare('SELECT * FROM users WHERE email=?'); $query->execute([$email]); $user=$query->fetch();
    check((bool)$user,'Registration persisted in MySQL');
    check($user['role']==='customer','Client cannot self-assign admin role');
    check($user['password_hash']!==$password && password_verify($password,$user['password_hash']),'Password stored as a valid hash');
    $dashboard=request('/customer/dashboard.php');
    check($dashboard['status']===200,'Authenticated dashboard accessible');
    check(str_contains($dashboard['body'],'&lt;script&gt;') && !str_contains($dashboard['body'],'<script>alert(1)</script>'),'Customer name is escaped');
    check(str_contains($dashboard['body'],'You have no upcoming appointments.') && str_contains($dashboard['body'],'Your past visits will appear here.'),'Real empty states displayed');
    check(request('/admin/index.php')['status']===403,'Customer cannot access admin');
    check(request('/auth/logout.php',['csrf'=>'invalid'])['status']===403,'Logout CSRF enforced');
    check(request('/auth/logout.php',['csrf'=>token($dashboard)])['status']===303,'POST logout succeeds');
    check(request('/customer/dashboard.php')['status']===303,'Session access revoked after logout');
    $data['csrf']=token(request('/auth/register.php'));
    check(str_contains(request('/auth/register.php',$data)['body'],'already exists'),'Duplicate email prevented case-insensitively');
    $login=request('/auth/login.php');
    $wrong=request('/auth/login.php',['csrf'=>token($login),'email'=>$email,'password'=>'wrong-password']);
    check(str_contains($wrong['body'],'email address or password is incorrect'),'Wrong password rejected with generic error');
    $login=request('/auth/login.php');
    $correct=request('/auth/login.php',['csrf'=>token($login),'email'=>$email,'password'=>$password]);
    check($correct['status']===303,'Correct password logs in');
    check(str_contains($correct['headers'],'Set-Cookie:'),'Login rotates session ID');
    $dashboard=request('/customer/dashboard.php');
    $promote=db()->prepare("UPDATE users SET role='admin' WHERE id=?"); $promote->execute([$user['id']]);
    check(request('/admin/index.php')['status']===200,'Authorized administrator can access foundation');
    check(request('/customer/dashboard.php')['status']===403,'Admin cannot access customer-only page');
    request('/auth/logout.php',['csrf'=>token($dashboard)]);
    for($i=0;$i<10;$i++) {
        $login=request('/auth/login.php');
        $attempt=request('/auth/login.php',['csrf'=>token($login),'email'=>$throttleEmail,'password'=>'wrong-password']);
        check($attempt['status']===200,'Throttle pre-limit attempt '.($i+1));
    }
    $login=request('/auth/login.php');
    check(request('/auth/login.php',['csrf'=>token($login),'email'=>$throttleEmail,'password'=>'wrong-password'])['status']===429,'Persistent login throttle blocks eleventh attempt');
    $catalog=services(); check(count($catalog)===3,'Exactly three seeded services');
    check((int)db()->query('SELECT COUNT(*) FROM rooms')->fetchColumn()===7,'Exactly seven seeded rooms');
    check((int)$catalog[0]['base_price']===800 && (int)$catalog[1]['base_price']===1800,'Fixed individual and couples pricing');
    foreach([4=>4000,5=>4750,6=>5500] as $guests=>$total) check(service_total($catalog[2],$guests)===$total,"Family pricing: $guests guests = $total");
    try { service_total($catalog[2],7); check(false,'Reject overcapacity'); } catch(InvalidArgumentException) { check(true,'Price helper rejects more than six guests'); }
    check((int)settings()['cleaning_gap_minutes']===30 && (int)settings()['cancellation_notice_hours']===6,'Confirmed business settings seeded');
    check(settings()['downpayment_percentage']===null && settings()['grace_period_minutes']===null,'Unconfirmed policies remain unset');
    check((int)db()->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===0,'No fake appointments created');
    check((int)db()->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0,'No fake payments created');
    echo "\n$checks checks passed.\n";
} finally {
    $delete=db()->prepare('DELETE FROM users WHERE email=?'); $delete->execute([$email]);
    $delete=db()->prepare('DELETE FROM login_attempts WHERE email_hash IN (?,?)'); $delete->execute([hash('sha256',$email),hash('sha256',$throttleEmail)]);
    if (is_file($cookie)) unlink($cookie);
}
