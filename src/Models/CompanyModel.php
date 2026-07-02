<?php

namespace App\Models;

use App\Database;
use PDO;

class CompanyModel
{
    //업체 전체 목록 조회
    public function getAll(array $filters = []): array
    {
        $db = Database::getInstance()->getConnection();

        [$whereSql, $params] = $this->buildFilterSql($filters);

        $stmt = $db->prepare("SELECT * FROM companies {$whereSql} ORDER BY created_at DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSummary(): array
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'active') AS active,
                SUM(status = 'hold') AS hold,
                SUM(status = 'inactive') AS inactive,
                SUM(contract_end IS NOT NULL AND contract_end < CURDATE()) AS expired,
                SUM(contract_end IS NOT NULL AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS due_30
            FROM companies
        ");

        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($summary['total'] ?? 0),
            'active' => (int)($summary['active'] ?? 0),
            'hold' => (int)($summary['hold'] ?? 0),
            'inactive' => (int)($summary['inactive'] ?? 0),
            'expired' => (int)($summary['expired'] ?? 0),
            'due_30' => (int)($summary['due_30'] ?? 0),
        ];
    }

    public function getContractDueSoon(int $limit = 8, int $days = 30): array
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            SELECT *
            FROM companies
            WHERE contract_end IS NOT NULL
                AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
            ORDER BY contract_end ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    //업체 등록
    public function insert(array $data): bool
    {

        $db = Database::getInstance()->getConnection();

        $sql = "INSERT INTO companies 
            (name, business_number, type, contract_start, contract_end, manager, phone, email, address, status, memo, created_at, updated_at)
            VALUES 
            (:name, :business_number, :type, :contract_start, :contract_end, :manager, :phone, :email, :address, :status, :memo, NOW(), NOW())";

        $stmt = $db->prepare($sql);

        return $stmt->execute([
            ':name'            => $data['name'],
            ':business_number' => $data['business_number'],
            ':type'            => $data['type'],
            ':contract_start'  => $data['contract_start'],
            ':contract_end'    => $data['contract_end'],
            ':manager'         => $data['manager'],
            ':phone'           => $data['phone'],
            ':email'           => $data['email'],
            ':address'         => $data['address'],
            ':status'          => $data['status'],
            ':memo'            => $data['memo'],
        ]);
    }

    // 업체 정보를 데이터베이스에서 가져오는 메서드
    public function getCompany($companyId)
    {

        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->bindParam(':id', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC); // 업체 정보를 배열로 반환
    }

    // 업체 수정
    public function updateCompany(int $id, array $data): bool
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            UPDATE companies SET
                name = :name,
                business_number = :business_number,
                type = :type,
                contract_start = :contract_start,
                contract_end = :contract_end,
                manager = :manager,
                phone = :phone,
                email = :email,
                address = :address,
                status = :status,
                memo = :memo,
                updated_at = NOW()
            WHERE id = :id
        ");

        $data['id'] = $id;

        return $stmt->execute($data);
    }

    // 업체 삭제
    public function deleteCompany(int $id): bool
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("DELETE FROM companies WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    private function buildFilterSql(array $filters): array
    {
        $conditions = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = "(name LIKE :q OR business_number LIKE :q OR manager LIKE :q OR phone LIKE :q OR email LIKE :q OR address LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = "status = :status";
            $params[':status'] = $status;
        }

        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '') {
            $conditions[] = "type = :type";
            $params[':type'] = $type;
        }

        $contract = trim((string)($filters['contract'] ?? ''));
        if ($contract === 'expired') {
            $conditions[] = "contract_end IS NOT NULL AND contract_end < CURDATE()";
        } elseif (in_array($contract, ['7', '30', '60'], true)) {
            $conditions[] = "contract_end IS NOT NULL AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . (int)$contract . " DAY)";
        } elseif ($contract === 'none') {
            $conditions[] = "contract_end IS NULL";
        }

        return [
            $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
            $params,
        ];
    }

}

?>
