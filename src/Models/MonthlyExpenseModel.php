<?php

namespace App\Models;

use App\Database;
use PDO;

class MonthlyExpenseModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureTable();
    }

    public function getByMonth(int $year, int $month): array
    {
        $stmt = $this->db->prepare('SELECT * FROM monthly_expenses WHERE YEAR(expense_date) = ? AND MONTH(expense_date) = ? ORDER BY expense_date ASC, id ASC');
        $stmt->execute([$year, $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSummary(int $year, int $month): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS total_sum FROM monthly_expenses WHERE YEAR(expense_date) = ? AND MONTH(expense_date) = ?');
        $stmt->execute([$year, $month]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['row_count' => 0, 'total_sum' => 0];
    }

    public function add(array $data): bool
    {
        $repeatCount = $data['is_recurring'] ? max(1, (int)$data['repeat_count']) : 1;
        $this->db->beginTransaction();
        try {
            for ($index = 0; $index < $repeatCount; $index++) {
                $entry = $data;
                $entry['expense_date'] = $this->nextMonthlyDate($data['expense_date'], $index);
                $entry['repeat_count'] = $repeatCount;
                if (!$this->insert($entry)) {
                    $this->db->rollBack();
                    return false;
                }
            }
            return $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('UPDATE monthly_expenses SET expense_date = :expense_date, person_name = :person_name, item_name = :item_name, amount = :amount, payment_account = :payment_account, cardholder_name = :cardholder_name, description = :description, is_recurring = :is_recurring, repeat_count = :repeat_count WHERE id = :id');
        $params = $this->params($data);
        $params[':id'] = $id;
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        return $this->db->prepare('DELETE FROM monthly_expenses WHERE id = ?')->execute([$id]);
    }

    private function params(array $data): array
    {
        return [
            ':expense_date' => $data['expense_date'], ':person_name' => $data['person_name'],
            ':item_name' => $data['item_name'], ':amount' => $data['amount'],
            ':payment_account' => $data['payment_account'] ?: null, ':cardholder_name' => $data['cardholder_name'] ?: null,
            ':description' => $data['description'] ?: null,
            ':is_recurring' => (int)$data['is_recurring'], ':repeat_count' => (int)$data['repeat_count'],
        ];
    }

    private function insert(array $data): bool
    {
        $stmt = $this->db->prepare('INSERT INTO monthly_expenses (expense_date, person_name, item_name, amount, payment_account, cardholder_name, description, is_recurring, repeat_count) VALUES (:expense_date, :person_name, :item_name, :amount, :payment_account, :cardholder_name, :description, :is_recurring, :repeat_count)');
        return $stmt->execute($this->params($data));
    }

    private function nextMonthlyDate(string $date, int $index): string
    {
        $base = new \DateTimeImmutable($date);
        $monthStart = new \DateTimeImmutable($base->format('Y-m-01'));
        $target = $index ? $monthStart->modify('+' . $index . ' month') : $monthStart;
        $day = min((int)$base->format('d'), (int)$target->format('t'));
        return $target->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    }

    private function ensureTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS monthly_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            person_name VARCHAR(80) NOT NULL,
            item_name VARCHAR(160) NOT NULL,
            amount INT UNSIGNED NOT NULL,
            payment_account VARCHAR(160) NULL,
            cardholder_name VARCHAR(80) NULL,
            description TEXT NULL,
            is_recurring TINYINT(1) NOT NULL DEFAULT 0,
            repeat_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_monthly_expenses_date (expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = $this->db->query('SHOW COLUMNS FROM monthly_expenses')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_recurring', $columns, true)) {
            $this->db->exec('ALTER TABLE monthly_expenses ADD COLUMN is_recurring TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
        }
        if (!in_array('repeat_count', $columns, true)) {
            $this->db->exec('ALTER TABLE monthly_expenses ADD COLUMN repeat_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_recurring');
        }
    }
}
