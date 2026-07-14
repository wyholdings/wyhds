<?php

namespace App\Models;

use App\Database;
use PDO;

class ToolEventModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function record(string $toolSlug, string $eventName, string $context, string $sessionHash): void
    {
        $stmt = $this->db->prepare("\n            INSERT INTO tool_event_logs (tool_slug, event_name, event_context, session_hash)\n            VALUES (:tool_slug, :event_name, :event_context, :session_hash)\n        ");
        $stmt->execute([
            ':tool_slug' => $toolSlug,
            ':event_name' => $eventName,
            ':event_context' => $context !== '' ? $context : null,
            ':session_hash' => $sessionHash !== '' ? $sessionHash : null,
        ]);
    }

    public function getTotals(int $days = 30): array
    {
        $days = max(1, $days);
        $stmt = $this->db->query("\n            SELECT event_name, COUNT(*) AS events, COUNT(DISTINCT NULLIF(session_hash, '')) AS sessions\n            FROM tool_event_logs\n            WHERE occurred_at >= (NOW() - INTERVAL {$days} DAY)\n            GROUP BY event_name\n        ");
        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string)$row['event_name']] = [
                'events' => (int)$row['events'],
                'sessions' => (int)$row['sessions'],
            ];
        }
        return $totals;
    }

    public function getTopTools(int $limit = 20, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);
        $stmt = $this->db->prepare("\n            SELECT\n                tool_slug,\n                SUM(event_name = 'tool_start') AS starts,\n                SUM(event_name = 'tool_complete') AS completes,\n                SUM(event_name = 'copy') AS copies,\n                SUM(event_name = 'download') AS downloads,\n                SUM(event_name = 'share') AS shares,\n                SUM(event_name = 'favorite') AS favorites,\n                MAX(occurred_at) AS last_event_at\n            FROM tool_event_logs\n            WHERE occurred_at >= (NOW() - INTERVAL {$days} DAY)\n            GROUP BY tool_slug\n            ORDER BY completes DESC, starts DESC, tool_slug ASC\n            LIMIT :limit\n        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("\n            CREATE TABLE IF NOT EXISTS tool_event_logs (\n                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                tool_slug VARCHAR(120) NOT NULL,\n                event_name VARCHAR(40) NOT NULL,\n                event_context VARCHAR(60) DEFAULT NULL,\n                session_hash CHAR(64) DEFAULT NULL,\n                occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_tool_event_time (tool_slug, event_name, occurred_at),\n                INDEX idx_event_time (event_name, occurred_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n        ");
    }
}
