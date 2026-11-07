<?php
$filterDate = is_string($_GET['date'] ?? null) && $_GET['date'] !== '' ? $date : '';
$search = is_string($_GET['search'] ?? null) ? mb_substr($_GET['search'],0,100) : '';
$mode = is_string($_GET['mode'] ?? null) ? $_GET['mode'] : '';
$where = $params = [];
if ($filterDate) {
    [$from,$to] = admin_day($filterDate);
    $where[] = 'recorded_at>=? AND recorded_at<?';
    array_push($params,$from,$to);
}
if ($search !== '') {
    $where[] = '(booking_reference LIKE ? OR customer_name LIKE ? OR transaction_reference LIKE ? OR original_reference LIKE ?)';
    $searchPattern = '%'.$search.'%';
    array_push($params,$searchPattern,$searchPattern,$searchPattern,$searchPattern);
}
if (in_array($mode,['test','verified'],true)) {
    $where[] = 'record_mode=?';
    $params[] = $mode;
}
$query = db()->prepare("SELECT * FROM (
    SELECT 'test' AS record_mode,d.id,d.created_at AS recorded_at,
        CASE WHEN LEFT(d.transaction_reference,5)='DEMO-' THEN CONCAT('TEST-',SUBSTRING(d.transaction_reference,6)) ELSE d.transaction_reference END AS transaction_reference,
        d.transaction_reference AS original_reference,d.method,d.payment_option AS payment_type,
        d.total_centavos,d.amount_centavos,d.remaining_centavos,'PHP' AS currency,d.status,a.booking_reference,
        u.full_name AS customer_name,COALESCE(a.service_name_snapshot,s.name) AS service_name
    FROM demo_payments d JOIN appointments a ON a.id=d.appointment_id
    JOIN users u ON u.id=d.user_id JOIN services s ON s.id=a.service_id WHERE d.is_demo=1
    UNION ALL
    SELECT 'verified',p.id,p.verified_at,p.provider_reference,p.provider_reference,p.provider,p.payment_type,
        ROUND(a.total_amount*100),ROUND(p.amount*100),NULL,p.currency,
        CASE WHEN p.allocation_status='review' THEN 'allocation review' ELSE p.status END,a.booking_reference,
        u.full_name,COALESCE(a.service_name_snapshot,s.name)
    FROM payments p JOIN appointments a ON a.id=p.appointment_id
    JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id
    WHERE p.status='verified' AND p.verified_at IS NOT NULL
) AS payment_history".($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY recorded_at DESC,id DESC LIMIT 500');
$query->execute($params);
$payments = $query->fetchAll();
?>
<div class="notice"><strong>Test checkout — No actual funds will be transferred.</strong> Test transactions and verified transactions are identified in the Mode column. The balance shown for a test transaction is the balance at the time it was recorded.</div>
<form method="get" class="admin-filters">
    <div class="field"><label for="payment-date">Payment date · optional</label><input id="payment-date" type="date" name="date" value="<?= e($filterDate) ?>"></div>
    <div class="field"><label for="payment-search">Booking, transaction or customer</label><input id="payment-search" name="search" value="<?= e($search) ?>" maxlength="100"></div>
    <div class="field"><label for="payment-mode">Mode</label><select id="payment-mode" name="mode"><option value="">All transactions</option><option value="test" <?= $mode==='test'?'selected':'' ?>>Test</option><option value="verified" <?= $mode==='verified'?'selected':'' ?>>Verified</option></select></div>
    <button class="button" type="submit">Filter payments ↗</button>
</form>
<?php if (!$payments): ?><p class="notice">No matching transactions.</p><?php else: ?>
<div class="table-scroll payment-ledger" tabindex="0" role="region" aria-label="Payment history"><table>
    <thead><tr><th>Booking / customer</th><th>Service</th><th>Payment method / type</th><th>Total amount</th><th>Amount recorded</th><th>Remaining balance</th><th>Transaction reference</th><th>Payment date</th><th>Status / mode</th></tr></thead>
    <tbody><?php foreach ($payments as $payment): ?>
        <tr>
            <td><a class="text-link booking-reference" href="<?= e(url('/admin/appointment.php?reference='.rawurlencode($payment['booking_reference']))) ?>"><?= e($payment['booking_reference']) ?></a><small><?= e($payment['customer_name']) ?></small></td>
            <td><?= e($payment['service_name']) ?></td>
            <td><?= e(DEMO_METHODS[$payment['method']] ?? $payment['method']) ?><small><?= e(ucfirst(str_replace('_',' ',$payment['payment_type']))) ?></small></td>
            <td><?= peso_amount((int)$payment['total_centavos']) ?></td>
            <td><?= $payment['currency']==='PHP' ? peso_amount((int)$payment['amount_centavos']) : e($payment['currency'].' '.decimal_amount((int)$payment['amount_centavos'])) ?></td>
            <td><?= isset($payment['remaining_centavos']) ? peso_amount((int)$payment['remaining_centavos']) : '—' ?></td>
            <td class="transaction-reference"><?= e($payment['transaction_reference'] ?: '—') ?></td>
            <td><?= e(local_time($payment['recorded_at'])->format('M j, Y · g:i A')) ?></td>
            <td><?= e(ucfirst($payment['status'])) ?><small><?= $payment['record_mode']==='test' ? 'Test Mode' : 'Verified ledger' ?></small></td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php if (count($payments)===500): ?><p class="small-note">Showing the 500 newest matches. Use the date or reference filter to find older transactions.</p><?php endif; endif; ?>
