<?php
// Temporary debug file - DELETE after fixing the 500 error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h2>PHP Version: ' . PHP_VERSION . '</h2>';

// Check required extensions
$exts = ['pdo', 'pdo_mysql', 'curl', 'openssl', 'mbstring'];
foreach ($exts as $ext) {
    $ok = extension_loaded($ext);
    echo '<p style="color:' . ($ok ? 'green' : 'red') . '">'
       . ($ok ? '✓' : '✗') . ' ' . $ext . '</p>';
}

// Test DB connection
echo '<h3>DB Connection Test</h3>';
require_once __DIR__ . '/config.php';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo '<p style="color:green">✓ Database connected successfully</p>';

    // Check if tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo '<p>Tables found: ' . (empty($tables) ? '<strong style="color:orange">NONE — run install.php first</strong>' : implode(', ', $tables)) . '</p>';

} catch (PDOException $e) {
    echo '<p style="color:red">✗ DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Test index.php directly
echo '<h3>index.php Parse Check</h3>';
$output = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/index.php') . ' 2>&1');
echo '<pre>' . htmlspecialchars($output) . '</pre>';

echo '<hr><p style="color:orange"><strong>Delete debug.php after use!</strong></p>';
