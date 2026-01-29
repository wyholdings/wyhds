<?php

namespace App\Models;

use App\Database;
use PDO;

class MoneyModel
{
    //문의 전체 목록 조회
    public function getAll($member_name, $type, $year, $period = 'year', $periodValue = null)
    {
        $db = Database::getInstance()->getConnection();

        $whereSql = "member_name = ? AND type = ? AND YEAR(date) = ?";
        $params = [$member_name, $type, $year];

        if ($period === 'month' && $periodValue) {
            $whereSql .= " AND MONTH(date) = ?";
            $params[] = (int)$periodValue;
        } elseif ($period === 'quarter' && $periodValue) {
            $whereSql .= " AND QUARTER(date) = ?";
            $params[] = (int)$periodValue;
        }

        $stmt = $db->prepare("SELECT * FROM account_book WHERE {$whereSql} ORDER BY date ASC, created_at DESC");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getSumByMember($member_name, $type, $year, $period = 'year', $periodValue = null) 
    {
        $db = Database::getInstance()->getConnection();

        $whereSql = "member_name = ? AND type = ? AND YEAR(date) = ?";
        $params = [$member_name, $type, $year];

        if ($period === 'month' && $periodValue) {
            $whereSql .= " AND MONTH(date) = ?";
            $params[] = (int)$periodValue;
        } elseif ($period === 'quarter' && $periodValue) {
            $whereSql .= " AND QUARTER(date) = ?";
            $params[] = (int)$periodValue;
        }

        $stmt = $db->prepare("
            SELECT 
                COUNT(*) AS row_count,
                SUM(amount) AS amount_sum, 
                SUM(vat) AS vat_sum, 
                SUM(amount + vat) AS total_sum 
            FROM account_book 
            WHERE {$whereSql}
        ");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function add($type, $amount, $vat, $date, $company, $product, $member_name) 
    {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO account_book (member_name, type, amount, vat, date, company, product) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt->execute([$member_name, $type, $amount, $vat, $date, $company, $product])) {
            return $db->lastInsertId();  // ← 여기서 삽입된 ID 반환
        }

        return false;
    }

    public function delete($id) 
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            DELETE FROM account_book WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    
}

?>
