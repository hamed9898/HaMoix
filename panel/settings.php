<?php


session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/icons.php';
require_once __DIR__ . '/lib/maintenance.php';

$query = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
$query->bindValue(":username", $_SESSION["user"] ?? '', PDO::PARAM_STR);
$query->execute();
$adminRow = $query->fetch(PDO::FETCH_ASSOC);
if (!isset($_SESSION["user"]) || !$adminRow) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrf = $_SESSION['csrf_token'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['_csrf'] ?? '';
    if (!is_string($incoming) || !hash_equals((string)$_SESSION['csrf_token'], $incoming)) {
        http_response_code(403);
        exit('درخواست نامعتبر — توکن CSRF اشتباه است');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    hamoix_maintenance_send_download((string)$_GET['download']);
}

$maintenanceNotice = '';
$maintenanceNoticeType = 'success';
$maintenanceBackupId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_action'])) {
    $maintenanceAction = is_string($_POST['maintenance_action']) ? $_POST['maintenance_action'] : '';
    if ($maintenanceAction === 'create_backup') {
        $backupResult = hamoix_maintenance_create_backup();
        if (($backupResult['ok'] ?? false) === true) {
            $maintenanceBackupId = $backupResult['id'] ?? null;
            $maintenanceNotice = 'نسخهٔ پشتیبان با موفقیت ساخته شد و برای دانلود/restore آماده است.';
        } else {
            $maintenanceNotice = (string)($backupResult['message'] ?? 'ساخت backup ناموفق بود.');
            $maintenanceNoticeType = 'error';
        }
    } elseif ($maintenanceAction === 'update_source') {
        set_time_limit(900);
        $retryDelayInput = $_POST['maintenance_retry_delay'] ?? 5;
        $retryAttemptsInput = $_POST['maintenance_retry_attempts'] ?? 10;
        $retryDelay = is_scalar($retryDelayInput) ? (int)$retryDelayInput : 5;
        $retryAttempts = is_scalar($retryAttemptsInput) ? (int)$retryAttemptsInput : 10;
        $retryDelay = in_array($retryDelay, [5, 10], true) ? $retryDelay : 5;
        $retryAttempts = max(1, min(10, $retryAttempts));
        $updateResult = hamoix_maintenance_update_source($retryAttempts, $retryDelay);
        if (($updateResult['ok'] ?? false) === true) {
            $maintenanceBackupId = $updateResult['backup_id'] ?? null;
            $maintenanceNotice = (string)($updateResult['message'] ?? 'سورس با موفقیت به‌روزرسانی شد.');
            if (($updateResult['fetch_attempts'] ?? 1) > 1) {
                $maintenanceNotice .= ' دریافت GitHub پس از ' . (int)$updateResult['fetch_attempts'] . ' تلاش انجام شد.';
            }
            $maintenanceNoticeType = !empty($updateResult['warning']) ? 'warning' : 'success';
        } else {
            $maintenanceBackupId = $updateResult['backup_id'] ?? null;
            $maintenanceNotice = (string)($updateResult['message'] ?? 'به‌روزرسانی سورس ناموفق بود.');
            $maintenanceNoticeType = 'error';
        }
    } elseif ($maintenanceAction === 'restore_backup') {
        $backupId = isset($_POST['backup_id']) && is_string($_POST['backup_id']) ? $_POST['backup_id'] : '';
        $restoreResult = hamoix_maintenance_restore_backup($backupId);
        if (($restoreResult['ok'] ?? false) === true) {
            $maintenanceBackupId = $restoreResult['safety_backup_id'] ?? null;
            $maintenanceNotice = 'سورس و دیتابیس از backup انتخاب‌شده بازگردانی شد. یک backup ایمنی نیز قبل از restore ساخته شد.';
        } else {
            $maintenanceNotice = (string)($restoreResult['message'] ?? 'بازگردانی backup ناموفق بود.');
            $maintenanceNoticeType = 'error';
        }
    }
}
$maintenanceBackups = hamoix_maintenance_list_backups();


$SETTING_GROUPS = [
    'general' => [
        'title' => 'تنظیمات عمومی پنل',
        'icon'  => 'sliders',
        'fields' => [
            ['type' => 'toggle', 'col' => 'Bot_Status',        'label' => 'فعال بودن فروشگاه',                 'on' => 'botstatuson',         'off' => 'botstatusoff'],
            ['type' => 'toggle', 'col' => 'roll_Status',       'label' => 'قوانین استفاده',                  'on' => 'rolleon',             'off' => 'rolleoff'],
            ['type' => 'toggle', 'col' => 'get_number',        'label' => 'الزام احراز شماره موبایل',         'on' => 'onAuthenticationphone','off' => 'offAuthenticationphone'],
            ['type' => 'toggle', 'col' => 'iran_number',       'label' => 'فقط شماره‌های ایرانی',             'on' => 'onAuthenticationiran','off' => 'offAuthenticationiran'],
            ['type' => 'toggle', 'col' => 'NotUser',           'label' => 'مسدودسازی در صورت نقض قانون',      'on' => 'onnotuser',           'off' => 'offnotuser'],

            ['type' => 'toggle', 'col' => 'verifybucodeuser',  'label' => 'تایید با کد یکبارمصرف برای خرید',  'on' => 'onverify',            'off' => 'offverify'],
            ['type' => 'toggle', 'col' => 'showcard',          'label' => 'نمایش لیست محصولات',               'on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'statuscategory',    'label' => 'دسته‌بندی محصولات',                'on' => 'oncategory',          'off' => 'offcategory'],
            ['type' => 'toggle', 'col' => 'statuscategorygenral','label'=>'دسته‌بندی عمومی',                  'on' => 'oncategorys',         'off' => 'offcategorys'],
            ['type' => 'toggle', 'col' => 'inlinebtnmain',     'label' => 'استفاده از دکمه inline در منو اصلی','on' => 'oninline',          'off' => 'offinline'],
            ['type' => 'toggle', 'col' => 'statusnamecustom',  'label' => 'نام دلخواه برای سرویس',            'on' => 'onnamecustom',        'off' => 'offnamecustom'],
            ['type' => 'toggle', 'col' => 'bulkbuy',           'label' => 'خرید چندتایی (Bulk)',              'on' => 'onbulk',              'off' => 'offbulk'],
        ],
    ],

    'agent' => [
        'title' => 'نمایندگی و زیرمجموعه',
        'icon'  => 'users',
        'fields' => [
            ['type' => 'toggle', 'col' => 'affiliatesstatus',  'label' => 'فعال‌سازی زیرمجموعه‌گیری',        'on' => 'onaffiliates',        'off' => 'offaffiliates'],
            ['type' => 'toggle', 'col' => 'statusagentrequest','label' => 'پذیرش درخواست نمایندگی',           'on' => 'onrequestagent',      'off' => 'offrequestagent'],
            ['type' => 'number', 'col' => 'affiliatespercentage','label'=>'درصد پورسانت زیرمجموعه (٪)',       'placeholder' => '0'],
            ['type' => 'number', 'col' => 'agentreqprice',     'label' => 'حداقل پرداختی ثبت درخواست نمایندگی (تومان)', 'placeholder' => '0'],
            ['type' => 'toggle', 'col' => 'wheelagent',        'label' => 'گردونه شانس برای نماینده',          'on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'Lotteryagent',      'label' => 'قرعه‌کشی برای نماینده',             'on' => '1',                   'off' => '0'],
        ],
    ],

    'reseller' => [
        'title' => 'پنل نمایندگان (فروشندگان)',
        'icon'  => 'user-tag',
        'fields' => [
            ['type' => 'toggle', 'col' => 'reseller_system_status', 'label' => 'فعال‌سازی سیستم نمایندگان', 'on' => '1', 'off' => '0'],
            ['type' => 'toggle', 'col' => 'reseller_signup_status', 'label' => 'اجازه ثبت‌نام خودکار نماینده', 'on' => '1', 'off' => '0'],
            ['type' => 'number', 'col' => 'reseller_min_withdraw',  'label' => 'حداقل مبلغ برداشت نماینده (تومان)', 'placeholder' => '500000'],
        ],
    ],

    'wheel' => [
        'title' => 'گردونه شانس و امتیاز',
        'icon'  => 'gift',
        'fields' => [
            ['type' => 'toggle', 'col' => 'wheelـluck',        'label' => 'فعال‌سازی گردونه شانس',           'on' => '1',                   'off' => '0'],
            ['type' => 'number', 'col' => 'wheelـluck_price',  'label' => 'قیمت هر چرخش (تومان)',             'placeholder' => '0'],
            ['type' => 'toggle', 'col' => 'statusfirstwheel',  'label' => 'یک‌بار گردونه رایگان (کاربر جدید)','on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'scorestatus',       'label' => 'سیستم امتیاز',                     'on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'Dice',              'label' => 'تاس شانس',                         'on' => '1',                   'off' => '0'],
        ],
    ],

    'service' => [
        'title' => 'تنظیمات سرویس',
        'icon'  => 'package',
        'fields' => [
            ['type' => 'number', 'col' => 'limit_usertest_all','label' => 'محدودیت اکانت تست هر کاربر',      'placeholder' => '1'],
            ['type' => 'number', 'col' => 'volumewarn',        'label' => 'هشدار اتمام حجم در (گیگ)',         'placeholder' => '2'],
            ['type' => 'number', 'col' => 'daywarn',           'label' => 'هشدار اتمام زمان در (روز)',        'placeholder' => '2'],
            ['type' => 'number', 'col' => 'removedayc',        'label' => 'حذف سرویس منقضی بعد از (روز)',     'placeholder' => '1'],
            ['type' => 'number', 'col' => 'on_hold_day',       'label' => 'نگه‌داری سرویس قبل از حذف (روز)',  'placeholder' => '4'],
            ['type' => 'number', 'col' => 'cronvolumere',      'label' => 'هر چند ساعت کرون حجم',             'placeholder' => '5'],
            ['type' => 'number', 'col' => 'timeauto_not_verify','label'=>'حذف خودکار سفارش تأییدنشده (دقیقه)','placeholder' => '4'],
            ['type' => 'toggle', 'col' => 'statuslimitchangeloc','label'=>'محدودیت تغییر لوکیشن',             'on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'Debtsettlement',    'label' => 'تسویه بدهی (debt settlement)',     'on' => '1',                   'off' => '0'],
        ],
    ],

    'ux' => [
        'title' => 'تجربه کاربری',
        'icon'  => 'sparkles',
        'fields' => [
            ['type' => 'toggle', 'col' => 'statusnoteforf',   'label' => 'الزام نوشتن یادداشت در سفارش',     'on' => '1',                   'off' => '0'],
            ['type' => 'toggle', 'col' => 'statuscopycart',   'label' => 'دکمه کپی شماره کارت',              'on' => '1',                   'off' => '0'],

        ],
    ],

    'antispam' => [
        'title' => 'ضد اسپم و امنیت',
        'icon'  => 'shield',
        'fields' => [
            ['type' => 'toggle', 'col' => 'antispam_status',     'label' => 'فعال‌سازی ضد اسپم',             'on' => '1',                   'off' => '0'],
            ['type' => 'number', 'col' => 'antispam_msg_count', 'label' => 'حداکثر پیام مجاز در بازه',       'placeholder' => '5'],
            ['type' => 'number', 'col' => 'antispam_seconds',    'label' => 'بازه زمانی (ثانیه)',            'placeholder' => '10'],
            ['type' => 'number', 'col' => 'antispam_mute_seconds','label'=>'مدت سکوت درخواست‌ها (ثانیه)',          'placeholder' => '60'],

        ],
    ],

    'proxy' => [
        'title' => 'پراکسی (برای هاست‌های ایران)',
        'icon'  => 'shield',
        'fields' => [

            ['type' => 'text',   'col' => 'proxy_panel_url',       'label' => 'آدرس پراکسی پنل‌ها', 'placeholder' => 'socks5h://user:pass@1.2.3.4:1080',
             'hint'  => 'قالب: scheme://[user:pass@]host:port — پشتیبانی از http، socks4، socks5 و socks5h. اگر scheme ننویسید http در نظر گرفته می‌شود. برای رد کردن DNS از داخل ایران، socks5h توصیه می‌شود.'],
            ['type' => 'toggle', 'col' => 'proxy_panel_status',    'label' => 'فعال‌سازی پراکسی پنل‌ها', 'on' => '1', 'off' => '0'],
        ],
    ],
];


$settingRow = [];
try {
    $stmt = $pdo->query("SELECT * FROM setting LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if (is_array($row)) $settingRow = $row;
} catch (\Throwable $e) {
    error_log('[panel/settings] load failed: ' . $e->getMessage());
}


$savedCount = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_save'])) {
    foreach ($SETTING_GROUPS as $group) {
        foreach ($group['fields'] as $f) {
            $col = $f['col'];
            $cur = $settingRow[$col] ?? null;
            if ($f['type'] === 'toggle') {
                $new = isset($_POST['f_' . $col]) ? $f['on'] : $f['off'];
                if ((string)$cur !== (string)$new) {
                    try {
                        $sanCol = preg_replace('/[^A-Za-z0-9_ـ]/u', '', $col);
                        $upd = $pdo->prepare("UPDATE setting SET `{$sanCol}` = :v");
                        $upd->bindValue(':v', $new, PDO::PARAM_STR);
                        $upd->execute();
                        $savedCount++;
                    } catch (\Throwable $e) {
                        error_log('[panel/settings] toggle ' . $col . ' failed: ' . $e->getMessage());
                    }
                }
            } else {
                if (array_key_exists('f_' . $col, $_POST)) {
                    $new = (string)$_POST['f_' . $col];


                    if ($f['type'] === 'number') {
                        if ($new !== '' && !is_numeric($new)) {
                            error_log('[panel/settings] non-numeric value for ' . $col . ': ' . $new);
                            continue;
                        }
                        if ($new !== '' && (float)$new < 0) {
                            $new = '0';
                        }

                        if ($col === 'affiliatespercentage' && (float)$new > 100) $new = '100';
                    }

                    if ($f['type'] === 'text' && mb_strlen($new) > 500) {
                        $new = mb_substr($new, 0, 500);
                    }

                    if ((string)$cur !== $new) {
                        try {
                            $sanCol = preg_replace('/[^A-Za-z0-9_ـ]/u', '', $col);
                            $upd = $pdo->prepare("UPDATE setting SET `{$sanCol}` = :v");
                            $upd->bindValue(':v', $new, PDO::PARAM_STR);
                            $upd->execute();
                            $savedCount++;
                        } catch (\Throwable $e) {
                            error_log('[panel/settings] text ' . $col . ' failed: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    header('Location: settings.php?saved=' . $savedCount);
    exit;
}

$showSaved = isset($_GET['saved']);
$savedNum  = isset($_GET['saved']) ? (int)$_GET['saved'] : 0;

function hamoix_is_toggle_on($cur, $on, $off) {
    if ($cur === null) return false;
    if ((string)$cur === (string)$on)  return true;
    if ((string)$cur === (string)$off) return false;
    return in_array(strtolower(trim((string)$cur)), ['1','on','true','yes'], true);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>تنظیمات پنل | Hamoix</title>
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/admin-extra.css">
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
                        <?php echo icon('sliders', 'svg-icon svg-lg'); ?>
                        تنظیمات پنل
                    </div>
                    <div class="page-head__sub">پیکربندی فروشگاه، امنیت و اتصال پنل‌ها</div>
                </div>
            </div>

            <?php if ($showSaved): ?>
                <div class="alert alert-success">
                    <?php echo icon('circle-check', 'svg-icon'); ?>
                    <span>
                        <?php if ($savedNum > 0): ?>
                            تغییرات ذخیره شد. (<?php echo $savedNum; ?> فیلد به‌روزرسانی شد)
                        <?php else: ?>
                            هیچ تغییری انجام نشد.
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($maintenanceNotice !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($maintenanceNoticeType, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo icon($maintenanceNoticeType === 'error' ? 'circle-exclamation' : 'circle-check', 'svg-icon'); ?>
                    <span><?php echo htmlspecialchars($maintenanceNotice, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($maintenanceBackupId !== null): ?>
                        <a href="settings.php?download=<?php echo rawurlencode((string)$maintenanceBackupId); ?>" class="btn btn-outline" style="margin-right:auto;">دانلود backup</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom:22px; border:1px solid rgba(124,92,246,.28);">
                <div class="card__head">
                    <div class="card__title">
                        <?php echo icon('refresh', 'svg-icon svg-md'); ?>
                        <span>نگهداری و بروزرسانی سورس</span>
                    </div>
                </div>
                <p style="margin:0 0 16px; color:var(--text-muted,#9aa4b8); line-height:1.9;">
                    قبل از هر بروزرسانی از فایل‌های سورس، <code>config.php</code> و دیتابیس backup خارج از ریشهٔ وب ساخته می‌شود. در صورت اختلال موقت GitHub، دریافت سورس با فاصلهٔ انتخابی دوباره تلاش می‌شود.
                </p>
                <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    <form method="post" action="settings.php" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;" onsubmit="return confirm('ابتدا backup ساخته می‌شود و سپس آخرین تغییرات branch اصلی Hamoix دریافت خواهد شد. ادامه می‌دهید؟');">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="maintenance_action" value="update_source">
                        <label for="maintenance_retry_delay" style="font-size:12px; color:var(--text-muted,#9aa4b8);">فاصله retry</label>
                        <select id="maintenance_retry_delay" name="maintenance_retry_delay" style="min-width:92px; padding:8px 10px; border-radius:8px;">
                            <option value="5" selected>۵ ثانیه</option>
                            <option value="10">۱۰ ثانیه</option>
                        </select>
                        <label for="maintenance_retry_attempts" style="font-size:12px; color:var(--text-muted,#9aa4b8);">حداکثر تلاش</label>
                        <select id="maintenance_retry_attempts" name="maintenance_retry_attempts" style="min-width:82px; padding:8px 10px; border-radius:8px;">
                            <option value="5">۵ بار</option>
                            <option value="10" selected>۱۰ بار</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <?php echo icon('refresh', 'svg-icon svg-sm'); ?>
                            بروزرسانی از GitHub + backup
                        </button>
                    </form>
                    <form method="post" action="settings.php">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="maintenance_action" value="create_backup">
                        <button type="submit" class="btn btn-outline">
                            <?php echo icon('save', 'svg-icon svg-sm'); ?>
                            ساخت backup جدید
                        </button>
                    </form>
                    <span style="font-size:12px; color:var(--text-muted,#9aa4b8);">فایل‌های backup فقط از همین بخش قابل دریافت هستند.</span>
                </div>

                <div style="margin-top:20px; overflow-x:auto;">
                    <h4 style="margin:0 0 10px;">backupهای موجود و restore</h4>
                    <?php if (empty($maintenanceBackups)): ?>
                        <p style="margin:0; color:var(--text-muted,#9aa4b8);">هنوز backupای ثبت نشده است.</p>
                    <?php else: ?>
                        <table class="table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>تاریخ</th>
                                    <th>حجم</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenanceBackups as $maintenanceBackup):
                                    $backupSize = (int)$maintenanceBackup['size'];
                                    if ($backupSize >= 1048576) {
                                        $backupSizeLabel = number_format($backupSize / 1048576, 2) . ' MB';
                                    } elseif ($backupSize >= 1024) {
                                        $backupSizeLabel = number_format($backupSize / 1024, 1) . ' KB';
                                    } else {
                                        $backupSizeLabel = $backupSize . ' B';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('Y-m-d H:i', (int)$maintenanceBackup['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="direction:ltr; text-align:right;"><?php echo htmlspecialchars($backupSizeLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <a class="btn btn-outline" href="settings.php?download=<?php echo rawurlencode($maintenanceBackup['id']); ?>">دانلود</a>
                                            <form method="post" action="settings.php" onsubmit="return confirm('این backup جایگزین فایل‌های سورس و دیتابیس فعلی می‌شود. قبل از آن یک backup ایمنی خودکار ساخته خواهد شد. ادامه می‌دهید؟');">
                                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="maintenance_action" value="restore_backup">
                                                <input type="hidden" name="backup_id" value="<?php echo htmlspecialchars($maintenanceBackup['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-outline">restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="settings.php" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="_save" value="1">

                <div class="setting-grid">
                    <?php foreach ($SETTING_GROUPS as $gKey => $group): ?>
                        <div class="card">
                            <div class="card__head">
                                <div class="card__title">
                                    <?php echo icon($group['icon'], 'svg-icon svg-md'); ?>
                                    <span><?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </div>
                            <?php foreach ($group['fields'] as $f):
                                $col = $f['col'];
                                $val = $settingRow[$col] ?? null;
                                $idAttr = 'f_' . htmlspecialchars($col, ENT_QUOTES);
                            ?>
                                <div class="setting-row">
                                    <label for="<?php echo $idAttr; ?>" class="setting-row__label">
                                        <?php echo htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($f['hint'])): ?>
                                            <small class="setting-row__hint"><?php echo htmlspecialchars($f['hint'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </label>
                                    <div class="setting-row__control">
                                        <?php if ($f['type'] === 'toggle'): ?>
                                            <label class="switch" title="<?php echo htmlspecialchars($col); ?>">
                                                <input type="checkbox" id="<?php echo $idAttr; ?>" name="<?php echo $idAttr; ?>"
                                                    <?php echo hamoix_is_toggle_on($val, $f['on'], $f['off']) ? 'checked' : ''; ?>>
                                                <span class="switch__slot"></span>
                                            </label>
                                        <?php elseif ($f['type'] === 'number'): ?>
                                            <input type="number" id="<?php echo $idAttr; ?>" name="<?php echo $idAttr; ?>"
                                                value="<?php echo htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="<?php echo htmlspecialchars($f['placeholder'] ?? '', ENT_QUOTES); ?>">
                                        <?php else: ?>
                                            <input type="text" id="<?php echo $idAttr; ?>" name="<?php echo $idAttr; ?>"
                                                value="<?php echo htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="<?php echo htmlspecialchars($f['placeholder'] ?? '', ENT_QUOTES); ?>"
                                                style="direction:ltr; text-align:left;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="save-bar">
                    <button type="reset" class="btn btn-outline">
                        <?php echo icon('rotate-left', 'svg-icon svg-sm'); ?>
                        <span>بازنشانی</span>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <?php echo icon('check', 'svg-icon svg-sm'); ?>
                        <span>ذخیره تغییرات</span>
                    </button>
                </div>
            </form>

        </div>
    </section>
</section>

</body>
</html>


