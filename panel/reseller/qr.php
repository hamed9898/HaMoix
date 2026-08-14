<?php

/**
 * Renders a PNG QR code for a reseller service subscription link.
 * Public endpoint (looked up by the unguessable sub_token).
 */

require_once __DIR__ . '/lib.php';
require __DIR__ . '/../../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
$pdo = $GLOBALS['pdo'] ?? null;

$sub = '';
if ($token !== '' && $pdo instanceof PDO) {
    $stmt = $pdo->prepare("SELECT sub_link FROM reseller_service WHERE sub_token = :t LIMIT 1");
    $stmt->execute([':t' => $token]);
    $sub = (string) $stmt->fetchColumn();
}

if ($sub === '') {
    http_response_code(404);
    exit('not found');
}

try {
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $sub,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 420,
        margin: 2,
    );
    $result = $builder->build();
    header('Content-Type: ' . $result->getMimeType());
    header('Cache-Control: private, max-age=300');
    echo $result->getString();
} catch (\Throwable $e) {
    error_log('[reseller qr] ' . $e->getMessage());
    http_response_code(500);
    exit('qr error');
}
