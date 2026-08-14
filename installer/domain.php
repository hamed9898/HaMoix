<?php

if (!function_exists('normalizeDomainAddressWithPort')) {
    function normalizeDomainAddressWithPort($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parsedUrl = parse_url($url);
        if (empty($parsedUrl['host'])) {
            return null;
        }

        $address = $parsedUrl['host'];
        if (!empty($parsedUrl['port'])) {
            $address .= ':' . (int) $parsedUrl['port'];
        }

        $path = $parsedUrl['path'] ?? '';
        $path = preg_replace('#/index\\.php$#i', '', $path);
        $path = preg_replace('#/installer/?$#', '', $path);
        $path = rtrim($path, '/');
        $path = ltrim($path, '/');
        if ($path !== '') {
            $address .= '/' . $path;
        }

        return ['address' => $address];
    }
}

if (!function_exists('runTableMigrationsLocally')) {
    function runTableMigrationsLocally($tablesFile, $rootDirectory) {
        $previousDirectory = getcwd();
        if (!@chdir($rootDirectory)) {
            return ['ok' => false];
        }

        ob_start();
        try {
            require $tablesFile;
            return ['ok' => true];
        } catch (\Throwable $e) {
            error_log('[installer] local table migration failed: ' . $e->getMessage());
            return ['ok' => false];
        } finally {
            ob_end_clean();
            if ($previousDirectory !== false) {
                @chdir($previousDirectory);
            }
        }
    }
}
