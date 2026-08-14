<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pay_lib.php';

$reseller = reseller_require_login();
$pdo = $GLOBALS['pdo'];
$rid = (int) $reseller['id'];

$gateways = reseller_pay_gateways();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_csrf_check();
    $amount = (int) preg_replace('/[^0-9]/', '', (string) ($_POST['amount'] ?? '0'));
    $gateway = (string) ($_POST['gateway'] ?? '');
    if (!isset($gateways[$gateway])) {
        reseller_flash_set('error', 'درگاه انتخابی معتبر نیست.');
        header('Location: wallet.php');
        exit;
    }
    if ($amount < 1000) {
        reseller_flash_set('error', 'حداقل مبلغ شارژ ۱۰۰۰ تومان است.');
        header('Location: wallet.php');
        exit;
    }
    $limitBalance = trim((string) ($reseller['limit_balance'] ?? ''));
    if ($limitBalance !== '' && is_numeric($limitBalance) && ((int) $reseller['balance'] + $amount) > (int) $limitBalance) {
        reseller_flash_set('error', 'مبلغ شارژ از سقف مجاز کیف پول شما بیشتر است. سقف: ' . number_format((int) $limitBalance) . ' تومان');
        header('Location: wallet.php');
        exit;
    }
    $res = reseller_payment_create($rid, $gateway, $amount);
    if (!empty($res['ok']) && !empty($res['url'])) {
        header('Location: ' . $res['url']);
        exit;
    }
    reseller_flash_set('error', $res['msg'] ?? 'خطا در ایجاد تراکنش.');
    header('Location: wallet.php');
    exit;
}

$payments = $pdo->prepare("SELECT * FROM reseller_payment WHERE reseller_id = :id ORDER BY id DESC LIMIT 20");
$payments->execute([':id' => $rid]);
$paymentRows = $payments->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = ['pending' => 'در انتظار', 'paid' => 'موفق', 'failed' => 'ناموفق'];
$gatewayLabels = ['zarinpal' => 'زرین‌پال', 'aqayepardakht' => 'آقای پرداخت'];

reseller_layout_head('کیف پول', 'wallet', $reseller);
?>
<div class="page-head">
    <div>
        <div class="page-head__title"><?php echo icon('wallet', 'svg-icon svg-lg'); ?> کیف پول</div>
        <div class="page-head__sub">شارژ کیف پول از طریق درگاه‌های پرداخت</div>
    </div>
</div>

<?php reseller_flash_render(); ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card__icon icon-green"><?php echo icon('wallet', 'svg-icon'); ?></div>
        <div class="stat-card__info">
            <span class="stat-card__value"><?php echo number_format((float) $reseller['balance']); ?></span>
            <span class="stat-card__label">موجودی فعلی (تومان)</span>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card__head"><div class="card__title"><?php echo icon('plus', 'svg-icon svg-sm'); ?> شارژ کیف پول</div></div>
    <div style="padding:16px;">
        <?php if (!$gateways): ?>
            <div class="alert alert-info">
                <?php echo icon('circle-info', 'svg-icon'); ?>
                <span>هیچ درگاه پرداختی پیکربندی نشده است. لطفاً با مدیر تماس بگیرید.</span>
            </div>
        <?php else: ?>
            <form method="post" action="wallet.php">
                <?php echo reseller_csrf_field(); ?>
                <div class="form-row" style="display:flex; gap:14px; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:200px;">
                        <label class="form-label">مبلغ (تومان)</label>
                        <input type="number" name="amount" min="1000" step="1000" class="form-control" placeholder="مثلاً ۱۰۰۰۰۰" required>
                    </div>
                    <div class="form-group" style="flex:1; min-width:200px;">
                        <label class="form-label">درگاه پرداخت</label>
                        <select name="gateway" class="form-control" required>
                            <?php foreach ($gateways as $key => $label): ?>
                                <option value="<?php echo reseller_e($key); ?>"><?php echo reseller_e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">
                    <?php echo icon('arrow-left', 'svg-icon svg-sm'); ?> پرداخت و شارژ
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card__head"><div class="card__title"><?php echo icon('receipt', 'svg-icon svg-sm'); ?> تاریخچه شارژ</div></div>
    <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th>مبلغ</th><th>درگاه</th><th>وضعیت</th><th>کد پیگیری</th><th>تاریخ</th></tr></thead>
            <tbody>
                <?php if (!$paymentRows): ?>
                    <tr><td colspan="5" style="text-align:center; padding:24px;">تاکنون شارژی ثبت نشده است.</td></tr>
                <?php else: ?>
                    <?php foreach ($paymentRows as $p): ?>
                        <tr>
                            <td style="direction:ltr;"><?php echo number_format((int) $p['amount']); ?></td>
                            <td><?php echo reseller_e($gatewayLabels[$p['gateway']] ?? $p['gateway']); ?></td>
                            <td>
                                <?php $st = (string) $p['status']; ?>
                                <span class="badge <?php echo $st === 'paid' ? 'badge-success' : ($st === 'pending' ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo reseller_e($statusLabels[$st] ?? $st); ?>
                                </span>
                            </td>
                            <td style="direction:ltr;"><?php echo reseller_e($p['ref'] ?? '—'); ?></td>
                            <td><?php echo reseller_jdate($p['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
reseller_layout_foot();
