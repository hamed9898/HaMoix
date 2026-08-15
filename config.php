<?php

/* Baseline web hardening shared by the installer, admin and reseller panels. */
if (!function_exists('hamoix_security_headers')) {
    function hamoix_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (!function_exists('hamoix_csrf_token')) {
    function hamoix_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['hamoix_csrf'])) {
            $_SESSION['hamoix_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['hamoix_csrf'];
    }
}

if (!function_exists('hamoix_csrf_check')) {
    function hamoix_csrf_check(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $incoming = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (is_string($incoming) && $incoming !== '') {
            $validTokens = [hamoix_csrf_token()];
            // A few legacy admin forms use csrf_token instead of the newer
            // hamoix_csrf session key. Both values are server-generated and
            // accepting the legacy value keeps old forms from failing after an
            // update without weakening the same-session check.
            if (!empty($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
                $validTokens[] = $_SESSION['csrf_token'];
            }
            foreach ($validTokens as $validToken) {
                if ($validToken !== '' && hash_equals($validToken, $incoming)) {
                    return;
                }
            }
            http_response_code(403);
            exit('درخواست نامعتبر — توکن CSRF اشتباه است');
        }

        // Protect legacy forms that predate the hidden token. Browsers send
        // Origin/Referer on same-origin form submissions; a cross-site request
        // cannot forge these values.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $sameOrigin = ($origin !== '' && hash_equals($scheme . '://' . $host, rtrim($origin, '/')))
            || ($referer !== '' && str_starts_with($referer, $scheme . '://' . $host . '/'));
        if (!$sameOrigin) {
            http_response_code(403);
            exit('درخواست نامعتبر — توکن CSRF لازم است');
        }
    }
}

if (!function_exists('hamoix_csrf_field')) {
    function hamoix_csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(hamoix_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

hamoix_security_headers();

$dbname     = '';
$usernamedb = '';
$passworddb = '';


$connect = null;
$pdo     = null;
$dsn     = '';
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

if ($dbname !== '' && $usernamedb !== '') {
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    try {
        $connect = @mysqli_connect('localhost', $usernamedb, $passworddb, $dbname);
    } catch (\Throwable $rxMysqliConnectError) {
        $connect = null;
        error_log('config.php mysqli_connect failed: ' . $rxMysqliConnectError->getMessage());
    }
    if ($connect instanceof mysqli) {
        @mysqli_set_charset($connect, 'utf8mb4');
    } else {
        $connect = null;
    }

    $dsn = 'mysql:host=localhost;dbname=' . $dbname . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
    } catch (\PDOException $rxPdoError) {
        $pdo = null;
        error_log('config.php PDO connection failed: ' . $rxPdoError->getMessage());
    }
} else {
    $rxInstallerPending = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'index.php');
    if (!$rxInstallerPending) {
        $rxConfigEmptyMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_config_empty.flag';
        if (!is_file($rxConfigEmptyMarker) || (time() - (int) @filemtime($rxConfigEmptyMarker)) > 3600) {
            error_log('config.php: database credentials are empty — fill $dbname/$usernamedb/$passworddb to enable DB-backed features.');
            @touch($rxConfigEmptyMarker);
        }
        unset($rxConfigEmptyMarker);
    }
    unset($rxInstallerPending);
}

$domainhosts = '';
$domainhosts = rtrim(preg_replace('#^https?://#', '', $domainhosts), '/');


if (!defined('APP_ORIGIN') && $domainhosts !== '') {
    define('APP_ORIGIN', 'https://' . $domainhosts);
}


$GLOBALS['dbname']                     = $dbname;
$GLOBALS['usernamedb']                 = $usernamedb;
$GLOBALS['passworddb']                 = $passworddb;
$GLOBALS['dsn']                        = $dsn;
$GLOBALS['options']                    = $options;
$GLOBALS['pdo']                        = $pdo;
$GLOBALS['connect']                    = $connect;
$GLOBALS['domainhosts'] = $domainhosts;

require_once __DIR__ . '/proxy.php';
