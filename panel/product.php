<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/icons.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../x-ui_single.php';

$hasInboundsColumn = false;
try {
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'inbounds'"
    );
    $columnCheck->execute();
    if (!(bool) $columnCheck->fetchColumn()) {
        try {
            $pdo->exec("ALTER TABLE `product` ADD COLUMN `inbounds` TEXT NULL");
        } catch (\Throwable $e) {
            // Some old database users cannot ALTER tables; continue without the
            // optional inbound override instead of breaking product creation.
        }
    }
    $columnCheck->execute();
    $hasInboundsColumn = (bool) $columnCheck->fetchColumn();
} catch (\Throwable $e) {
    error_log('[panel/product] inbound column check: ' . $e->getMessage());
}

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
$_csrf = $_SESSION['csrf_token'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['removeid']) || isset($_GET['oneproduct'], $_GET['toweproduct'])) {
    $incomingCsrf = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (string) ($_POST['_csrf'] ?? '')
        : (string) ($_GET['_csrf'] ?? '');
    if (!hash_equals((string) $_csrf, $incomingCsrf)) {
        http_response_code(403);
        exit('درخواست نامعتبر — توکن CSRF اشتباه است');
    }
}

$query = $pdo->prepare("SELECT * FROM product ORDER BY id ASC");
$query->execute();
$listinvoice = $query->fetchAll();

$query = $pdo->prepare("SELECT * FROM marzban_panel ORDER BY id ASC");
$query->execute();
$listpanel = $query->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'inbounds') {
    header('Content-Type: application/json; charset=utf-8');
    $panelId = filter_var($_GET['panel_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($panelId === false) {
        echo json_encode(['ok' => false, 'message' => 'پنل نامعتبر است.', 'inbounds' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $panelStmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :id LIMIT 1");
    $panelStmt->execute([':id' => $panelId]);
    $panelRow = $panelStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($panelRow) || !in_array((string) ($panelRow['type'] ?? ''), ['x-ui_single'], true)) {
        echo json_encode(['ok' => false, 'message' => 'برای این نوع پنل، inbound قابل دریافت نیست.', 'inbounds' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $inbounds = xui_get_inbounds($panelRow);
        echo json_encode(['ok' => true, 'message' => '', 'inbounds' => $inbounds], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        error_log('[panel/product] inbound list: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'دریافت لیست inbound از پنل ناموفق بود.', 'inbounds' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


$nameProduct = $_POST['nameproduct'] ?? null;
if (!empty($nameProduct)) {
    $randomString = bin2hex(random_bytes(2));
    $userdata['data_limit_reset'] = "no_reset";

    $product_count = select("product", "*", "name_product", $nameProduct, "count");
    if ($product_count != 0) {
        echo "<script>
alert('محصول از قبل وجود دارد'); window.location.href='product.php';
</script>";
        return;
    }

    $hidepanel       = "{}";
    $priceProduct    = $_POST['price_product']    ?? '';
    $volumeProduct   = $_POST['volume_product']   ?? '';
    $serviceTime     = $_POST['time_product']     ?? '';
    $location        = $_POST['namepanel']        ?? '';
    $agentProduct    = $_POST['agent_product']    ?? '';
    $category        = $_POST['cetegory_product'] ?? '';
    $note            = $_POST['note_product']     ?? '';
    $resellerStatus  = !empty($_POST['reseller_status']) ? '1' : '0';
    $resellerPrice   = preg_replace('/[^0-9]/', '', (string) ($_POST['reseller_price'] ?? ''));
    $dataLimitReset  = $userdata['data_limit_reset'];
    $inboundIds      = xui_normalize_inbound_ids($_POST['inbounds'] ?? []);
    $selectedPanel   = null;
    foreach ($listpanel as $panelRow) {
        if ((string) ($panelRow['name_panel'] ?? '') === (string) $location) {
            $selectedPanel = $panelRow;
            break;
        }
    }
    if ($location === '/all' || !is_array($selectedPanel) || !in_array((string) ($selectedPanel['type'] ?? ''), ['x-ui_single'], true)) {
        $inboundIds = [];
    } elseif ($inboundIds) {
        try {
            $availableIds = array_column(xui_get_inbounds($selectedPanel), 'id');
            $inboundIds = array_values(array_intersect($inboundIds, array_map('intval', $availableIds)));
        } catch (\Throwable $e) {
            error_log('[panel/product] inbound validation: ' . $e->getMessage());
            $inboundIds = [];
        }
    }
    $inboundsJson = json_encode($inboundIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $insertSql = $hasInboundsColumn
        ? "INSERT IGNORE INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status,inbounds,reseller_status,reseller_price) VALUES (:name_product,:code_product,:price_product,:Volume_constraint,:Service_time,:Location,:agent,:data_limit_reset,:note,:category,:hide_panel,'0',:inbounds,:reseller_status,:reseller_price)"
        : "INSERT IGNORE INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status,reseller_status,reseller_price) VALUES (:name_product,:code_product,:price_product,:Volume_constraint,:Service_time,:Location,:agent,:data_limit_reset,:note,:category,:hide_panel,'0',:reseller_status,:reseller_price)";
    $stmt = $pdo->prepare($insertSql);
    $stmt->bindParam(':name_product',     $nameProduct, PDO::PARAM_STR);
    $stmt->bindParam(':code_product',     $randomString);
    $stmt->bindParam(':price_product',    $priceProduct, PDO::PARAM_STR);
    $stmt->bindParam(':Volume_constraint',$volumeProduct, PDO::PARAM_STR);
    $stmt->bindParam(':Service_time',     $serviceTime, PDO::PARAM_STR);
    $stmt->bindParam(':Location',         $location, PDO::PARAM_STR);
    $stmt->bindParam(':agent',            $agentProduct, PDO::PARAM_STR);
    $stmt->bindParam(':data_limit_reset', $dataLimitReset);
    $stmt->bindParam(':category',         $category, PDO::PARAM_STR);
    $stmt->bindParam(':note',             $note, PDO::PARAM_STR);
    $stmt->bindParam(':hide_panel',       $hidepanel);
    if ($hasInboundsColumn) {
        $stmt->bindParam(':inbounds',     $inboundsJson, PDO::PARAM_STR);
    }
    $stmt->bindParam(':reseller_status',   $resellerStatus, PDO::PARAM_STR);
    $stmt->bindParam(':reseller_price',    $resellerPrice, PDO::PARAM_STR);
    $stmt->execute();

    header("Location: product.php");
    exit;
}


if (isset($_GET['oneproduct'], $_GET['toweproduct']) && $_GET['oneproduct'] !== '' && $_GET['toweproduct'] !== '') {
    $firstProductId = filter_var($_GET['oneproduct'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $secondProductId = filter_var($_GET['toweproduct'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($firstProductId === false || $secondProductId === false || $firstProductId === $secondProductId) {
        http_response_code(400);
        exit('شناسه محصولات نامعتبر است');
    }
    update("product", "id", 10000, "id", $firstProductId);
    update("product", "id", $firstProductId,  "id", $secondProductId);
    update("product", "id", $secondProductId, "id", 10000);
    header("Location: product.php");
    exit;
}


if (isset($_GET['removeid']) && $_GET['removeid'] !== '') {
    $productId = filter_var($_GET['removeid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false) {
        http_response_code(400);
        exit('شناسه محصول نامعتبر است');
    }
    $stmt = $pdo->prepare("DELETE FROM product WHERE id = :id");
    $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
    $stmt->execute();
    header("Location: product.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>مدیریت محصولات | ربات Hamoix</title>
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
                        <?php echo icon('grid', 'svg-icon svg-lg'); ?>
                        لیست محصولات
                    </div>
                    <div class="page-head__sub">مدیریت محصولات و پنل‌های مرزبان</div>
                </div>
                <div class="chip-row">
                    <button onclick="openModal('modal-add-product')" class="btn btn-primary btn-sm">
                        <?php echo icon('plus', 'svg-icon'); ?> افزودن محصول
                    </button>
                    <button onclick="openModal('modal-move-product')" class="btn btn-soft-purple btn-sm">
                        <?php echo icon('arrow-right-arrow-left', 'svg-icon'); ?> جابجایی ردیف
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table id="productsTable" class="display app-table" style="width:100%" data-mdt-filter="5,6,7">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>نام محصول</th>
                                <th>قیمت</th>
                                <th>حجم (GB)</th>
                                <th>زمان (روز)</th>
                                <th>لوکیشن</th>
                                <th>گروه کاربری</th>
                                <th>دسته‌بندی</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($listinvoice as $list):
                            $category = ($list['category'] == null) ? 'ندارد' : htmlspecialchars($list['category'], ENT_QUOTES, 'UTF-8');
                            $agent_type = 'عادی'; $agent_badge = 'badge-gray';
                            if ($list['agent'] == 'n')  { $agent_type = 'نماینده';      $agent_badge = 'badge-purple';  }
                            if ($list['agent'] == 'n2') { $agent_type = 'نماینده ویژه';  $agent_badge = 'badge-warning'; }
                        ?>
                            <tr>
                                <td data-label="شناسه"><?php echo $list['id']; ?></td>
                                <td data-label="نام محصول"><?php echo htmlspecialchars($list['name_product'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="قیمت"><span class="badge badge-success"><?php echo number_format($list['price_product']); ?></span></td>
                                <td data-label="حجم (GB)"><span class="badge badge-info"><?php echo (int)$list['Volume_constraint']; ?></span></td>
                                <td data-label="زمان (روز)"><span class="badge badge-warning"><?php echo (int)$list['Service_time']; ?></span></td>
                                <td data-label="لوکیشن"><span class="badge badge-cyan"><?php echo htmlspecialchars($list['Location'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="گروه کاربری"><span class="badge <?php echo $agent_badge; ?>"><?php echo $agent_type; ?></span></td>
                                <td data-label="دسته‌بندی"><?php echo $category; ?></td>
                                <td data-label="عملیات" class="cell-actions">
                                    <div style="display:inline-flex; gap:6px;">
                                        <a href="productedit.php?id=<?php echo $list['id']; ?>" class="btn btn-sm btn-soft-info" title="ویرایش">
                                            <?php echo icon('pen', 'svg-icon'); ?>
                                        </a>
                                        <a href="product.php?removeid=<?php echo (int) $list['id']; ?>&_csrf=<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-soft-danger" title="حذف"
                                           onclick="return confirm('آیا از حذف این محصول مطمئن هستید؟')">
                                            <?php echo icon('trash', 'svg-icon'); ?>
                                        </a>
                                    </div>
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


<div id="modal-add-product" class="modal-overlay">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-head">
            <span class="modal-head__title">افزودن محصول جدید</span>
            <button class="modal-close" onclick="closeModal('modal-add-product')">&times;</button>
        </div>
        <form action="product.php" method="POST">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label class="form-label">نام محصول</label>
                <input type="text" name="nameproduct" class="form-control" placeholder="نام محصول را وارد کنید" required>
            </div>

            <div class="form-group">
                <label class="form-label">پنل (موقعیت)</label>
                <select name="namepanel" id="productPanelSelect" class="form-control" required>
                    <option value="/all" data-panel-id="0" data-panel-type="all">تمامی پنل‌ها</option>
                    <?php foreach ($listpanel as $panel): ?>
                        <option value="<?php echo htmlspecialchars($panel['name_panel'], ENT_QUOTES, 'UTF-8'); ?>" data-panel-id="<?php echo (int) ($panel['id'] ?? 0); ?>" data-panel-type="<?php echo htmlspecialchars((string) ($panel['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($panel['name_panel'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="productInboundsGroup" style="display:none;">
                <label class="form-label">اینـباندهای محصول (چند انتخابی)</label>
                <div id="productInboundsStatus" class="text-muted" style="margin-bottom:8px;">برای دریافت inboundها پنل را انتخاب کنید.</div>
                <div id="productInboundsList" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:8px;"></div>
                <small class="text-muted">سهمیهٔ محصول بین همهٔ inboundهای انتخابی مشترک است؛ برای محصول «تمامی پنل‌ها» انتخاب inbound ذخیره نمی‌شود.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">قیمت (تومان)</label>
                    <input type="number" name="price_product" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">حجم (GB)</label>
                    <input type="number" name="volume_product" class="form-control" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">زمان (روز)</label>
                    <input type="number" name="time_product" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">نوع کاربر</label>
                    <select name="agent_product" class="form-control" required>
                        <option value="f">کاربر عادی</option>
                        <option value="n">نماینده</option>
                        <option value="n2">نماینده پیشرفته</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">دسته‌بندی</label>
                <input type="text" name="cetegory_product" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">توضیحات</label>
                <input type="text" name="note_product" class="form-control" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="reseller_status" value="1"> قابل فروش توسط نمایندگان
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">قیمت نماینده (خالی = قیمت عادی)</label>
                    <input type="number" name="reseller_price" class="form-control" placeholder="اختیاری">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">افزودن محصول</button>
        </form>
    </div>
</div>


<div id="modal-move-product" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-head__title">جابجایی ردیف محصولات</span>
            <button class="modal-close" onclick="closeModal('modal-move-product')">&times;</button>
        </div>
        <form action="product.php" method="GET">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label class="form-label">شناسه محصول اول</label>
                <input type="number" name="oneproduct" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">شناسه محصول دوم</label>
                <input type="number" name="toweproduct" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-soft-purple btn-block">جابجایی</button>
        </form>
    </div>
</div>
<script src="js/datatable.js" defer>

</script>
<script>
(function () {
    var panel = document.getElementById('productPanelSelect');
    var group = document.getElementById('productInboundsGroup');
    var list = document.getElementById('productInboundsList');
    var status = document.getElementById('productInboundsStatus');
    if (panel && group && list && status) {
        function loadInbounds() {
            var option = panel.options[panel.selectedIndex];
            var type = option ? (option.getAttribute('data-panel-type') || '') : '';
            var id = option ? (option.getAttribute('data-panel-id') || '0') : '0';
            var supported = type === 'x-ui_single';
            group.style.display = supported ? '' : 'none';
            list.innerHTML = '';
            if (!supported || id === '0') return;
            status.textContent = 'در حال دریافت inboundهای پنل…';
            fetch('product.php?ajax=inbounds&panel_id=' + encodeURIComponent(id), {credentials:'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    list.innerHTML = '';
                    if (!j.ok || !Array.isArray(j.inbounds) || !j.inbounds.length) {
                        status.textContent = j.message || 'inboundی از پنل دریافت نشد.';
                        return;
                    }
                    status.textContent = j.inbounds.length + ' inbound دریافت شد.';
                    j.inbounds.forEach(function (item) {
                        var label = document.createElement('label');
                        label.className = 'form-label';
                        label.style.cssText = 'display:flex;align-items:center;gap:8px;padding:9px;border:1px solid var(--border-soft);border-radius:8px;';
                        var input = document.createElement('input');
                        input.type = 'checkbox'; input.name = 'inbounds[]'; input.value = String(item.id);
                        var text = document.createTextNode('#' + item.id + ' — ' + (item.remark || 'بدون نام') + (item.protocol ? ' (' + item.protocol + ')' : '') + (item.port ? ' :' + item.port : ''));
                        label.appendChild(input); label.appendChild(text); list.appendChild(label);
                    });
                })
                .catch(function () { status.textContent = 'خطا در دریافت inboundها؛ اتصال پنل و API token را بررسی کنید.'; });
        }
        panel.addEventListener('change', loadInbounds);
        loadInbounds();
    }
    document.addEventListener("DOMContentLoaded", function () {
        HamoixDT.init("#productsTable");
    });
})();
</script>
</body>
</html>


