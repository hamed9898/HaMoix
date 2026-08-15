<?php

/**
 * Shared traffic quota for a single service spread across multiple inbounds.
 *
 * 3x-ui applies a traffic limit to each client record. This table is the
 * authoritative aggregate limit for Hamoix; the cron job sums the panel
 * traffic and disables every matching client when the aggregate is exhausted.
 */

if (!function_exists('hamoix_quota_ensure_table')) {
    function hamoix_quota_ensure_table(PDO $pdo): bool
    {
        static $ready = [];
        $key = spl_object_hash($pdo);
        if (!empty($ready[$key])) {
            return true;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS hamoix_shared_quota (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                panel_name VARCHAR(190) NOT NULL,
                username VARCHAR(190) NOT NULL,
                limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                inbound_ids TEXT NULL,
                used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                source_kind VARCHAR(30) NOT NULL DEFAULT 'panel',
                source_id VARCHAR(190) NULL,
                last_checked_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_hamoix_quota_service (panel_name, username),
                KEY idx_hamoix_quota_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready[$key] = true;
            return true;
        } catch (\Throwable $e) {
            error_log('[hamoix quota] schema: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hamoix_quota_normalize_ids')) {
    function hamoix_quota_normalize_ids($ids): array
    {
        if (function_exists('xui_normalize_inbound_ids')) {
            return xui_normalize_inbound_ids($ids);
        }
        if (is_string($ids)) {
            $decoded = json_decode(trim($ids), true);
            $ids = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', trim($ids), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $out, true)) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }
}

if (!function_exists('hamoix_quota_register')) {
    function hamoix_quota_register(PDO $pdo, string $panelName, string $username, $limitBytes, $inboundIds = [], string $sourceKind = 'panel', $sourceId = '', bool $resetUsage = true): bool
    {
        $limitBytes = max(0, (int) $limitBytes);
        if ($limitBytes <= 0 || trim($panelName) === '' || trim($username) === '') {
            return false;
        }
        if (!hamoix_quota_ensure_table($pdo)) {
            return false;
        }
        $now = time();
        try {
            $duplicateUsage = $resetUsage
                ? 'used_bytes = 0'
                : 'used_bytes = LEAST(used_bytes, VALUES(limit_bytes))';
            $stmt = $pdo->prepare(
                "INSERT INTO hamoix_shared_quota
                    (panel_name, username, limit_bytes, inbound_ids, used_bytes, status, source_kind, source_id, last_checked_at, created_at, updated_at)
                 VALUES (:panel, :username, :limit_bytes, :inbounds, 0, 'active', :source_kind, :source_id, 0, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    limit_bytes = VALUES(limit_bytes), inbound_ids = VALUES(inbound_ids),
                    status = 'active', source_kind = VALUES(source_kind), source_id = VALUES(source_id),
                    {$duplicateUsage}, last_checked_at = 0, updated_at = VALUES(updated_at)"
            );
            $stmt->execute([
                ':panel' => mb_substr($panelName, 0, 190),
                ':username' => mb_substr($username, 0, 190),
                ':limit_bytes' => $limitBytes,
                ':inbounds' => json_encode(hamoix_quota_normalize_ids($inboundIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':source_kind' => mb_substr($sourceKind, 0, 30),
                ':source_id' => mb_substr((string) $sourceId, 0, 190),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[hamoix quota] register: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hamoix_quota_extract_usage')) {
    function hamoix_quota_extract_usage($response): ?int
    {
        if (!is_array($response) || !empty($response['error']) || empty($response['body'])) {
            return null;
        }
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['obj'] ?? null)) {
            return null;
        }
        $obj = $decoded['obj'];
        $hasDirections = array_key_exists('up', $obj) || array_key_exists('down', $obj)
            || array_key_exists('upload', $obj) || array_key_exists('download', $obj);
        $up = (int) ($obj['up'] ?? $obj['upload'] ?? 0);
        $down = (int) ($obj['down'] ?? $obj['download'] ?? 0);
        if ($hasDirections) {
            // `total` in 3x-ui is the configured quota; up+down is usage.
            return max(0, $up + $down);
        }
        return array_key_exists('used', $obj) && is_numeric($obj['used'])
            ? max(0, (int) $obj['used'])
            : null;
    }
}

if (!function_exists('hamoix_quota_enforce_all')) {
    /** Check active aggregate quotas and disable every matching client at limit. */
    function hamoix_quota_enforce_all(PDO $pdo): array
    {
        if (!hamoix_quota_ensure_table($pdo) || !function_exists('get_clinets')) {
            return ['checked' => 0, 'exhausted' => 0, 'errors' => 0];
        }
        $checked = 0;
        $exhausted = 0;
        $errors = 0;
        try {
            $rows = $pdo->query("SELECT * FROM hamoix_shared_quota WHERE status = 'active' AND limit_bytes > 0 ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[hamoix quota] load: ' . $e->getMessage());
            return ['checked' => 0, 'exhausted' => 0, 'errors' => 1];
        }

        foreach ($rows as $row) {
            $checked++;
            $usageResponse = null;
            try {
                $inboundIds = json_decode((string) ($row['inbound_ids'] ?? '[]'), true);
                if (!is_array($inboundIds)) {
                    $inboundIds = [];
                }
                $used = null;
                if (function_exists('xui_get_aggregate_usage')) {
                    $used = xui_get_aggregate_usage(
                        (string) $row['username'],
                        (string) $row['panel_name'],
                        $inboundIds
                    );
                }
                if ($used === null && function_exists('get_clinets')) {
                    $usageResponse = get_clinets((string) $row['username'], (string) $row['panel_name']);
                    $used = hamoix_quota_extract_usage($usageResponse);
                }
                if ($used === null) {
                    $errors++;
                    continue;
                }
                $now = time();
                $upd = $pdo->prepare("UPDATE hamoix_shared_quota SET used_bytes = :used, last_checked_at = :checked, updated_at = :updated WHERE id = :id");
                $upd->execute([':used' => $used, ':checked' => $now, ':updated' => $now, ':id' => (int) $row['id']]);
                if ($used < (int) $row['limit_bytes']) {
                    continue;
                }

                $disabled = false;
                if (function_exists('xui_disable_client')) {
                    $disabled = xui_disable_client((string) $row['panel_name'], (string) $row['username']);
                }
                if ($disabled) {
                    $pdo->prepare("UPDATE hamoix_shared_quota SET status = 'exhausted', updated_at = :updated WHERE id = :id")
                        ->execute([':updated' => $now, ':id' => (int) $row['id']]);
                    $exhausted++;
                } else {
                    $errors++;
                    error_log('[hamoix quota] disable failed for ' . $row['panel_name'] . '/' . $row['username']);
                }
            } catch (\Throwable $e) {
                $errors++;
                error_log('[hamoix quota] check failed: ' . $e->getMessage());
            }
        }
        return ['checked' => $checked, 'exhausted' => $exhausted, 'errors' => $errors];
    }
}
