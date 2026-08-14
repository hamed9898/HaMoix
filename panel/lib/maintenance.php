<?php

/*
 * Maintenance helpers used by panel/settings.php.
 * Backups live next to the application, outside the public document root.
 * All shell arguments are fixed by the application and escaped before use.
 */

if (!function_exists('hamoix_maintenance_project_root')) {
    function hamoix_maintenance_project_root(): string
    {
        return dirname(__DIR__, 2);
    }
}

if (!function_exists('hamoix_maintenance_backup_dir')) {
    function hamoix_maintenance_backup_dir(): string
    {
        $preferred = '/var/lib/hamoix/backups';
        if ((is_dir($preferred) && is_writable($preferred))
            || (!is_dir($preferred) && is_writable(dirname($preferred)))) {
            return $preferred;
        }
        // Existing installations may not have received the external backup
        // directory yet. Nginx blocks /storage/ and the fallback also writes
        // an Apache deny rule when it is first created.
        return hamoix_maintenance_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
    }
}

if (!function_exists('hamoix_maintenance_exec')) {
    function hamoix_maintenance_exec(string $command, ?string $cwd = null): array
    {
        if (!function_exists('proc_open')) {
            return ['code' => 127, 'output' => 'proc_open is disabled'];
        }

        $pipes = [];
        $process = @proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        if (!is_resource($process)) {
            return ['code' => 127, 'output' => 'Could not start maintenance command'];
        }

        $output = isset($pipes[1]) ? (string) @stream_get_contents($pipes[1]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            @fclose($pipes[1]);
        }
        $code = @proc_close($process);

        return ['code' => is_int($code) ? $code : 1, 'output' => $output];
    }
}

if (!function_exists('hamoix_maintenance_log_command_error')) {
    function hamoix_maintenance_log_command_error(string $context, array $result): void
    {
        $output = trim((string) ($result['output'] ?? ''));
        if (strlen($output) > 2000) {
            $output = substr($output, -2000);
        }
        error_log('[hamoix/maintenance] ' . $context . ' failed (' . (int) ($result['code'] ?? 1) . '): ' . $output);
    }
}

if (!function_exists('hamoix_maintenance_remove_tree')) {
    function hamoix_maintenance_remove_tree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($itemPath);
                } else {
                    @unlink($itemPath);
                }
            }
        } catch (Throwable $e) {
            error_log('[hamoix/maintenance] temporary cleanup failed: ' . $e->getMessage());
        }
        @rmdir($path);
    }
}

if (!function_exists('hamoix_maintenance_make_temp_dir')) {
    function hamoix_maintenance_make_temp_dir(): ?string
    {
        try {
            $suffix = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hamoix-maint-' . $suffix;
        return @mkdir($path, 0700, true) ? $path : null;
    }
}

if (!function_exists('hamoix_maintenance_write_db_defaults')) {
    function hamoix_maintenance_write_db_defaults(string $path, string $database, string $username, string $password): bool
    {
        if ($database === '' || $username === '') {
            return false;
        }
        $contents = "[client]\n"
            . "host=localhost\n"
            . "user=" . str_replace(["\\", "\n", "\r"], ['', '', ''], $username) . "\n"
            . "password=" . str_replace(["\\", "\n", "\r"], ['', '', ''], $password) . "\n";
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($path, 0600);
        return true;
    }
}

if (!function_exists('hamoix_maintenance_database_values')) {
    function hamoix_maintenance_database_values(): array
    {
        return [
            'database' => trim((string) ($GLOBALS['dbname'] ?? '')),
            'username' => trim((string) ($GLOBALS['usernamedb'] ?? '')),
            'password' => (string) ($GLOBALS['passworddb'] ?? ''),
        ];
    }
}

if (!function_exists('hamoix_maintenance_create_backup')) {
    function hamoix_maintenance_create_backup(): array
    {
        $root = realpath(hamoix_maintenance_project_root());
        if ($root === false || !is_dir($root) || !is_file($root . DIRECTORY_SEPARATOR . 'config.php')) {
            return ['ok' => false, 'message' => 'ریشهٔ سورس Hamoix پیدا نشد.'];
        }

        $db = hamoix_maintenance_database_values();
        if ($db['database'] === '' || $db['username'] === '') {
            return ['ok' => false, 'message' => 'تنظیمات اتصال دیتابیس کامل نیست.'];
        }

        $backupDir = hamoix_maintenance_backup_dir();
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
            $fallback = hamoix_maintenance_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
            if ($backupDir !== $fallback && (!is_dir($fallback) && !@mkdir($fallback, 0700, true))) {
                return ['ok' => false, 'message' => 'ساخت پوشهٔ backup امن ممکن نشد.'];
            }
            $backupDir = $fallback;
        }
        @chmod($backupDir, 0700);
        $storageRoot = hamoix_maintenance_project_root() . DIRECTORY_SEPARATOR . 'storage';
        if (str_starts_with($backupDir, $storageRoot . DIRECTORY_SEPARATOR)) {
            $denyFile = $storageRoot . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($denyFile)) {
                @file_put_contents($denyFile, "Require all denied\nDeny from all\n", LOCK_EX);
                @chmod($denyFile, 0644);
            }
        }
        $backupDirReal = realpath($backupDir);
        if ($backupDirReal === false) {
            return ['ok' => false, 'message' => 'مسیر امن backup قابل دسترسی نیست.'];
        }

        $stage = hamoix_maintenance_make_temp_dir();
        if ($stage === null) {
            return ['ok' => false, 'message' => 'ساخت فضای موقت backup ممکن نشد.'];
        }
        $metaDir = $stage . DIRECTORY_SEPARATOR . '__hamoix_meta__';
        @mkdir($metaDir, 0700, true);
        $defaults = $stage . DIRECTORY_SEPARATOR . '.db.cnf';
        $archive = null;
        $backupCreated = false;

        try {
            if (!hamoix_maintenance_write_db_defaults($defaults, $db['database'], $db['username'], $db['password'])) {
                return ['ok' => false, 'message' => 'ساخت فایل موقت اتصال دیتابیس ممکن نشد.'];
            }

            $dumpBin = is_executable('/usr/bin/mysqldump')
                ? '/usr/bin/mysqldump'
                : (is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump');
            $dumpCommand = escapeshellarg($dumpBin)
                . ' --defaults-extra-file=' . escapeshellarg($defaults)
                . ' --single-transaction --routines --triggers --events --hex-blob '
                . escapeshellarg($db['database'])
                . ' > ' . escapeshellarg($metaDir . DIRECTORY_SEPARATOR . 'database.sql');
            $dumpResult = hamoix_maintenance_exec($dumpCommand, $root);
            if (($dumpResult['code'] ?? 1) !== 0 || !is_file($metaDir . DIRECTORY_SEPARATOR . 'database.sql')
                || (int) @filesize($metaDir . DIRECTORY_SEPARATOR . 'database.sql') < 1) {
                hamoix_maintenance_log_command_error('database backup', $dumpResult);
                return ['ok' => false, 'message' => 'تهیهٔ backup دیتابیس ناموفق بود.'];
            }

            $version = trim((string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'version'));
            $manifest = [
                'format' => 1,
                'created_at' => gmdate('c'),
                'application' => 'Hamoix',
                'version' => $version,
                'database' => $db['database'],
            ];
            if (@file_put_contents($metaDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
                return ['ok' => false, 'message' => 'ثبت مشخصات backup ممکن نشد.'];
            }

            try {
                $random = bin2hex(random_bytes(4));
            } catch (Throwable $e) {
                $random = substr(md5(uniqid('', true)), 0, 8);
            }
            $filename = 'hamoix-' . gmdate('Ymd-His') . '-' . $random . '.tar.gz';
            $archive = $backupDirReal . DIRECTORY_SEPARATOR . $filename;
            $tarBin = is_executable('/bin/tar') ? '/bin/tar' : 'tar';
            $tarCommand = escapeshellarg($tarBin)
                . ' --create --gzip --file=' . escapeshellarg($archive)
                . ' --exclude=.git --exclude=vendor --exclude=logs --exclude=storage/cache --exclude=storage/backups '
                . ' --directory=' . escapeshellarg($root) . ' . '
                . ' --directory=' . escapeshellarg($stage) . ' __hamoix_meta__';
            $tarResult = hamoix_maintenance_exec($tarCommand, $root);
            if (($tarResult['code'] ?? 1) !== 0 || !is_file($archive) || (int) @filesize($archive) < 1) {
                hamoix_maintenance_log_command_error('source backup', $tarResult);
                return ['ok' => false, 'message' => 'ساخت فایل backup ناموفق بود.'];
            }

            @chmod($archive, 0600);
            $backupCreated = true;
            return [
                'ok' => true,
                'id' => substr($filename, 0, -7),
                'filename' => $filename,
                'path' => $archive,
            ];
        } finally {
            @unlink($defaults);
            hamoix_maintenance_remove_tree($stage);
            if ($archive !== null && !$backupCreated) {
                @unlink($archive);
            }
        }
    }
}

if (!function_exists('hamoix_maintenance_backup_path')) {
    function hamoix_maintenance_backup_path(string $id): ?string
    {
        if (!preg_match('/^hamoix-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id)) {
            return null;
        }
        $directory = realpath(hamoix_maintenance_backup_dir());
        if ($directory === false) {
            return null;
        }
        $candidate = realpath($directory . DIRECTORY_SEPARATOR . $id . '.tar.gz');
        if ($candidate === false || dirname($candidate) !== $directory || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }
}

if (!function_exists('hamoix_maintenance_list_backups')) {
    function hamoix_maintenance_list_backups(): array
    {
        $directory = realpath(hamoix_maintenance_backup_dir());
        if ($directory === false || !is_dir($directory)) {
            return [];
        }
        $items = [];
        foreach ((array) @glob($directory . DIRECTORY_SEPARATOR . 'hamoix-*.tar.gz') as $path) {
            $filename = basename($path);
            $id = substr($filename, 0, -7);
            if (hamoix_maintenance_backup_path($id) !== $path) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'filename' => $filename,
                'size' => (int) @filesize($path),
                'created_at' => (int) @filemtime($path),
            ];
        }
        usort($items, static function (array $left, array $right): int {
            return $right['created_at'] <=> $left['created_at'];
        });
        return $items;
    }
}

if (!function_exists('hamoix_maintenance_send_download')) {
    function hamoix_maintenance_send_download(string $id): void
    {
        $path = hamoix_maintenance_backup_path($id);
        if ($path === null) {
            http_response_code(404);
            exit('فایل backup پیدا نشد.');
        }
        if (headers_sent()) {
            http_response_code(500);
            exit('ارسال فایل ممکن نیست.');
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) @filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
}

if (!function_exists('hamoix_maintenance_validate_archive')) {
    function hamoix_maintenance_validate_archive(string $archive, string $tarBin): bool
    {
        $result = hamoix_maintenance_exec(
            escapeshellarg($tarBin) . ' --list --gzip --file=' . escapeshellarg($archive)
        );
        if (($result['code'] ?? 1) !== 0) {
            return false;
        }
        foreach (preg_split('/\r?\n/', (string) ($result['output'] ?? '')) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, 'tar:')) {
                continue;
            }
            if (str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('hamoix_maintenance_restore_backup')) {
    function hamoix_maintenance_restore_backup(string $id): array
    {
        $archive = hamoix_maintenance_backup_path($id);
        if ($archive === null) {
            return ['ok' => false, 'message' => 'backup انتخاب‌شده معتبر نیست.'];
        }

        $tarBin = is_executable('/bin/tar') ? '/bin/tar' : 'tar';
        if (!hamoix_maintenance_validate_archive($archive, $tarBin)) {
            return ['ok' => false, 'message' => 'ساختار فایل backup معتبر نیست.'];
        }

        $preRestore = hamoix_maintenance_create_backup();
        if (!($preRestore['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'قبل از restore، backup ایمنی ساخته نشد؛ restore لغو شد.'];
        }

        $stage = hamoix_maintenance_make_temp_dir();
        if ($stage === null) {
            return ['ok' => false, 'message' => 'ساخت فضای موقت restore ممکن نشد.'];
        }
        $root = realpath(hamoix_maintenance_project_root());
        $db = hamoix_maintenance_database_values();
        $defaults = $stage . DIRECTORY_SEPARATOR . '.db.cnf';
        $sql = $stage . DIRECTORY_SEPARATOR . '__hamoix_meta__' . DIRECTORY_SEPARATOR . 'database.sql';

        try {
            $extractResult = hamoix_maintenance_exec(
                escapeshellarg($tarBin) . ' --extract --gzip --file=' . escapeshellarg($archive)
                . ' --directory=' . escapeshellarg($stage)
                . ' --no-same-owner --no-same-permissions'
            );
            if (($extractResult['code'] ?? 1) !== 0 || !is_file($sql)) {
                hamoix_maintenance_log_command_error('backup extraction', $extractResult);
                return ['ok' => false, 'message' => 'باز کردن backup ناموفق بود.'];
            }

            if (!hamoix_maintenance_write_db_defaults($defaults, $db['database'], $db['username'], $db['password'])) {
                return ['ok' => false, 'message' => 'تنظیم اتصال دیتابیس برای restore ممکن نشد.'];
            }
            $mysqlBin = is_executable('/usr/bin/mysql')
                ? '/usr/bin/mysql'
                : (is_executable('/usr/bin/mariadb') ? '/usr/bin/mariadb' : 'mysql');
            $restoreDb = hamoix_maintenance_exec(
                escapeshellarg($mysqlBin)
                . ' --defaults-extra-file=' . escapeshellarg($defaults)
                . ' --database=' . escapeshellarg($db['database'])
                . ' < ' . escapeshellarg($sql)
            );
            if (($restoreDb['code'] ?? 1) !== 0) {
                hamoix_maintenance_log_command_error('database restore', $restoreDb);
                return ['ok' => false, 'message' => 'بازگردانی دیتابیس ناموفق بود؛ فایل‌های سورس تغییر نکردند.'];
            }

            $restoreSource = hamoix_maintenance_exec(
                escapeshellarg($tarBin) . ' --extract --gzip --file=' . escapeshellarg($archive)
                . ' --directory=' . escapeshellarg($root)
                . ' --exclude=__hamoix_meta__ --no-same-owner --no-same-permissions'
            );
            if (($restoreSource['code'] ?? 1) !== 0) {
                hamoix_maintenance_log_command_error('source restore', $restoreSource);
                return ['ok' => false, 'message' => 'دیتابیس restore شد اما بازگردانی فایل‌های سورس کامل نشد.'];
            }

            return ['ok' => true, 'safety_backup_id' => $preRestore['id'] ?? null];
        } finally {
            @unlink($defaults);
            hamoix_maintenance_remove_tree($stage);
        }
    }
}

if (!function_exists('hamoix_maintenance_is_runtime_path')) {
    function hamoix_maintenance_is_runtime_path(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), './');

        // These paths are generated by the running installation and must not
        // block a source update. Keep this list explicit so an actual source
        // change is never hidden accidentally.
        $runtimePaths = [
            'vendor',
            'logs',
            'storage/cache',
            'storage/backups',
            'panel/panel-auth.log',
            'cron/cron.lock',
            'cron/use_loopback.flag',
            'composer.lock',
            'log.txt',
            'error_log',
            'error.log',
            'ss',
            'domains.json',
            'updatedenide.json',
        ];
        foreach ($runtimePaths as $runtimePath) {
            if ($path === $runtimePath || str_starts_with($path, $runtimePath . '/')) {
                return true;
            }
        }

        // Future panel diagnostics use the same protected log convention.
        return (bool) preg_match('#^panel/[^/]+\\.log$#i', $path);
    }
}

if (!function_exists('hamoix_maintenance_update_source')) {
    function hamoix_maintenance_update_source(): array
    {
        $root = realpath(hamoix_maintenance_project_root());
        if ($root === false || !is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
            return ['ok' => false, 'message' => 'این نصب به repository گیت متصل نیست.'];
        }

        $git = 'git -c ' . escapeshellarg('safe.directory=' . $root) . ' -C ' . escapeshellarg($root);
        $remote = hamoix_maintenance_exec(
            $git . ' config --get remote.origin.url'
        );
        $remoteUrl = trim((string) ($remote['output'] ?? ''));
        if (($remote['code'] ?? 1) !== 0 || !preg_match('#^(https://github\.com/hamed9898/Hamoix(?:\.git)?|git@github\.com:hamed9898/Hamoix(?:\.git)?)$#i', $remoteUrl)) {
            return ['ok' => false, 'message' => 'مخزن Git این نصب، مخزن رسمی Hamoix نیست.'];
        }

        $branch = hamoix_maintenance_exec($git . ' branch --show-current');
        if (trim((string) ($branch['output'] ?? '')) !== 'main') {
            return ['ok' => false, 'message' => 'به‌روزرسانی فقط روی branch اصلی main مجاز است.'];
        }

        $changedFiles = [];
        foreach ([
            $git . ' diff --name-only',
            $git . ' diff --cached --name-only',
            $git . ' ls-files --others --exclude-standard',
        ] as $command) {
            $result = hamoix_maintenance_exec($command);
            if (($result['code'] ?? 1) !== 0) {
                return ['ok' => false, 'message' => 'وضعیت فایل‌های محلی قابل بررسی نیست.'];
            }
            foreach (preg_split('/\r?\n/', trim((string) ($result['output'] ?? ''))) as $file) {
                $file = trim($file);
                if ($file !== '' && !hamoix_maintenance_is_runtime_path($file)
                    && !in_array($file, $changedFiles, true)) {
                    $changedFiles[] = $file;
                }
            }
        }

        $blockedFiles = [];
        foreach ($changedFiles as $file) {
            if ($file !== 'config.php') {
                $blockedFiles[] = $file;
            }
        }
        if (!empty($blockedFiles)) {
            $shownFiles = array_slice($blockedFiles, 0, 5);
            $fileLabel = implode(', ', $shownFiles);
            if (count($blockedFiles) > count($shownFiles)) {
                $fileLabel .= ' و چند فایل دیگر';
            }
            return [
                'ok' => false,
                'message' => 'ابتدا تغییرات محلی خارج از config.php را backup یا بررسی کنید؛ update لغو شد. فایل(های) مانع: ' . $fileLabel,
            ];
        }

        $backup = hamoix_maintenance_create_backup();
        if (!($backup['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'قبل از update، backup ساخته نشد؛ update لغو شد.'];
        }

        $configPath = $root . DIRECTORY_SEPARATOR . 'config.php';
        $configTemp = null;
        $configTracked = false;
        try {
            $tracked = hamoix_maintenance_exec(
                $git . ' ls-files --error-unmatch -- config.php'
            );
            $configTracked = (($tracked['code'] ?? 1) === 0);
            if (is_file($configPath)) {
                $configTemp = tempnam(sys_get_temp_dir(), 'hamoix-config-');
                if ($configTemp === false || !@copy($configPath, $configTemp)) {
                    return ['ok' => false, 'message' => 'ذخیرهٔ موقت config.php ممکن نشد؛ update لغو شد.'];
                }
                @chmod($configTemp, 0600);
            }

            if ($configTracked) {
                $cleanConfig = hamoix_maintenance_exec(
                    $git . ' restore --source=HEAD --staged --worktree -- config.php'
                );
                if (($cleanConfig['code'] ?? 1) !== 0) {
                    hamoix_maintenance_log_command_error('temporary config checkout', $cleanConfig);
                    return ['ok' => false, 'message' => 'آماده‌سازی config.php برای update ناموفق بود.'];
                }
            } elseif (is_file($configPath) && !@unlink($configPath)) {
                return ['ok' => false, 'message' => 'آماده‌سازی فایل تنظیمات برای update ناموفق بود.'];
            }

            $fetch = hamoix_maintenance_exec(
                $git . ' fetch --prune origin main'
            );
            if (($fetch['code'] ?? 1) !== 0) {
                hamoix_maintenance_log_command_error('git fetch', $fetch);
                return ['ok' => false, 'message' => 'دریافت آخرین تغییرات GitHub ناموفق بود.'];
            }

            $merge = hamoix_maintenance_exec(
                $git . ' merge --ff-only FETCH_HEAD'
            );
            if (($merge['code'] ?? 1) !== 0) {
                hamoix_maintenance_log_command_error('git fast-forward', $merge);
                return ['ok' => false, 'message' => 'به‌روزرسانی امن fast-forward ممکن نشد؛ سورس تغییر نکرد.'];
            }

            if ($configTemp !== null && is_file($configTemp) && !@copy($configTemp, $configPath)) {
                return ['ok' => false, 'message' => 'سورس به‌روزرسانی شد اما بازگردانی config.php ناموفق بود؛ backup موجود است.'];
            }

            $phpCandidates = ['/usr/bin/php8.2', '/usr/bin/php8.3', '/usr/bin/php8.4', '/usr/bin/php'];
            $phpBin = null;
            foreach ($phpCandidates as $candidate) {
                if (is_executable($candidate)) {
                    $phpBin = $candidate;
                    break;
                }
            }
            $composerBin = is_executable('/usr/bin/composer') ? '/usr/bin/composer' : 'composer';
            if ($phpBin === null) {
                return ['ok' => true, 'warning' => true, 'backup_id' => $backup['id'], 'message' => 'سورس به‌روزرسانی شد؛ PHP CLI برای اجرای Composer پیدا نشد.'];
            }

            $composer = hamoix_maintenance_exec(
                'COMPOSER_ALLOW_SUPERUSER=1 ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($composerBin)
                . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader',
                $root
            );
            if (($composer['code'] ?? 1) !== 0) {
                hamoix_maintenance_log_command_error('composer install after update', $composer);
                return ['ok' => true, 'warning' => true, 'backup_id' => $backup['id'], 'message' => 'سورس به‌روزرسانی شد اما نصب Composer کامل نشد.'];
            }

            return ['ok' => true, 'backup_id' => $backup['id']];
        } finally {
            if ($configTemp !== null && is_file($configTemp)) {
                if (!is_file($configPath) || !@copy($configTemp, $configPath)) {
                    error_log('[hamoix/maintenance] could not restore temporary config.php');
                }
                @unlink($configTemp);
            }
        }
    }
}
