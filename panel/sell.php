<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/icons.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/lib/quota.php';
if ($pdo instanceof PDO) {
    hamoix_quota_ensure_table($pdo);
}

$query = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
$query->execute([':username' => (string) ($_SESSION['user'] ?? '')]);
$adminRow = $query->fetch(PDO::FETCH_ASSOC);
if (!isset($_SESSION['user']) || !$adminRow) {
    header('Location: login.php');
    exit;
}

$_csrf = hamoix_csrf_token();
$saleError = '';

function admin_sale_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_sale_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
    );
    $stmt->execute([':table' => $table]);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[(string) $column] = true;
    }
    return $columns;
}

function admin_sale_insert_invoice(PDO $pdo, array $values): void
{
    $available = admin_sale_columns($pdo, 'invoice');
    $values = array_filter($values, static function ($value, $column) use ($available) {
        return isset($available[$column]);
    }, ARRAY_FILTER_USE_BOTH);
    if (!isset($values['id_invoice'], $values['id_user'], $values['username'])) {
        throw new RuntimeException('ساختار جدول سفارشات کامل نیست.');
    }
    $columns = array_keys($values);
    $quoted = array_map(static function ($column) { return '`' . $column . '`'; }, $columns);
    $placeholders = array_map(static function ($column) { return ':' . $column; }, $columns);
    $stmt = $pdo->prepare(
        'INSERT INTO `invoice` (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    foreach ($values as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->execute();
}

$selectedUserId = trim((string) ($_GET['user_id'] ?? $_POST['user_id'] ?? ''));
$selectedUser = null;
if ($selectedUserId !== '') {
    $userStmt = $pdo->prepare("SELECT * FROM user WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $selectedUserId]);
    $selectedUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedUser) {
        $saleError = 'کاربر انتخاب‌شده یافت نشد.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'sell_service') {
    hamoix_csrf_check();

    $selectedUserId = trim((string) ($_POST['user_id'] ?? ''));
    $userStmt = $pdo->prepare("SELECT * FROM user WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $selectedUserId]);
    $selectedUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $code = trim((string) ($_POST['product'] ?? ''));
    $panelChoice = trim((string) ($_POST['panel'] ?? ''));
    $serviceUsername = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['service_username'] ?? ''));

    $productStmt = $pdo->prepare("SELECT * FROM product WHERE code_product = :code LIMIT 1");
    $productStmt->execute([':code' => $code]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$selectedUser) {
        $saleError = 'لطفاً یک کاربر معتبر انتخاب کنید.';
    } elseif (!$product) {
        $saleError = 'محصول انتخاب‌شده معتبر نیست.';
    } elseif (strlen($serviceUsername) < 3) {
        $saleError = 'نام کاربری سرویس باید حداقل ۳ کاراکتر انگلیسی باشد.';
    }

    $panelName = '';
    if ($saleError === '') {
        $location = trim((string) ($product['Location'] ?? ''));
        if ($location !== '' && $location !== '/all') {
            $panelName = $location;
        } else {
            $panelName = $panelChoice;
        }
        $panelStmt = $pdo->prepare("SELECT name_panel FROM marzban_panel WHERE name_panel = :name LIMIT 1");
        $panelStmt->execute([':name' => $panelName]);
        if ($panelName === '' || !$panelStmt->fetchColumn()) {
            $saleError = 'پنل مقصد معتبر نیست یا محصول به پنل موجودی متصل نشده است.';
        }
    }

    $price = 0;
    $days = 0;
    $volumeGb = 0.0;
    $expire = 0;
    if ($saleError === '') {
        $price = max(0, (int) preg_replace('/[^0-9]/', '', (string) ($product['price_product'] ?? '0')));
        $days = max(0, (int) ($product['Service_time'] ?? 0));
        $volumeRaw = str_replace(',', '.', trim((string) ($product['Volume_constraint'] ?? '0')));
        $volumeGb = is_numeric($volumeRaw) ? max(0, (float) $volumeRaw) : 0.0;
        $expire = $days > 0 ? strtotime('+' . $days . ' days') : 0;
    }

    $externalServiceCreated = false;
    $createdOutput = null;
    if ($saleError === '') {
        try {
            // Lock the customer's wallet until both panel provisioning and invoice
            // creation finish, so two simultaneous sales cannot spend the same balance.
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT Balance FROM user WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => $selectedUserId]);
            $currentBalance = $lock->fetchColumn();
            if ($currentBalance === false) {
                throw new RuntimeException('کاربر در زمان ثبت سفارش یافت نشد.');
            }
            $currentBalance = (int) $currentBalance;
            if ($currentBalance < $price) {
                throw new RuntimeException('موجودی کیف پول کاربر برای این محصول کافی نیست.');
            }

            $dataConfig = [
                'expire' => $expire,
                'data_limit' => $volumeGb > 0 ? (int) round($volumeGb * 1073741824) : 0,
                'from_id' => 'admin:' . (string) $adminRow['username'],
                'username' => (string) $selectedUser['username'],
                'type' => 'admin_sale',
            ];
            $manager = new ManagePanel();
            $createdOutput = $manager->createUser($panelName, (string) $product['code_product'], $serviceUsername, $dataConfig);
            if (!is_array($createdOutput) || ($createdOutput['status'] ?? '') !== 'successful') {
                $message = is_array($createdOutput) ? (string) ($createdOutput['msg'] ?? 'خطای نامشخص پنل') : 'پاسخ نامعتبر از پنل';
                throw new RuntimeException('ساخت سرویس در پنل ناموفق بود: ' . $message);
            }
            $externalServiceCreated = true;

            $configs = $createdOutput['configs'] ?? [];
            if (!is_array($configs)) {
                $configs = trim((string) $configs) === '' ? [] : [(string) $configs];
            }
            $invoiceId = 'ADM-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $invoiceValues = [
                'id_invoice' => $invoiceId,
                'id_user' => $selectedUserId,
                'username' => $serviceUsername,
                'Service_location' => $panelName,
                'time_sell' => (string) time(),
                'name_product' => (string) ($product['name_product'] ?? $product['code_product']),
                'price_product' => (string) $price,
                'Volume' => (string) ($product['Volume_constraint'] ?? '0'),
                'Service_time' => (string) ($product['Service_time'] ?? '0'),
                'uuid' => json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'note' => 'فروش از پنل مدیریت کل',
                'user_info' => json_encode([
                    'subscription_url' => (string) ($createdOutput['subscription_url'] ?? ''),
                    'admin' => (string) $adminRow['username'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'bottype' => 'web_admin',
                'refral' => 'none',
                'time_cron' => '0',
                'notifctions' => json_encode(['volume' => false, 'time' => false]),
                'Status' => 'active',
            ];
            admin_sale_insert_invoice($pdo, $invoiceValues);
            hamoix_quota_register(
                $pdo,
                $panelName,
                $serviceUsername,
                $volumeGb > 0 ? (int) round($volumeGb * 1073741824) : 0,
                $product['inbounds'] ?? [],
                'admin_sale',
                $invoiceId
            );
            if ($price > 0) {
                $debit = $pdo->prepare("UPDATE user SET Balance = Balance - :amount WHERE id = :id");
                $debit->execute([':amount' => $price, ':id' => $selectedUserId]);
                if ($debit->rowCount() !== 1) {
                    throw new RuntimeException('کسر موجودی کاربر انجام نشد.');
                }
            }
            $pdo->commit();

            $_SESSION['admin_sale_result'] = [
                'invoice_id' => $invoiceId,
                'username' => $serviceUsername,
                'subscription_url' => (string) ($createdOutput['subscription_url'] ?? ''),
                'configs' => $configs,
                'user_id' => $selectedUserId,
            ];
            header('Location: sell.php?user_id=' . urlencode($selectedUserId) . '&sold=1');
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($externalServiceCreated && $createdOutput && is_array($createdOutput)) {
                try {
                    (new ManagePanel())->RemoveUser($panelName, $serviceUsername);
                } catch (\Throwable $cleanupError) {
                    error_log('[panel/sell] cleanup failed: ' . $cleanupError->getMessage());
                }
            }
            error_log('[panel/sell] sale failed: ' . $e->getMessage());
            $saleError = $e->getMessage();
        }
    }
}

$saleResult = $_SESSION['admin_sale_result'] ?? null;
unset($_SESSION['admin_sale_result']);
$users = $pdo->query("SELECT id, username, Balance FROM user ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT * FROM product ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$panelRows = $pdo->query("SELECT name_panel FROM marzban_panel ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$panels = array_values(array_filter(array_map(static function ($row) {
    return (string) ($row['name_panel'] ?? '');
}, $panelRows), static function ($name) {
    return $name !== '';
}));

$defaultServiceUsername = '';
if ($selectedUser) {
    $defaultServiceUsername = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($selectedUser['username'] ?? ''));
    if (strlen($defaultServiceUsername) < 3) {
        $defaultServiceUsername = 'user_' . preg_replace('/[^0-9]/', '', (string) ($selectedUser['id'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>فروش کانفیگ | Hamoix</title>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
</head>
<body>
<section id="container">
    <?php include('header.php'); ?>
    <section id="main-content">
        <div class="wrapper">
            <div class="page-head">
                <div>
                    <div class="page-head__title"><?php echo icon('cart-shopping', 'svg-icon svg-lg'); ?> فروش کانفیگ</div>
                    <div class="page-head__sub">فروش مستقیم محصول توسط مدیرکل و ثبت سفارش برای مشتری</div>
                </div>
                <div class="chip-row"><a href="users.php" class="btn btn-sm btn-outline"><?php echo icon('users', 'svg-icon'); ?> کاربران</a></div>
            </div>

            <?php if ($saleError !== ''): ?>
                <div class="alert alert-error"><?php echo icon('circle-exclamation', 'svg-icon'); ?><span><?php echo admin_sale_escape($saleError); ?></span></div>
            <?php endif; ?>
            <?php if ($saleResult && ($saleResult['user_id'] ?? '') === $selectedUserId): ?>
                <div class="alert alert-success">
                    <?php echo icon('circle-check', 'svg-icon'); ?>
                    <div>
                        <strong>سرویس با موفقیت ساخته و فروخته شد.</strong>
                        <div style="margin-top:6px;">نام سرویس: <code><?php echo admin_sale_escape($saleResult['username']); ?></code> — سفارش: <code><?php echo admin_sale_escape($saleResult['invoice_id']); ?></code></div>
                        <?php if (!empty($saleResult['subscription_url'])): ?>
                            <div style="margin-top:6px; direction:ltr; word-break:break-all;"><a class="text-link" href="<?php echo admin_sale_escape($saleResult['subscription_url']); ?>" target="_blank" rel="noopener">لینک اشتراک</a></div>
                        <?php endif; ?>
                        <?php if (!empty($saleResult['configs'])): ?>
                            <div style="margin-top:10px; display:grid; gap:6px; direction:ltr; text-align:left;">
                                <?php foreach ($saleResult['configs'] as $config): ?>
                                    <code style="display:block; word-break:break-all; padding:7px; background:rgba(0,0,0,.16); border-radius:6px;"><?php echo admin_sale_escape($config); ?></code>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card__head"><div class="card__title"><?php echo icon('cart-shopping', 'svg-icon svg-md'); ?> مشخصات فروش</div></div>
                <div style="padding:16px;">
                    <?php if (!$users): ?>
                        <div class="alert alert-info">ابتدا از بخش کاربران یک مشتری ایجاد کنید.</div>
                    <?php elseif (!$products): ?>
                        <div class="alert alert-info">هنوز محصولی برای فروش تعریف نشده است.</div>
                    <?php else: ?>
                    <form method="POST" action="sell.php" autocomplete="off">
                        <?php echo hamoix_csrf_field(); ?>
                        <input type="hidden" name="_action" value="sell_service">
                        <div class="form-group">
                            <label class="form-label">مشتری</label>
                            <select name="user_id" class="form-control" required>
                                <option value="">— انتخاب مشتری —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo admin_sale_escape($u['id']); ?>" <?php echo (string) $u['id'] === $selectedUserId ? 'selected' : ''; ?>>
                                        <?php echo admin_sale_escape($u['username']); ?> — موجودی <?php echo number_format((int) $u['Balance']); ?> تومان
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">محصول</label>
                            <select name="product" id="adminSaleProduct" class="form-control" required>
                                <option value="">— انتخاب محصول —</option>
                                <?php foreach ($products as $p): ?>
                                    <?php $loc = trim((string) ($p['Location'] ?? '')); ?>
                                    <option value="<?php echo admin_sale_escape($p['code_product']); ?>" data-location="<?php echo admin_sale_escape($loc); ?>">
                                        <?php echo admin_sale_escape($p['name_product'] ?? $p['code_product']); ?> — <?php echo number_format((int) preg_replace('/[^0-9]/', '', (string) ($p['price_product'] ?? '0'))); ?> تومان (<?php echo admin_sale_escape((string) ($p['Volume_constraint'] ?? '0')); ?>GB / <?php echo admin_sale_escape((string) ($p['Service_time'] ?? '0')); ?> روز)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="adminSalePanelGroup">
                            <label class="form-label">پنل مقصد</label>
                            <select name="panel" class="form-control">
                                <option value="">— انتخاب پنل —</option>
                                <?php foreach ($panels as $panel): ?>
                                    <option value="<?php echo admin_sale_escape($panel); ?>"><?php echo admin_sale_escape($panel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">برای محصولی که لوکیشن مشخص دارد، پنل محصول به‌صورت خودکار استفاده می‌شود.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">نام کاربری سرویس</label>
                            <input type="text" name="service_username" class="form-control" style="direction:ltr;" pattern="[A-Za-z0-9_]{3,}" value="<?php echo admin_sale_escape($defaultServiceUsername); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary" <?php echo (!$users || !$products) ? 'disabled' : ''; ?>>
                            <?php echo icon('check', 'svg-icon'); ?> ساخت سرویس، کسر موجودی و ثبت سفارش
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedUser): ?>
                <div class="card" style="margin-top:14px;">
                    <div class="card__head"><div class="card__title"><?php echo icon('user', 'svg-icon svg-md'); ?> مشتری انتخاب‌شده</div></div>
                    <div style="padding:16px; display:flex; gap:24px; flex-wrap:wrap;">
                        <span>نام کاربری: <strong><?php echo admin_sale_escape($selectedUser['username']); ?></strong></span>
                        <span>موجودی: <strong><?php echo number_format((int) $selectedUser['Balance']); ?> تومان</strong></span>
                        <a href="user.php?id=<?php echo urlencode((string) $selectedUser['id']); ?>" class="text-link">مدیریت کاربر</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</section>
<script>
(function () {
    var product = document.getElementById('adminSaleProduct');
    var group = document.getElementById('adminSalePanelGroup');
    if (!product || !group) return;
    function sync() {
        var option = product.options[product.selectedIndex];
        var location = option ? (option.getAttribute('data-location') || '') : '';
        group.style.display = location && location !== '/all' ? 'none' : '';
    }
    product.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
