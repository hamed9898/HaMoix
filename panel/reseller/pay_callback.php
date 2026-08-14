<?php

/**
 * Gateway return endpoint for reseller wallet top-ups.
 *
 * Zarinpal redirects here via GET (?Authority=&Status=); AqayePardakht posts
 * back invoice_id + transid. Either way we look up the pending top-up by its
 * order id, confirm it with the gateway, and credit the reseller wallet once.
 */

require_once __DIR__ . '/pay_lib.php';

$gw = isset($_GET['gw']) ? preg_replace('/[^a-z]/', '', (string) $_GET['gw']) : '';
$orderId = (string) ($_GET['order'] ?? $_POST['invoice_id'] ?? '');
$orderId = preg_replace('/[^A-Za-z0-9_\-]/', '', $orderId);

$result = ['ok' => false, 'amount' => 0, 'msg' => 'تراکنش یافت نشد'];
$payment = $orderId !== '' ? reseller_payment_find($orderId) : null;

if ($payment) {
    // Zarinpal sends Status=NOK when the user cancels.
    $status = (string) ($_GET['Status'] ?? 'OK');
    if ($gw === 'zarinpal' && $status !== 'OK') {
        $result = ['ok' => false, 'amount' => 0, 'msg' => 'پرداخت توسط کاربر لغو شد'];
    } elseif ((string) $payment['status'] === 'paid') {
        $result = ['ok' => true, 'amount' => (int) $payment['amount'], 'msg' => 'این تراکنش قبلاً تأیید شده است'];
    } else {
        $result = reseller_payment_verify($payment);
    }
}

$ok = !empty($result['ok']);
$amount = (int) ($result['amount'] ?? 0);
$msg = (string) ($result['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-color="blue">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتیجه پرداخت</title>
    <link rel="stylesheet" href="../css/theme.css">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-body" style="text-align:center;">
            <div style="font-size:54px; color:<?php echo $ok ? 'var(--success,#22c55e)' : 'var(--danger,#ef4444)'; ?>;">
                <?php echo icon($ok ? 'circle-check' : 'circle-exclamation', 'svg-icon'); ?>
            </div>
            <h2><?php echo $ok ? 'پرداخت موفق' : 'پرداخت ناموفق'; ?></h2>
            <?php if ($ok && $amount > 0): ?>
                <p>مبلغ <b><?php echo number_format($amount); ?></b> تومان به کیف پول شما اضافه شد.</p>
            <?php endif; ?>
            <?php if ($msg !== ''): ?>
                <p class="text-muted"><?php echo reseller_e($msg); ?></p>
            <?php endif; ?>
            <a href="wallet.php" class="btn btn-primary btn-block mt-2">بازگشت به کیف پول</a>
        </div>
    </div>
</body>
</html>
