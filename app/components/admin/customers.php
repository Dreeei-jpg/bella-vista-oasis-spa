<?php
$search = is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']), 0, 100) : '';
$page = is_string($_GET['page'] ?? null) ? max(1, min(10000, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * 50;
$query = db()->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
    (SELECT COUNT(*) FROM appointments a WHERE a.user_id=u.id) AS appointment_count,
    (SELECT MAX(a.starts_at) FROM appointments a WHERE a.user_id=u.id AND a.status IN ('confirmed','ongoing','completed')) AS latest_visit
    FROM users u WHERE u.role='customer'".($search !== '' ? ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)' : '').
    " ORDER BY u.full_name, u.id LIMIT 51 OFFSET $offset");
$pattern = '%'.$search.'%';
$query->execute($search !== '' ? [$pattern,$pattern,$pattern] : []);
$customers = $query->fetchAll();
$hasMore = count($customers) > 50 && $page < 10000;
?>
<form method="get" class="admin-filters">
    <div class="field"><label for="customer-search">Name, email, or phone</label><input id="customer-search" name="search" value="<?= e($search) ?>" maxlength="100"></div>
    <button type="submit" class="button">Find customers ↗</button>
    <?php if ($search !== ''): ?><a class="text-link" href="<?= e(url('/admin/customers.php')) ?>">Clear search</a><?php endif; ?>
</form>
<?php if (!$customers): ?>
    <p class="notice">No customers match this search.</p>
<?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Customer records"><table>
        <thead><tr><th scope="col">Customer</th><th scope="col">Contact</th><th scope="col">Member since</th><th scope="col">Most recent confirmed or completed visit</th><th scope="col">Reservations</th></tr></thead>
        <tbody><?php foreach (array_slice($customers, 0, 50) as $customer): ?>
            <tr><td><?= e($customer['full_name']) ?></td><td><?= e($customer['email']) ?><?php if ($customer['phone']): ?><br><?= e($customer['phone']) ?><?php endif; ?></td>
                <td><?= e(local_time($customer['created_at'])->format('M Y')) ?></td>
                <td><?= $customer['latest_visit'] ? e(local_time($customer['latest_visit'])->format('M j, Y · g:i A')) : '—' ?></td>
                <td><?php if ((int)$customer['appointment_count'] > 0): ?><a class="text-link" href="<?= e(url('/admin/appointments.php?customer_id='.(int)$customer['id'])) ?>">View reservations (<?= (int)$customer['appointment_count'] ?>) ↗</a><?php else: ?>No reservations<?php endif; ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>
<?php if ($page > 1 || $hasMore): ?><div class="inline-actions">
    <?php if ($page > 1): ?><a class="text-link" href="<?= e(url('/admin/customers.php?'.http_build_query(['search'=>$search,'page'=>$page-1]))) ?>">Previous page</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="text-link" href="<?= e(url('/admin/customers.php?'.http_build_query(['search'=>$search,'page'=>$page+1]))) ?>">Next page</a><?php endif; ?>
</div><?php endif; ?>
