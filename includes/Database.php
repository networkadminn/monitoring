<?php
// =============================================================================
// includes/Database.php - Production PDO singleton with reconnect & query log
// =============================================================================

class Database {
    private static ?PDO $instance = null;
    private static int  $queryCount = 0;
    private static float $queryTime  = 0.0;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::connect();
        }
        return self::$instance;
    }

    private static function connect(): void {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s;port=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET,
            defined('DB_PORT') ? DB_PORT : 3306
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
            PDO::ATTR_TIMEOUT            => 10,
        ];
        self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    // Auto-reconnect on gone-away errors
    private static function execute_safe(string $sql, array $params): PDOStatement {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $t    = microtime(true);
            $stmt->execute($params);
            self::$queryTime  += microtime(true) - $t;
            self::$queryCount++;
            return $stmt;
        } catch (PDOException $e) {
            // MySQL server has gone away (2006) or lost connection (2013)
            if (in_array($e->errorInfo[1] ?? 0, [2006, 2013])) {
                self::$instance = null;
                $stmt = self::getInstance()->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        return self::execute_safe($sql, $params);
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::execute_safe($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): array|false {
        return self::execute_safe($sql, $params)->fetch();
    }

    public static function insert(string $sql, array $params = []): string {
        self::execute_safe($sql, $params);
        return self::getInstance()->lastInsertId();
    }

    public static function execute(string $sql, array $params = []): int {
        return self::execute_safe($sql, $params)->rowCount();
    }

    // Transaction helpers
    public static function beginTransaction(): void {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void {
        self::getInstance()->commit();
    }

    public static function rollback(): void {
        if (self::getInstance()->inTransaction()) {
            self::getInstance()->rollBack();
        }
    }

    // Debug stats
    public static function getStats(): array {
        return [
            'query_count' => self::$queryCount,
            'query_time'  => round(self::$queryTime * 1000, 2) . 'ms',
        ];
    }
}
