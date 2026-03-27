<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Statistics.php';

header('Content-Type: application/json');

try {
    $health = Statistics::getSystemHealth();
    echo json_encode(['success' => true, 'data' => $health]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
