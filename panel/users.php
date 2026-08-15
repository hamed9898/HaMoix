<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/icons.php';

$query = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
$query->bindParam("username", $_SESSION["user"], PDO::PARAM_STR);
$query->execute();
$result = $query->fetch(PDO::FETCH_ASSOC);

if (!isset($_SESSION["user"]) || !$result) {
    header('Location: login.php');
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrf = (string) $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_user') {
    if (!hash_equals($_csrf, (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        exit('درخواست نامعتبر — توکن CSRF اشتباه است');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $username = preg_replace('/[\x00-\x1F\x7F]/u', '', $username);
    $number = preg_replace('/[^0-9+\-() ]/', '', (string) ($_POST['number'] ?? ''));
    $number = trim($number) !== '' ? trim($number) : 'none';
    // This page creates customer accounts; reseller accounts use the separate
    // reseller management flow and are not rows in the customer table.
    $agent = 'f';
    $balance = filter_var($_POST['balance'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $balance = $balance === false ? 0 : $balance;

    $error = '';
    if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 500) {
        $error = 'نام کاربری باید بین ۲ تا ۵۰۰ کاراکتر باشد.';
    } elseif (!in_array($agent, ['f', 'n', 'n2'], true)) {
        $error = 'نوع کاربر نامعتبر است.';
    } else {
        $dup = $pdo->prepare("SELECT 1 FROM user WHERE username = :username LIMIT 1");
        $dup->execute([':username' => $username]);
        if ($dup->fetchColumn()) {
            $error = 'این نام کاربری قبلاً ثبت شده است.';
        }
    }

    if ($error === '') {
        try {
            $userId = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = (string) random_int(100000000, 9999999999);
                $checkId = $pdo->prepare("SELECT 1 FROM user WHERE id = :id LIMIT 1");
                $checkId->execute([':id' => $candidate]);
                if (!$checkId->fetchColumn()) {
                    $userId = $candidate;
                    break;
                }
            }
            if ($userId === '') {
                throw new RuntimeException('شناسه یکتا برای کاربر تولید نشد.');
            }

            // Older installations may have a slightly different user schema.
            // Insert only columns that exist, while keeping standard fields populated.
            $columnRows = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user'");
            $available = [];
            foreach ($columnRows ? $columnRows->fetchAll(PDO::FETCH_COLUMN) : [] as $column) {
                $available[(string) $column] = true;
            }
            $userValues = [
                'id' => $userId, 'limit_usertest' => 0, 'roll_Status' => 0, 'username' => $username,
                'Processing_value' => '', 'Processing_value_one' => '', 'Processing_value_tow' => '', 'Processing_value_four' => '',
                'step' => '', 'number' => $number, 'Balance' => $balance, 'User_Status' => 'active', 'pagenumber' => 0,
                'message_count' => '0', 'last_message_time' => '0', 'agent' => $agent, 'affiliatescount' => '0',
                'affiliates' => '0', 'namecustom' => 'none', 'number_username' => '100', 'register' => 'panel',
                'verify' => '1', 'cardpayment' => '1', 'pricediscount' => '0', 'maxbuyagent' => '0',
                'joinchannel' => '0', 'checkstatus' => '0', 'bottype' => 'web', 'score' => 0,
                'limitchangeloc' => '0', 'status_cron' => '1', 'expire' => '', 'token' => '',
            ];
            $userValues = array_filter($userValues, static function ($value, $column) use ($available) {
                return isset($available[$column]);
            }, ARRAY_FILTER_USE_BOTH);
            if (!isset($userValues['id'], $userValues['username'])) {
                throw new RuntimeException('ساختار جدول کاربران کامل نیست.');
            }
            $columns = array_keys($userValues);
            $quotedColumns = array_map(static function ($column) { return '`' . $column . '`'; }, $columns);
            $placeholders = array_map(static function ($column) { return ':' . $column; }, $columns);
            $insert = $pdo->prepare(
                'INSERT INTO `user` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            foreach ($userValues as $column => $value) {
                $insert->bindValue(':' . $column, $value);
            }
            $insert->execute();
            header('Location: users.php?created=1');
            exit;
        } catch (\Throwable $e) {
            error_log('[panel/users] create user failed: ' . $e->getMessage());
            $error = 'ایجاد کاربر انجام نشد؛ ساختار دیتابیس یا اطلاعات ورودی را بررسی کنید.';
        }
    }
    if ($error !== '') {
        $_SESSION['users_error'] = $error;
        header('Location: users.php');
        exit;
    }
}

$usersCreated = isset($_GET['created']);
$usersError = (string) ($_SESSION['users_error'] ?? '');
unset($_SESSION['users_error']);

$query = $pdo->prepare("SELECT * FROM user ORDER BY id DESC");
$query->execute();
$listusers = $query->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>مدیریت کاربران | ربات Hamoix</title>
    <link rel="stylesheet" href="css/theme.css">
<script src="js/theme.js" defer>

</script>
</head>
<body>

<section id="container">
    <?php include("header.php"); ?>

    <section id="main-content">
        <div class="wrapper">

            <div class="page-head">
                <div>
                    <div class="page-head__title">
                        <?php echo icon('users', 'svg-icon svg-lg'); ?>
                        لیست کاربران
                    </div>
                    <div class="page-head__sub">ایجاد مشتری، مدیریت کیف پول و فروش کانفیگ</div>
                </div>
                <div class="chip-row">
                    <button type="button" onclick="openModal('modal-create-user')" class="btn btn-primary btn-sm">
                        <?php echo icon('user-plus', 'svg-icon'); ?> ایجاد کاربر
                    </button>
                    <a href="sell.php" class="btn btn-soft-info btn-sm">
                        <?php echo icon('cart-shopping', 'svg-icon'); ?> فروش کانفیگ
                    </a>
                </div>
            </div>

            <?php if ($usersCreated): ?>
                <div class="alert alert-success"><?php echo icon('circle-check', 'svg-icon'); ?><span>کاربر با موفقیت ایجاد شد.</span></div>
            <?php endif; ?>
            <?php if ($usersError !== ''): ?>
                <div class="alert alert-error"><?php echo icon('circle-exclamation', 'svg-icon'); ?><span><?php echo htmlspecialchars($usersError, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>

            <div class="card">
                <div class="table-wrap">
                    <table id="usersTable" class="display app-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>شناسه (ID)</th>
                                <th>نام کاربری</th>
                                <th>شماره تلفن</th>
                                <th>موجودی</th>
                                <th>زیرمجموعه</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($listusers as $list):
                            $statusClass = 'badge-active';
                            $statusText  = 'فعال';
                            if (strtolower($list['User_Status']) == 'block') {
                                $statusClass = 'badge-block';
                                $statusText  = 'مسدود';
                            }
                            $number = ($list['number'] == "none") ? '<span class="text-muted">---</span>' : htmlspecialchars($list['number'], ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td data-label="شناسه (ID)"><?php echo htmlspecialchars((string) $list['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="نام کاربری" style="direction:ltr; text-align:right;">
                                    <?php echo htmlspecialchars($list['username'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td data-label="شماره تلفن"><?php echo $number; ?></td>
                                <td data-label="موجودی"><?php echo number_format($list['Balance']); ?> <small class="text-muted">تومان</small></td>
                                <td data-label="زیرمجموعه"><?php echo (int)$list['affiliatescount']; ?> <small class="text-muted">نفر</small></td>
                                <td data-label="وضعیت"><span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                <td data-label="عملیات" class="cell-actions">
                                    <a href="user.php?id=<?php echo urlencode((string) $list['id']); ?>" class="btn btn-sm btn-primary">
                                        <?php echo icon('pen-to-square', 'svg-icon'); ?>
                                        مدیریت
                                    </a>
                                    <a href="sell.php?user_id=<?php echo urlencode((string) $list['id']); ?>" class="btn btn-sm btn-soft-success">
                                        <?php echo icon('cart-shopping', 'svg-icon'); ?>
                                        فروش
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>
</section>

<div id="modal-create-user" class="modal-overlay">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-head">
            <span class="modal-head__title">ایجاد مشتری جدید</span>
            <button type="button" class="modal-close" onclick="closeModal('modal-create-user')">&times;</button>
        </div>
        <form method="POST" action="users.php" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="_action" value="create_user">
            <div class="form-group">
                <label class="form-label">نام کاربری مشتری</label>
                <input type="text" name="username" class="form-control" minlength="2" maxlength="500" required placeholder="مثلاً ali_123">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">شماره تماس (اختیاری)</label>
                    <input type="text" name="number" class="form-control" inputmode="tel" placeholder="0912...">
                </div>
                <div class="form-group">
                    <label class="form-label">موجودی اولیه (تومان)</label>
                    <input type="number" name="balance" class="form-control" min="0" value="0">
                </div>
            </div>
            <input type="hidden" name="agent" value="f">
            <div class="alert alert-info" style="margin-top:12px;">
                <?php echo icon('circle-info', 'svg-icon'); ?>
                <span>برای ساخت حساب نمایندگی از بخش «نمایندگان» استفاده کنید؛ این فرم مشتری عادی ایجاد می‌کند.</span>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('modal-create-user')">انصراف</button>
                <button type="submit" class="btn btn-primary btn-sm">ایجاد کاربر</button>
            </div>
        </form>
    </div>
</div>

<script src="js/datatable.js" defer>

</script>
<script>
  document.addEventListener("DOMContentLoaded", function () {
    HamoixDT.init("#usersTable");
  });
</script>
</body>
</html>


