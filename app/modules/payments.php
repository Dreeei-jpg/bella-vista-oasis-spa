<?php
declare(strict_types=1);
require_once __DIR__.'/money.php';
require_once __DIR__.'/../payments/Provider.php';

function payment_config(): array { static $c=null; return $c ??= require __DIR__.'/../../config/payments.php'; }
function payment_provider(): ?PaymentProvider
{
    static $loaded=false,$provider=null;
    if (!$loaded) {
        $loaded=true; $c=payment_config(); $registry=require __DIR__.'/../payments/registry.php';
        if (is_string($c['provider']) && isset($registry[$c['provider']]) && is_callable($registry[$c['provider']])) {
            $candidate=$registry[$c['provider']]($c);
            if ($candidate instanceof PaymentProvider) $provider=$candidate;
        }
    }
    return $provider;
}
function payment_rules(): array { return db()->query('SELECT downpayment_percentage,checkout_hold_minutes FROM business_settings WHERE id=1')->fetch(); }
function payment_verification_ready(): bool
{
    // Stopping new checkout must not discard notifications for an earlier payment.
    return payment_provider()!==null && payment_provider()->isConfigured() && in_array(payment_config()['environment'],['sandbox','live'],true);
}
function payment_readiness(): array
{
    $c=payment_config(); $reasons=[];
    if (!$c['enabled']) $reasons[]='Online checkout is disabled.';
    if (!payment_provider() || !payment_provider()->isConfigured()) $reasons[]='A payment provider and verified credentials have not been configured.';
    $origin=is_string($c['public_origin'])?parse_url($c['public_origin']):false;
    if (!$origin || ($origin['scheme']??'')!=='https' || empty($origin['host']) || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])) $reasons[]='A trusted HTTPS return/webhook address has not been configured.';
    if (!$c['checkout_hosts']) $reasons[]='The provider checkout host allowlist has not been configured.';
    if (!in_array($c['environment'],['sandbox','live'],true)) $reasons[]='A valid provider environment is required.';
    if ((int)(payment_rules()['checkout_hold_minutes']??0)<1) $reasons[]='The checkout hold duration is awaiting configuration.';
    return ['ready'=>!$reasons,'reasons'=>$reasons];
}
function checkout_hold_end(DateTimeImmutable $start): DateTimeImmutable
{
    $minutes=(int)(payment_rules()['checkout_hold_minutes']??0);
    if ($minutes<1) throw new PaymentUnavailable('Checkout hold duration has not been configured.');
    $end=(new DateTimeImmutable('now',spa_timezone()))->modify("+$minutes minutes");
    return $end<$start ? $end : $start;
}
function payment_summary(array $appointment): array
{
    if(($appointment['booking_mode']??'')==='demo')return demo_summary($appointment);
    $q=db()->prepare('SELECT * FROM payments WHERE appointment_id=? ORDER BY id'); $q->execute([$appointment['id']]);
    $gross=0; $refunded=0; $review=false;
    foreach ($q->fetchAll() as $payment) {
        if ($payment['allocation_status']==='review') { $review=true; continue; }
        if (!$payment['verified_at'] || !in_array($payment['status'],['verified','refunded'],true)) continue;
        $amount=decimal_centavos($payment['amount']);
        if ($payment['payment_type']==='refund') $refunded+=$amount;
        else { $gross+=$amount; if ($payment['status']==='refunded') $refunded+=$amount; }
    }
    $paid=max(0,$gross-$refunded); $total=decimal_centavos($appointment['total_amount']);
    $q=db()->prepare("SELECT COUNT(*) FROM payment_reconciliation_cases WHERE appointment_id=? AND status='open'");$q->execute([$appointment['id']]);$review=$review || (int)$q->fetchColumn()>0;
    return ['total'=>$total,'gross_paid'=>$gross,'paid'=>$paid,'refunded'=>$refunded,'balance'=>max(0,$total-$paid),'review'=>$review,'is_demo'=>false];
}
function payment_plan(array $a,string $choice): array
{
    $s=payment_summary($a);
    if ($s['review']) throw new BookingValidationException('A payment for this appointment requires administrator review before another checkout.');
    if ($s['balance']<=0) throw new BookingValidationException('This appointment has no outstanding balance.');
    if ($choice==='full' || $choice==='balance') return ['amount'=>$s['balance'],'remaining'=>0,'type'=>$s['paid']>0?'balance':'full'];
    if ($choice!=='downpayment') throw new BookingValidationException('Choose full payment or an available downpayment option.');
    $percentage=payment_rules()['downpayment_percentage'];
    if ($percentage===null) throw new BookingValidationException('The downpayment percentage has not been approved or configured.');
    if ($s['paid']>0) throw new BookingValidationException('A payment has already been made. Choose the remaining balance.');
    $amount=percentage_centavos($s['total'],(string)$percentage);
    if ($amount<1) throw new BookingValidationException('The configured downpayment rounds to less than one centavo.');
    return ['amount'=>$amount,'remaining'=>$s['balance']-$amount,'type'=>'downpayment'];
}
function refresh_payment_status(int $id): void
{
    $q=db()->prepare('SELECT * FROM appointments WHERE id=?');$q->execute([$id]);$a=$q->fetch();$s=payment_summary($a);
    if($a['booking_mode']==='demo') {
        $status=$s['review']?'refund_pending_review':($s['balance']===0?'paid':($s['paid']>0?'partially_paid':($a['status']==='cancelled'?'cancelled':'unpaid')));
        $q=db()->prepare('UPDATE appointments SET payment_status=? WHERE id=?');$q->execute([$status,$id]);return;
    }
    if ($s['review'] || ($a['status']==='cancelled' && $s['paid']>0)) $status='refund_pending_review';
    elseif ($s['refunded']>0) $status=$s['paid']===0?'refunded':'partially_refunded';
    elseif ($s['balance']===0) $status='paid';
    elseif ($s['paid']>0) $status='partially_paid';
    else {
        $q=db()->prepare('SELECT status FROM payment_attempts WHERE appointment_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);$attempt=$q->fetchColumn();
        $status=in_array($attempt,['initiated','pending'],true)?'pending':($attempt==='failed'?'failed':($attempt==='cancelled'?'cancelled':'unpaid'));
        if ($a['status']==='cancelled') $status='cancelled';
    }
    $q=db()->prepare('UPDATE appointments SET payment_status=? WHERE id=?');$q->execute([$status,$id]);
}
function expire_holds_locked(): int
{
    $rows=db()->query("SELECT id FROM appointments WHERE status='awaiting_payment' AND hold_expires_at<=UTC_TIMESTAMP()")->fetchAll(PDO::FETCH_COLUMN);
    $attemptOwners=db()->query("SELECT DISTINCT appointment_id FROM payment_attempts WHERE status IN ('initiated','pending') AND expires_at<=UTC_TIMESTAMP()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        $q=db()->prepare("UPDATE appointments SET status='expired',revision=revision+1 WHERE id=?");$q->execute([$id]);
        audit_appointment((int)$id,null,'hold_expired',['status'=>'awaiting_payment'],['status'=>'expired']);
    }
    db()->exec("UPDATE payment_attempts SET status='expired' WHERE status IN ('initiated','pending') AND expires_at<=UTC_TIMESTAMP()");
    foreach(array_unique(array_merge($rows,$attemptOwners)) as $id) refresh_payment_status((int)$id);
    return count($rows);
}
function expire_payment_holds(): int
{
    $p=db();$p->beginTransaction();
    try { lock_scheduling($p);$n=expire_holds_locked();$p->commit();return $n; }
    catch(Throwable $e) { if($p->inTransaction())$p->rollBack();throw $e; }
}
function payment_attempt_owned(int $userId,string $publicId): array
{
    $q=db()->prepare('SELECT pa.*,a.booking_reference,a.user_id,a.status AS appointment_status,a.hold_expires_at FROM payment_attempts pa JOIN appointments a ON a.id=pa.appointment_id WHERE pa.public_id=? AND a.user_id=?');$q->execute([$publicId,$userId]);
    $row=$q->fetch();if(!$row)throw new BookingValidationException('This payment attempt is not available in your account.');return $row;
}
function safe_checkout_url(string $url): bool
{
    $parts=parse_url($url);
    return $parts && ($parts['scheme']??'')==='https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']) && (!isset($parts['port']) || $parts['port']===443) && in_array(strtolower($parts['host']??''),array_map('strtolower',payment_config()['checkout_hosts']),true);
}
function reconciliation_case(?array $attempt,string $provider,string $reference,?int $amount,?string $currency,string $reason): void
{
    $key=hash('sha256',$provider.'|'.$reference.'|'.($attempt['id']??'unknown').'|'.$reason);
    $q=db()->prepare('INSERT INTO payment_reconciliation_cases (case_key,appointment_id,attempt_id,provider,transaction_reference,observed_amount_centavos,observed_currency,reason_code) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE case_key=VALUES(case_key)');
    $q->execute([$key,$attempt['appointment_id']??null,$attempt['id']??null,$provider,$reference?:null,$amount,$currency,$reason]);
}
function initiate_payment(int $userId,string $reference,string $choice,mixed $revision,string $key): array
{
    action_key($key);
    if (!payment_readiness()['ready']) throw new PaymentUnavailable('Secure checkout is unavailable until a provider and hold duration are configured. No payment was initiated.');
    $p=db();$c=payment_config();$p->beginTransaction();
    try {
        lock_scheduling($p);expire_holds_locked();$a=owned_appointment($userId,$reference);
        $q=$p->prepare('SELECT * FROM payment_attempts WHERE request_key=?');$q->execute([$key]);$attempt=$q->fetch();
        if ($attempt && (int)$attempt['appointment_id']!==(int)$a['id']) throw new BookingValidationException('This checkout form does not match the appointment.');
        if (!$attempt) {
            checked_revision($a,$revision);
            if (!in_array($a['status'],['confirmed','awaiting_payment'],true) || local_time($a['starts_at'])<=new DateTimeImmutable('now',spa_timezone())) throw new BookingValidationException('This appointment cannot open a new checkout.');
            $plan=payment_plan($a,$choice);
            $q=$p->prepare("SELECT * FROM payment_attempts WHERE appointment_id=? AND status IN ('initiated','pending') AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1");$q->execute([$a['id']]);$attempt=$q->fetch();
            if ($attempt && ((int)$attempt['amount_centavos']!==$plan['amount'] || $attempt['payment_type']!==$plan['type'])) throw new BookingValidationException('A checkout is already pending. Review or cancel it before changing the payment option.');
            if (!$attempt) {
                $public=bin2hex(random_bytes(16));$expiry=$a['status']==='awaiting_payment'?$a['hold_expires_at']:utc_time(checkout_hold_end(local_time($a['starts_at'])));
                $q=$p->prepare('INSERT INTO payment_attempts (appointment_id,public_id,request_key,provider,payment_type,amount_centavos,expires_at,terms_accepted_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');$q->execute([$a['id'],$public,$key,$c['provider'],$plan['type'],$plan['amount'],$expiry]);
                $attempt=payment_attempt_owned($userId,$public);
            }
        }
        refresh_payment_status((int)$a['id']);$p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack();throw $e; }
    if (!in_array($attempt['status'],['initiated','pending'],true) || local_time($attempt['expires_at'])<=new DateTimeImmutable('now',spa_timezone())) return payment_attempt_owned($userId,$attempt['public_id']);
    if ($attempt['checkout_url']) return payment_attempt_owned($userId,$attempt['public_id']);
    $origin=rtrim($c['public_origin'],'/');
    try {
        $session=payment_provider()->createCheckout(new CheckoutRequest($attempt['public_id'],$attempt['request_key'],(int)$attempt['amount_centavos'],$attempt['currency'],$origin.'/customer/payment-result.php?attempt='.$attempt['public_id'],$origin.'/api/payment-webhook.php',$attempt['provider_attempt_reference']));
        if (!$session->reference || strlen($session->reference)>150 || !safe_checkout_url($session->url)) throw new PaymentUnavailable('The provider returned an invalid checkout destination.');
        $p->beginTransaction();lock_scheduling($p);
        $current=payment_attempt_owned($userId,$attempt['public_id']);
        if (in_array($current['status'],['initiated','pending'],true) && local_time($current['expires_at'])>new DateTimeImmutable('now',spa_timezone())) {
            $q=$p->prepare("UPDATE payment_attempts SET provider_attempt_reference=?,checkout_url=?,status='pending' WHERE id=?");$q->execute([$session->reference,$session->url,$attempt['id']]);
            $q=$p->prepare("UPDATE payment_reconciliation_cases SET status='resolved' WHERE attempt_id=? AND reason_code='initiation_uncertain'");$q->execute([$attempt['id']]);
            refresh_payment_status((int)$attempt['appointment_id']);
        }
        $p->commit();
    } catch(Throwable $e) {
        if($p->inTransaction())$p->rollBack();
        $p->beginTransaction();lock_scheduling($p);
        reconciliation_case($attempt,$c['provider'],$attempt['public_id'],null,null,'initiation_uncertain');refresh_payment_status((int)$attempt['appointment_id']);$p->commit();
        // A timeout is not proof that the provider failed. Keep the attempt, never charge a new key.
        throw new PaymentUnavailable('Checkout could not be opened reliably. This attempt is recorded for reconciliation; no payment success is assumed.');
    }
    return payment_attempt_owned($userId,$attempt['public_id']);
}

/** Internal service: observations must come from PaymentProvider::fetchPayment, never request JSON. */
function apply_payment_observation(PaymentObservation $o,string $provider,?WebhookEnvelope $event=null,?string $payloadHash=null): void
{
    if (!in_array($o->status,['pending','paid','failed','cancelled','expired'],true) || $o->amountCentavos<0 || $o->amountCentavos>9999999999 || !preg_match('/^[A-Z]{3}$/D',$o->currency) || strlen($o->transactionReference)>150 || strlen($o->attemptReference)>150) throw new RuntimeException('Invalid provider observation.');
    $p=db();$p->beginTransaction();
    try {
        lock_scheduling($p);
        if ($event) {
            $q=$p->prepare('SELECT * FROM payment_webhook_events WHERE provider=? AND provider_event_id=?');$q->execute([$provider,$event->eventId]);$old=$q->fetch();
            if ($old) {
                if (!hash_equals($old['payload_sha256'],$payloadHash??'')) {
                    reconciliation_case(null,$provider,$o->transactionReference,$o->amountCentavos,$o->currency,'event_payload_conflict');
                }
                $p->commit();return;
            }
        }
        $q=$p->prepare('SELECT * FROM payment_attempts WHERE provider=? AND public_id=?');$q->execute([$provider,$o->clientReference]);$attempt=$q->fetch();$review=false;
        if (!$attempt || ($attempt['provider_attempt_reference'] && $attempt['provider_attempt_reference']!==$o->attemptReference)) {
            reconciliation_case($attempt?:null,$provider,$o->transactionReference,$o->amountCentavos,$o->currency,'unknown_or_mismatched_attempt');$review=true;
            if($attempt){$q=$p->prepare("UPDATE payment_attempts SET status='requires_review' WHERE id=?");$q->execute([$attempt['id']]);}
        } elseif ($o->status==='paid') {
            if ($o->amountCentavos<1 || $o->transactionReference==='') throw new RuntimeException('Provider transaction evidence is incomplete.');
            $q=$p->prepare("UPDATE payment_reconciliation_cases SET status='resolved' WHERE attempt_id=? AND reason_code='initiation_uncertain'");$q->execute([$attempt['id']]);
            $q=$p->prepare('SELECT * FROM payments WHERE provider=? AND provider_reference=?');$q->execute([$provider,$o->transactionReference]);$existing=$q->fetch();
            if ($existing) {
                $review=$existing['allocation_status']==='review';
                if ((int)$existing['attempt_id']!==(int)$attempt['id'] || decimal_centavos($existing['amount'])!==$o->amountCentavos || $existing['currency']!==$o->currency) {
                    reconciliation_case($attempt,$provider,$o->transactionReference,$o->amountCentavos,$o->currency,'duplicate_transaction_mismatch');$review=true;
                }
            } else {
                $q=$p->prepare('SELECT * FROM appointments WHERE id=?');$q->execute([$attempt['appointment_id']]);$a=$q->fetch();
                $summary=payment_summary($a);$reason=null;
                if ($o->currency!==$attempt['currency']) $reason='currency_mismatch';
                elseif ($o->amountCentavos!==(int)$attempt['amount_centavos']) $reason='amount_mismatch';
                elseif ($o->amountCentavos>$summary['balance']) $reason='excess_or_duplicate_charge';
                elseif (!in_array($a['status'],['confirmed','awaiting_payment'],true)) $reason='appointment_no_longer_confirmable';
                elseif (local_time($a['starts_at'])<=new DateTimeImmutable('now',spa_timezone())) $reason='appointment_already_started';
                elseif ($a['status']==='awaiting_payment' && (!$a['hold_expires_at'] || local_time($a['hold_expires_at'])<=new DateTimeImmutable('now',spa_timezone()))) $reason='expired_hold';
                if (!$reason && $a['status']==='awaiting_payment') {
                    $window=['start'=>local_time($a['starts_at']),'end'=>local_time($a['ends_at']),'cleaning_end'=>local_time($a['cleaning_ends_at'])];
                    try { $staff=staff_for_existing_appointment($a,$window); }
                    catch (BookingValidationException $exception) { $staff=[]; }
                    if (!room_available((int)$a['room_id'],$window,(int)$a['id']) || !$staff) $reason='resources_no_longer_available';
                    else {
                        $q=$p->prepare('DELETE FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);
                        $q=$p->prepare('INSERT INTO appointment_therapists (appointment_id,therapist_id) VALUES (?,?)');foreach($staff as $t)$q->execute([$a['id'],$t['id']]);
                        $q=$p->prepare("UPDATE appointments SET status='confirmed',hold_expires_at=NULL,revision=revision+1 WHERE id=?");$q->execute([$a['id']]);
                        audit_appointment((int)$a['id'],null,'payment_confirmed',['status'=>'awaiting_payment'],['status'=>'confirmed']);
                    }
                }
                $review=$reason!==null;
                $q=$p->prepare("INSERT INTO payments (appointment_id,attempt_id,provider,provider_reference,idempotency_key,amount,currency,payment_type,status,verified_at,allocation_status) VALUES (?,?,?,?,?,?,?,?,'verified',UTC_TIMESTAMP(),?)");
                $q->execute([$a['id'],$attempt['id'],$provider,$o->transactionReference,hash('sha256',$provider.'|'.$o->transactionReference),decimal_amount($o->amountCentavos),$o->currency,$attempt['payment_type'],$review?'review':'allocated']);
                if ($review) {
                    reconciliation_case($attempt,$provider,$o->transactionReference,$o->amountCentavos,$o->currency,$reason);
                    $q=$p->prepare("UPDATE appointments SET status='expired',hold_expires_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND booking_mode='online' AND status='awaiting_payment'");$q->execute([$a['id']]);
                }
            }
            $q=$p->prepare("UPDATE payment_attempts SET status=?,provider_attempt_reference=COALESCE(provider_attempt_reference,?),verified_at=COALESCE(verified_at,UTC_TIMESTAMP()) WHERE id=?");$q->execute([$review?'requires_review':'verified',$o->attemptReference,$attempt['id']]);
        } elseif (!in_array($attempt['status'],['verified','requires_review'],true)) {
            $q=$p->prepare('UPDATE payment_attempts SET status=? WHERE id=?');$q->execute([$o->status,$attempt['id']]);
            if (in_array($o->status,['failed','cancelled','expired'],true)) {
                $q=$p->prepare("UPDATE appointments SET status='expired',hold_expires_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND booking_mode='online' AND status='awaiting_payment'");$q->execute([$attempt['appointment_id']]);
            }
        }
        if ($attempt) refresh_payment_status((int)$attempt['appointment_id']);
        if ($event) {
            $q=$p->prepare('INSERT INTO payment_webhook_events (provider,provider_event_id,attempt_id,payload_sha256,signature_verified_at,status) VALUES (?,?,?,?,UTC_TIMESTAMP(),?)');$q->execute([$provider,$event->eventId,$attempt['id']??null,$payloadHash,$review?'requires_review':'processed']);
        }
        $p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack();throw $e; }
}
function receive_payment_webhook(string $body,array $headers): void
{
    if (!payment_verification_ready()) throw new PaymentUnavailable('Payment webhooks are disabled until the selected provider is configured.');
    $provider=payment_provider();$event=$provider->authenticateWebhook($body,$headers);
    if ($event->eventId==='' || strlen($event->eventId)>150 || $event->attemptReference==='') throw new InvalidPaymentSignature('Invalid authenticated event envelope.');
    $observation=$provider->fetchPayment($event->attemptReference);
    if ($observation->attemptReference!==$event->attemptReference) throw new InvalidPaymentSignature('Event and provider receipt do not match.');
    apply_payment_observation($observation,payment_config()['provider'],$event,hash('sha256',$body));
}
function reconcile_payment_attempt(array $attempt): void
{
    if (!payment_verification_ready()) throw new PaymentUnavailable('Provider reconciliation is unavailable until configured.');
    if ($attempt['provider']!==payment_config()['provider'] || !$attempt['provider_attempt_reference']) throw new PaymentUnavailable('This attempt needs provider-specific reference recovery.');
    $o=payment_provider()->fetchPayment($attempt['provider_attempt_reference']);
    if ($o->clientReference!==$attempt['public_id'] || $o->attemptReference!==$attempt['provider_attempt_reference']) throw new RuntimeException('Provider receipt identity mismatch.');
    apply_payment_observation($o,$attempt['provider']);
}
function cancel_checkout(int $userId,string $publicId): void
{
    $p=db();$p->beginTransaction();
    try {
        lock_scheduling($p);$a=payment_attempt_owned($userId,$publicId);
        if($a['status']==='cancelled'){$p->commit();return;}
        if (in_array($a['status'],['verified','requires_review'],true)) throw new BookingValidationException('A received payment cannot be cancelled as a checkout. Please request administrator review.');
        $q=$p->prepare("UPDATE payment_attempts SET status='cancelled',checkout_url=NULL WHERE id=?");$q->execute([$a['id']]);
        $q=$p->prepare("UPDATE appointments SET status='expired',hold_expires_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND booking_mode='online' AND status='awaiting_payment'");$q->execute([$a['appointment_id']]);
        refresh_payment_status((int)$a['appointment_id']);$p->commit();
    } catch(Throwable $e) { if($p->inTransaction())$p->rollBack();throw $e; }
}
function customer_payment_records(int $userId,?int $appointmentId=null): array
{
    $q=db()->prepare('SELECT p.*,a.booking_reference FROM payments p JOIN appointments a ON a.id=p.appointment_id WHERE a.user_id=? AND (? IS NULL OR a.id=?) ORDER BY p.created_at DESC,p.id DESC');$q->execute([$userId,$appointmentId,$appointmentId]);$rows=$q->fetchAll();
    foreach($rows as &$r) { $r['amount_centavos']=decimal_centavos($r['amount']);$r['record_kind']='transaction'; }unset($r);
    $q=db()->prepare('SELECT pa.*,a.booking_reference FROM payment_attempts pa JOIN appointments a ON a.id=pa.appointment_id WHERE a.user_id=? AND (? IS NULL OR a.id=?) AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.attempt_id=pa.id) ORDER BY pa.created_at DESC');$q->execute([$userId,$appointmentId,$appointmentId]);
    foreach($q->fetchAll() as $r){$r['provider_reference']=$r['provider_attempt_reference'];$r['allocation_status']='allocated';$r['record_kind']='attempt';$rows[]=$r;}
    usort($rows,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return $rows;
}
