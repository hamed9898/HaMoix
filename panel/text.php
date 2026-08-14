<?php
session_start();
require_once __DIR__ . '/../config.php';

$query = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
$query->bindParam("username", $_SESSION["user"], PDO::PARAM_STR);
$query->execute();
$result = $query->fetch(PDO::FETCH_ASSOC);

if(!isset($_SESSION["user"]) || !$result){
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrf = $_SESSION['csrf_token'];
$textPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'text.json';
$textUrl = '../text.json';

if(isset($_GET['action']) && $_GET['action'] === 'save'){
    if (!hash_equals((string) $_csrf, (string) ($_GET['_csrf'] ?? ''))) {
        http_response_code(403);
        exit('درخواست نامعتبر — توکن CSRF اشتباه است');
    }
    update("x_ui", "setting", (string) ($_POST['settings'] ?? ''), "codepanel", (string) ($_POST['namepanel'] ?? ''));
    header('Location: seeting_x_ui.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incomingCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($incomingCsrf) || !hash_equals((string) $_csrf, $incomingCsrf)) {
        http_response_code(403);
        exit('درخواست نامعتبر — توکن CSRF اشتباه است');
    }
    $jsonData = file_get_contents('php://input', false, null, 0, 1048576);
    $dataArray = json_decode($jsonData, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($dataArray)) {

        file_put_contents($textPath, json_encode($dataArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        echo 'Data saved successfully';
        exit;
    } else {
        echo 'Invalid JSON data';
        exit;
    }
}
$textbot = is_file($textPath) ? file_get_contents($textPath) : '{}';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-color="blue" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ویرایش متن — پنل Hamoix</title>
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="js/theme.js" defer>

</script>
    <style>
      .text-editor-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
      }
      .text-editor-row {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 14px;
        align-items: center;
        padding: 12px 14px;
        background: var(--surface-2);
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        transition: border-color .15s ease;
      }
      .text-editor-row:hover { border-color: var(--border-mid); }
      .text-editor-row label {
        font-family: 'Arad', system-ui, monospace;
        font-size: 12px;
        color: var(--text-muted);
        word-break: break-all;
        padding: 0;
        margin: 0;
      }
      .text-editor-row label::before {
        content: "$ ";
        color: var(--accent);
        font-weight: 700;
      }
      .text-editor-row input[type="text"] {
        width: 100%;
        padding: 9px 12px;
        background: var(--surface-1);
        border: 1px solid var(--border-mid);
        border-radius: 8px;
        color: var(--text-main);
        font-family: 'Arad', system-ui, sans-serif;
        font-size: 13px;
        transition: border-color .15s ease;
        direction: rtl;
      }
      .text-editor-row input[type="text"]:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-glow);
      }
      .text-editor-actions {
        position: sticky;
        bottom: 0;
        margin-top: 18px;
        padding-top: 14px;
        background: linear-gradient(to top, var(--bg-body) 60%, transparent);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
      }
      @media (max-width: 720px) {
        .text-editor-row { grid-template-columns: 1fr; gap: 6px; }
      }
      .text-loading {
        text-align: center;
        padding: 40px 20px;
        color: var(--text-muted);
        font-size: 14px;
      }
      .text-loading i { color: var(--accent); margin-left: 8px; }
    </style>
</head>
<body>

<section id="container">
    <?php include("header.php"); ?>
    <section id="main-content">
        <section class="wrapper">
            <div class="page-head">
                <div>
                    <div class="page-head__title">
                        <span class="symbol">~</span>
                        ویرایش متن ربات
                    </div>
                    <div class="page-head__sub">ویرایش پیام‌ها و متن‌های ربات از فایل text.json</div>
                </div>
            </div>

            <div class="card">
                <div class="card__head">
                    <div class="card__title"><span class="symbol">$</span> فایل text.json</div>
                    <span class="chip">JSON Editor</span>
                </div>
                <form id="jsonForm" class="text-editor-grid"></form>
                <div class="text-editor-actions">
                    <button type="button" class="btn btn-primary" onclick="saveChanges()">
                        <i class="fa-solid fa-floppy-disk"></i> ذخیره تغییرات
                    </button>
                </div>
            </div>
        </section>
    </section>
</section>

<script>
    function createForm(data, parentKey = '') {
        const form = document.getElementById('jsonForm');
        Object.keys(data).forEach(key => {
            const fullKey = parentKey ? `${parentKey}.${key}` : key;
            if (typeof data[key] === 'object' && data[key] !== null) {
                createForm(data[key], fullKey);
            } else {
                const row = document.createElement('div');
                row.className = 'text-editor-row';
                const label = document.createElement('label');
                label.innerText = fullKey;
                const input = document.createElement('input');
                input.type = 'text';
                input.value = data[key];
                input.name = fullKey;
                row.appendChild(label);
                row.appendChild(input);
                form.appendChild(row);
            }
        });
    }


    document.getElementById('jsonForm').innerHTML = '<div class="text-loading"><i class="fa-solid fa-spinner fa-spin"></i> در حال بارگذاری متن‌ها...</div>';


    fetch('<?php echo htmlspecialchars($textUrl, ENT_QUOTES, 'UTF-8'); ?>')
        .then(response => response.json())
        .then(data => {
            document.getElementById('jsonForm').innerHTML = '';
            createForm(data);
        })
        .catch(error => {
            console.error('Error loading JSON:', error);
            document.getElementById('jsonForm').innerHTML = '<div class="text-loading" style="color: var(--danger);"><i class="fa-solid fa-circle-exclamation"></i> خطا در بارگذاری فایل JSON</div>';
        });


    function saveChanges() {
        const form = document.getElementById('jsonForm');
        const inputs = form.querySelectorAll('input[type="text"]');
        const updatedJson = {};
        inputs.forEach(input => {
            const keys = input.name.split('.');
            let temp = updatedJson;
            while (keys.length > 1) {
                const k = keys.shift();
                if (!temp[k]) temp[k] = {};
                temp = temp[k];
            }
            temp[keys[0]] = input.value;
        });


        fetch('text.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8'); ?>'
            },
            body: JSON.stringify(updatedJson)
        })
        .then(response => {
            if (response.ok) {
                alert('تغییرات با موفقیت ذخیره شد!');
            } else {
                alert('خطا در ذخیره سازی داده‌ها');
            }
        })
        .catch(error => {
            console.error('Error saving data:', error);
            alert('خطا در ذخیره سازی داده‌ها');
        });
    }
</script>

</body>
</html>


