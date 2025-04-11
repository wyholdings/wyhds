<?php
namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $db   = $_ENV['DB_NAME'] ?? 'test';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';
        $port = $_ENV['DB_PORT'] ?? 3306;

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
            $this->connection = new PDO($dsn, $user, $pass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('DB 연결 실패: ' . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
