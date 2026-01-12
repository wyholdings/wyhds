<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}

function respondStore(array $store): void
{
    echo json_encode([
        'id'      => $store['id'],
        'name'    => $store['name'],
        'phone'   => $store['phone'],
        'address' => $store['address'],
        'notice'  => $store['notice'],
    ], JSON_UNESCAPED_UNICODE);
}

try {
    if ($method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST; // fallback to form-data
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $notice = trim((string) ($payload['notice'] ?? ''));

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'name_required']);
            exit;
        }

        $stmt = $pdo->query("SELECT id FROM stores ORDER BY id ASC LIMIT 1");
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $pdo->prepare("UPDATE stores SET name = :name, phone = :phone, address = :address, notice = :notice, updated_at = NOW() WHERE id = :id");
            $update->execute([
                ':name' => $name,
                ':phone' => $phone ?: null,
                ':address' => $address ?: null,
                ':notice' => $notice ?: null,
                ':id' => $existing['id'],
            ]);
            $id = $existing['id'];
        } else {
            $insert = $pdo->prepare("INSERT INTO stores (name, phone, address, notice, created_at, updated_at) VALUES (:name, :phone, :address, :notice, NOW(), NOW())");
            $insert->execute([
                ':name' => $name,
                ':phone' => $phone ?: null,
                ':address' => $address ?: null,
                ':notice' => $notice ?: null,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("SELECT id, name, phone, address, notice FROM stores WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $store = $stmt->fetch();

        http_response_code(201);
        respondStore($store);
        exit;
    }

    // GET: 단일 매장의 경우 첫 행을 반환, 여러 매장이라면 적절한 WHERE 조건을 추가하세요.
    $stmt = $pdo->query("SELECT id, name, phone, address, notice FROM stores ORDER BY id ASC LIMIT 1");
    $store = $stmt->fetch();

    if (!$store) {
        http_response_code(404);
        echo json_encode(['error' => 'store_not_found']);
        exit;
    }

    respondStore($store);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'unexpected_error']);
}
