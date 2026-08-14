<?php

require_once __DIR__ . '/lib.php';

unset($_SESSION['reseller_id'], $_SESSION['reseller_username']);
// Keep the admin session (if any) intact; only clear reseller keys.
header('Location: login.php');
exit;
