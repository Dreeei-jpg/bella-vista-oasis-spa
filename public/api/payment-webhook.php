<?php
require __DIR__.'/../../app/bootstrap.php';header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"POST required"}';exit;}
try{
    $body=file_get_contents('php://input',false,null,0,1048577);
    if(strlen($body)>1048576){http_response_code(413);echo '{"error":"Payload too large"}';exit;}
    receive_payment_webhook($body,function_exists('getallheaders')?getallheaders():[]);
    echo '{"received":true}';
}catch(PaymentUnavailable $e){http_response_code(503);echo '{"error":"Payment verification is unavailable"}';}
catch(InvalidPaymentSignature $e){http_response_code(400);error_log('Payment webhook signature rejected');echo '{"error":"Invalid webhook signature"}';}
catch(Throwable $e){http_response_code(500);error_log('Payment webhook processing failed');echo '{"error":"Payment verification could not be completed"}';}
