<?php

namespace App\Models;

use App\Database;
use PDO;

class ShortsProjectModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM shorts_projects ORDER BY id DESC, created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM shorts_projects WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO shorts_projects (
                channel_name,
                niche,
                target_audience,
                content_format,
                source_platform,
                automation_stack,
                publishing_frequency,
                avg_length_seconds,
                cta_text,
                monetization_model,
                status,
                topic_prompt,
                script_prompt,
                thumbnail_formula,
                compliance_notes,
                memo,
                created_at,
                updated_at
            ) VALUES (
                :channel_name,
                :niche,
                :target_audience,
                :content_format,
                :source_platform,
                :automation_stack,
                :publishing_frequency,
                :avg_length_seconds,
                :cta_text,
                :monetization_model,
                :status,
                :topic_prompt,
                :script_prompt,
                :thumbnail_formula,
                :compliance_notes,
                :memo,
                NOW(),
                NOW()
            )
        ");

        return $stmt->execute([
            ':channel_name' => $data['channel_name'],
            ':niche' => $data['niche'],
            ':target_audience' => $data['target_audience'],
            ':content_format' => $data['content_format'],
            ':source_platform' => $data['source_platform'],
            ':automation_stack' => $data['automation_stack'],
            ':publishing_frequency' => $data['publishing_frequency'],
            ':avg_length_seconds' => $data['avg_length_seconds'],
            ':cta_text' => $data['cta_text'],
            ':monetization_model' => $data['monetization_model'],
            ':status' => $data['status'],
            ':topic_prompt' => $data['topic_prompt'],
            ':script_prompt' => $data['script_prompt'],
            ':thumbnail_formula' => $data['thumbnail_formula'],
            ':compliance_notes' => $data['compliance_notes'],
            ':memo' => $data['memo'],
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE shorts_projects SET
                channel_name = :channel_name,
                niche = :niche,
                target_audience = :target_audience,
                content_format = :content_format,
                source_platform = :source_platform,
                automation_stack = :automation_stack,
                publishing_frequency = :publishing_frequency,
                avg_length_seconds = :avg_length_seconds,
                cta_text = :cta_text,
                monetization_model = :monetization_model,
                status = :status,
                topic_prompt = :topic_prompt,
                script_prompt = :script_prompt,
                thumbnail_formula = :thumbnail_formula,
                compliance_notes = :compliance_notes,
                memo = :memo,
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id' => $id,
            ':channel_name' => $data['channel_name'],
            ':niche' => $data['niche'],
            ':target_audience' => $data['target_audience'],
            ':content_format' => $data['content_format'],
            ':source_platform' => $data['source_platform'],
            ':automation_stack' => $data['automation_stack'],
            ':publishing_frequency' => $data['publishing_frequency'],
            ':avg_length_seconds' => $data['avg_length_seconds'],
            ':cta_text' => $data['cta_text'],
            ':monetization_model' => $data['monetization_model'],
            ':status' => $data['status'],
            ':topic_prompt' => $data['topic_prompt'],
            ':script_prompt' => $data['script_prompt'],
            ':thumbnail_formula' => $data['thumbnail_formula'],
            ':compliance_notes' => $data['compliance_notes'],
            ':memo' => $data['memo'],
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM shorts_projects WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getStatusCounts(): array
    {
        $stmt = $this->db->query("
            SELECT status, COUNT(*) AS total
            FROM shorts_projects
            GROUP BY status
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = [
            'planning' => 0,
            'building' => 0,
            'running' => 0,
            'paused' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS shorts_projects (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                channel_name VARCHAR(120) NOT NULL,
                niche VARCHAR(120) NOT NULL,
                target_audience VARCHAR(255) DEFAULT NULL,
                content_format VARCHAR(80) DEFAULT 'facts',
                source_platform VARCHAR(80) DEFAULT 'reddit',
                automation_stack VARCHAR(255) DEFAULT NULL,
                publishing_frequency VARCHAR(80) DEFAULT NULL,
                avg_length_seconds INT UNSIGNED DEFAULT 30,
                cta_text VARCHAR(255) DEFAULT NULL,
                monetization_model VARCHAR(80) DEFAULT 'adsense',
                status VARCHAR(20) DEFAULT 'planning',
                topic_prompt TEXT DEFAULT NULL,
                script_prompt TEXT DEFAULT NULL,
                thumbnail_formula TEXT DEFAULT NULL,
                compliance_notes TEXT DEFAULT NULL,
                memo TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_niche (niche),
                INDEX idx_monetization_model (monetization_model)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
