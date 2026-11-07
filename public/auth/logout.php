<?php
require __DIR__ . '/../../app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Sign out using the button in your account.'); }
verify_csrf();
$_SESSION=[];
$cookie=session_get_cookie_params();
setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$cookie['path'],'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
session_destroy();
redirect('/auth/login.php');
