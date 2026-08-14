<?php

require_once __DIR__ . '/layout.php';

$reseller = reseller_require_login();
$pdo = $GLOBALS['pdo'];
$rid = (int) $reseller['id'];

$minWithdraw = reseller_min_withdraw($reseller);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_csrf_check();
    $amount = (int) preg_replace('/[^0-9]/', '', (string) ($_POST['amount'] ?? '0'));
    $network = (string) ($_POST['network'] ?? 'USDT-TRC20');
    $address = trim((string) ($_POST['address'] ?? ''));

    $allowedNetworks = ['USDT-TRC20', 'TRON'];
    if (!in_array($network, $allowedNetworks, true)) {
        $network = 'USDT-TRC20';
    }

    // Re-read a fresh balance to avoid acting on stale session data.
    $fresh = reseller_find_by_id($rid);
    $balance = (int) ($fresh['balance'] ?? 0);

    $err = '';
    if ($address === '' || strlen($address) < 20) {
        $err = 'آدرس کیف پول مقصد نامعتبر است.';
    } elseif ($amount < $minWithdraw) {
        $err = 'حداقل مبلغ برداشت ' . number_format($minWithdraw) . ' تومان است.';
    } elseif ($amount > $balance) {
        $err = 'مبلغ درخواستی از موجودی کیف پول بیشتر است.';
    }

    if ($err !== '') {
        reseller_flash_set('error', $err);
        header('Location: withdraw.php');
        exit;
    }

    // Hold the funds immediately (debit now, refund automatically if rejected).
    $hold = reseller_wallet_apply($rid, 'withdraw', -$amount, 'درخواست برداشت ' . $network, $address);
    if (!$hold['ok']) {
        reseller_flash_set('error', $hold['msg'] ?: 'خطا در ثبت درخواست.');
        header('Location: withdraw.php');
        exit;
    }
    // اگر ثبت ردیف درخواست برداشت شکست بخورد، باید مبلغِ از قبل کسرشده را بازگردانیم؛
    // در غیر این صورت پول از کیف پول نماینده کم می‌شد بدون آنکه درخواستی ثبت شده باشد.
    try {
        $ins = $pdo->prepare(
            "INSERT INTO reseller_withdraw (reseller_id, amount, network, address, status, created_at)
             VALUES (:rid, :amt, :net, :addr, 'pending', :ts)"
        );
        $ins->execute([
            ':rid'  => $rid,
            ':amt'  => $amount,
            ':net'  => $network,
            ':addr' => mb_substr($address, 0, 190),
            ':ts'   => (string) time(),
        ]);
    } catch (\Throwable $e) {
        error_log('[reseller withdraw] insert failed, refunding hold for reseller ' . $rid . ': ' . $e->getMessage());
        reseller_wallet_apply($rid, 'withdraw_refund', $amount, 'بازگشت برداشت ناموفق', $address);
        reseller_flash_set('error', 'خطا در ثبت درخواست برداشت؛ مبلغ به کیف پول بازگشت داده شد.');
        header('Location: withdraw.php');
        exit;
    }
    reseller_flash_set('success', 'درخواست برداشت ثبت شد و پس از تأیید مدیر پرداخت می‌شود.');
    header('Location: withdraw.php');
    exit;
}

// Refresh reseller (balance may have changed above).
$reseller = reseller_find_by_id($rid) ?: $reseller;

$rows = $pdo->prepare("SELECT * FROM reseller_withdraw WHERE reseller_id = :id ORDER BY id DESC LIMIT 30");
$rows->execute([':id' => $rid]);
$withdrawRows = $rows->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = ['pending' => 'در انتظار تأیید', 'approved' => 'تأیید و پرداخت شد', 'rejected' => 'رد شد'];
$statusBadge = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-danger'];

reseller_layout_head('برداشت وجه', 'withdraw', $reseller);
?>
<div class="page-head">
    <div>
        <div class="page-head__title"><?php echo icon('coins', 'svg-icon svg-lg'); ?> برداشت وجه</div>
        <div class="page-head__sub">درخواست برداشت درآمد با USDT / TRON</div>
    </div>
</div>

<?php reseller_flash_render(); ?>

<div class="card">
    <div class="card__head"><div class="card__title"><?php echo icon('paper-plane', 'svg-icon svg-sm'); ?> درخواست جدید</div></div>
    <div style="padding:16px;">
        <div class="alert alert-info">
            <?php echo icon('circle-info', 'svg-icon'); ?>
            <span>موجودی فعلی: <b><?php echo number_format((int) $reseller['balance']); ?></b> تومان — حداقل برداشت: <b><?php echo number_format($minWithdraw); ?></b> تومان</span>
        </div>
        <form method="post" action="withdraw.php">
            <?php echo reseller_csrf_field(); ?>
            <div class="form-row" style="display:flex; gap:14px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:180px;">
                    <label class="form-label">مبلغ (تومان)</label>
                    <input type="number" name="amount" min="<?php echo $minWithdraw; ?>" step="1000" class="form-control" required>
                </div>
                <div class="form-group" style="flex:1; min-width:180px;">
                    <label class="form-label">شبکه</label>
                    <select name="network" class="form-control">
                        <option value="USDT-TRC20">USDT (TRC20)</option>
                        <option value="TRON">TRON (TRX)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">آدرس کیف پول مقصد</label>
                <input type="text" name="address" class="form-control" style="direction:ltr;" placeholder="T..." required>
            </div>
            <button type="submit" class="btn btn-primary mt-2"><?php echo icon('arrow-left', 'svg-icon svg-sm'); ?> ثبت درخواست برداشت</button>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card__head"><div class="card__title"><?php echo icon('receipt', 'svg-icon svg-sm'); ?> تاریخچه برداشت</div></div>
    <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th>مبلغ</th><th>شبکه</th><th>آدرس</th><th>وضعیت</th><th>کد تراکنش</th><th>تاریخ</th></tr></thead>
            <tbody>
                <?php if (!$withdrawRows): ?>
                    <tr><td colspan="6" style="text-align:center; padding:24px;">درخواستی ثبت نشده است.</td></tr>
                <?php else: ?>
                    <?php foreach ($withdrawRows as $w): ?>
                        <tr>
                            <td style="direction:ltr;"><?php echo number_format((int) $w['amount']); ?></td>
                            <td style="direction:ltr;"><?php echo reseller_e($w['network']); ?></td>
                            <td style="direction:ltr; max-width:180px; overflow:hidden; text-overflow:ellipsis;" title="<?php echo reseller_e($w['address']); ?>"><?php echo reseller_e($w['address']); ?></td>
                            <td><span class="badge <?php echo $statusBadge[$w['status']] ?? 'badge-gray'; ?>"><?php echo reseller_e($statusLabels[$w['status']] ?? $w['status']); ?></span>
                                <?php if (($w['admin_note'] ?? '') !== ''): ?><br><small class="text-muted"><?php echo reseller_e($w['admin_note']); ?></small><?php endif; ?>
                            </td>
                            <td style="direction:ltr;"><?php echo reseller_e(($w['txid'] ?? '') !== '' ? $w['txid'] : '—'); ?></td>
                            <td><?php echo reseller_jdate($w['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
reseller_layout_foot();
