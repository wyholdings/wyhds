<?php

namespace App\Models;

use App\Database;

class PortfolioModel
{
    public function all(): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query('SELECT * FROM portfolios ORDER BY project_date DESC, id DESC');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM portfolios WHERE id = ?');
        $stmt->execute([$id]);
        $portfolio = $stmt->fetch();

        return $portfolio ?: null;
    }

    public function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'INSERT INTO portfolios
                (title, subtitle, description, site_link, client, project_date, keywords, thumbnail_image, body_image, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute($this->values($data));

        return (int)$db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'UPDATE portfolios
             SET title = ?,
                 subtitle = ?,
                 description = ?,
                 site_link = ?,
                 client = ?,
                 project_date = ?,
                 keywords = ?,
                 thumbnail_image = ?,
                 body_image = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $values = $this->values($data);
        $values[] = $id;
        $stmt->execute($values);
    }

    public function delete(int $id): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('DELETE FROM portfolios WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function values(array $data): array
    {
        return [
            trim((string)($data['title'] ?? '')),
            trim((string)($data['subtitle'] ?? '')),
            trim((string)($data['description'] ?? '')),
            trim((string)($data['site_link'] ?? '')),
            trim((string)($data['client'] ?? '')),
            ($data['project_date'] ?? '') !== '' ? $data['project_date'] : null,
            trim((string)($data['keywords'] ?? '')),
            trim((string)($data['thumbnail_image'] ?? '')),
            trim((string)($data['body_image'] ?? '')),
        ];
    }
}
