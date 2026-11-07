<?php
// Both queries are scoped to the signed-in customer and optionally one appointment.
$testRecords = customer_demo_payments((int)$user['id'], $demoAppointmentId ?? null);
?>
<?php if (!$testRecords && !$paymentRecords): ?>
    <div class="empty-state"><h3>No payment activity yet.</h3><p>Recorded transactions will appear here after checkout.</p></div>
<?php endif; ?>
<?php if ($testRecords): ?>
    <p class="notice"><strong>Test checkout — No actual funds will be transferred.</strong></p>
    <div class="table-scroll payment-ledger" tabindex="0" role="region" aria-label="Test payment history"><table>
        <thead><tr><th>Payment date / booking</th><th>Payment method</th><th>Total amount</th><th>Amount recorded</th><th>Remaining balance</th><th>Transaction reference</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($testRecords as $record): ?>
            <tr>
                <td><?= e(local_time($record['created_at'])->format('M j, Y · g:i A')) ?><small class="booking-reference"><?= e($record['booking_reference']) ?></small></td>
                <td><?= e(DEMO_METHODS[$record['method']]) ?><small><?= e(ucfirst($record['payment_option'])) ?></small></td>
                <td><?= peso_amount((int)$record['total_centavos']) ?></td>
                <td><?= peso_amount((int)$record['amount_centavos']) ?></td>
                <td><?= peso_amount((int)$record['remaining_centavos']) ?></td>
                <td class="transaction-reference"><?= e(test_payment_reference($record['transaction_reference'])) ?></td>
                <td><?= e(ucfirst($record['status'])) ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>
<?php if ($paymentRecords): ?>
    <h3>Provider activity</h3>
    <div class="table-scroll payment-ledger" tabindex="0" role="region" aria-label="Provider payment activity"><table>
        <thead><tr><th>Payment date / booking</th><th>Amount / type</th><th>Transaction reference</th><th>Status</th><th>Verified date</th></tr></thead>
        <tbody><?php foreach ($paymentRecords as $payment): ?>
            <tr>
                <td><?= e(local_time($payment['created_at'])->format('M j, Y · g:i A')) ?><small class="booking-reference"><?= e($payment['booking_reference']) ?></small></td>
                <td><?= $payment['currency']==='PHP'?peso_amount((int)$payment['amount_centavos']):e($payment['currency'].' '.decimal_amount((int)$payment['amount_centavos'])) ?><small><?= e(ucfirst($payment['payment_type'])) ?> · <?= e($payment['record_kind']) ?></small></td>
                <td class="transaction-reference"><?= e($payment['provider_reference'] ?: '—') ?></td>
                <td><?= e(ucfirst(str_replace('_',' ',$payment['status']))) ?><?= $payment['allocation_status']==='review'?' · allocation review':'' ?></td>
                <td><?= $payment['verified_at']?e(local_time($payment['verified_at'])->format('M j, Y · g:i A')):'—' ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="small-note">Dates are Philippine time. Pending attempts and transactions awaiting allocation review do not reduce the balance.</p>
<?php endif; ?>
