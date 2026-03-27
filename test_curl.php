<?php
// TEMPORARY TEST SCRIPT - DELETE AFTER USE
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/Database.php';
require_once MONITOR_ROOT . '/includes/Checker.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = Checker::check($data);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        case 'sites':
            $sites = Database::fetchAll('SELECT id, name, url FROM sites LIMIT 5');
            echo json_encode(['success' => true, 'data' => $sites]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
