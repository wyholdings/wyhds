<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateToolEventLogs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("\n            CREATE TABLE IF NOT EXISTS tool_event_logs (\n                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                tool_slug VARCHAR(120) NOT NULL,\n                event_name VARCHAR(40) NOT NULL,\n                event_context VARCHAR(60) DEFAULT NULL,\n                session_hash CHAR(64) DEFAULT NULL,\n                occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_tool_event_time (tool_slug, event_name, occurred_at),\n                INDEX idx_event_time (event_name, occurred_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n        ");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS tool_event_logs');
    }
}
