<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
$sites = Database::fetchAll('SELECT check_type FROM sites LIMIT 10');
header('Content-Type: application/json');
echo json_encode($sites);
