<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateToolViewTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE IF NOT EXISTS tool_view_stats (
                tool_slug VARCHAR(120) NOT NULL PRIMARY KEY,
                total_views INT UNSIGNED NOT NULL DEFAULT 0,
                last_viewed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_total_views (total_views),
                INDEX idx_last_viewed_at (last_viewed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->execute("
            CREATE TABLE IF NOT EXISTS tool_view_daily (
                tool_slug VARCHAR(120) NOT NULL,
                view_date DATE NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (tool_slug, view_date),
                INDEX idx_view_date_views (view_date, views)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS tool_view_daily');
        $this->execute('DROP TABLE IF EXISTS tool_view_stats');
    }
}
