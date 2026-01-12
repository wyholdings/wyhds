<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$role = $_GET['role'] ?? 'guest'; // guest | admin
$storeId = isset($_GET['store_id']) ? (int) $_GET['store_id'] : null;

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

function findDefaultStoreId(PDO $pdo): ?int
{
    $stmt = $pdo->query("SELECT id FROM stores ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function maskPhone(string $phone): string
{
    return preg_replace('/(\d{3})(\d{2,3})(\d{4})/', '$1-$2**-$4', $phone);
}

function sendSms(string $phone, string $message): void
{
    $smsUrl = 'https://apis.aligo.in/send/';
    $smsUserId = 'wyhds05';
    $smsKey = 's4quv1hhrlrkkepdjcue03focnkueffc';
    $smsSender = $_ENV['SMS_SENDER'] ?? '';

    $payload = [
        'user_id'     => $smsUserId,
        'key'         => $smsKey,
        'msg'         => $message,
        'receiver'    => $phone,
        'destination' => $phone,
        'sender'      => $smsSender,
        'rdate'       => '',
        'rtime'       => '',
        'testmode_yn' => '',
        'title'       => '',
        'msg_type'    => 'SMS',
    ];

    $port = stripos($smsUrl, 'https://') === 0 ? 443 : 80;

    if (!function_exists('curl_init')) {
        return; // curl 미설치 시 건너뜀
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PORT, $port);
    curl_setopt($ch, CURLOPT_URL, $smsUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // best-effort
    $res = curl_exec($ch);
    curl_close($ch);

    if ($res === false) {
        return;
    }
    $data = json_decode($res, true);
    if (!is_array($data) || ($data['result_code'] ?? -1) != 1) {
        return;
    }
}

function getStoreName(PDO $pdo, int $storeId): string
{
    $stmt = $pdo->prepare("SELECT name FROM stores WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $storeId]);
    $row = $stmt->fetch();
    return $row['name'] ?? '매장';
}

try {
    $storeId = $storeId ?: findDefaultStoreId($pdo);
    if (!$storeId) {
        http_response_code(404);
        echo json_encode(['error' => 'store_not_found']);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST; // fallback
        }

        $partySize = (int) ($payload['people'] ?? $payload['party_size'] ?? 0);
        $phoneRaw = preg_replace('/\D/', '', (string) ($payload['phone'] ?? ''));

        if ($partySize < 1 || $partySize > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_party_size']);
            exit;
        }

        if (!preg_match('/^01[0-9]{8,9}$/', $phoneRaw)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_phone']);
            exit;
        }

        // next ticket number per store
        $stmt = $pdo->prepare("SELECT IFNULL(MAX(ticket_no), 0) + 1 AS next_no FROM reservations WHERE store_id = :store_id");
        $stmt->execute([':store_id' => $storeId]);
        $nextNo = (int) $stmt->fetchColumn();

        $insert = $pdo->prepare("
            INSERT INTO reservations (store_id, ticket_no, party_size, phone, consent, status, created_at, updated_at)
            VALUES (:store_id, :ticket_no, :party_size, :phone, 1, 'waiting', NOW(), NOW())
        ");
        $insert->execute([
            ':store_id' => $storeId,
            ':ticket_no' => $nextNo,
            ':party_size' => $partySize,
            ':phone' => $phoneRaw,
        ]);

        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT id, ticket_no, party_size, phone, status, created_at FROM reservations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        // Send SMS (best effort)
        $storeName = getStoreName($pdo, $storeId);
        $smsMsg = sprintf('[%s] 대기 등록 #%d · %d명, 잠시만 기다려 주세요.', $storeName, $row['ticket_no'], $row['party_size']);
        sendSms($row['phone'], $smsMsg);

        http_response_code(201);
        echo json_encode([
            'id' => $row['id'],
            'ticket' => $row['ticket_no'],
            'people' => $row['party_size'],
            'phone' => $row['phone'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET: list
    $order = "CASE status WHEN 'waiting' THEN 1 WHEN 'calling' THEN 2 WHEN 'seated' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END ASC, id DESC";
    $stmt = $pdo->prepare("SELECT id, ticket_no, party_size, phone, status, created_at FROM reservations WHERE store_id = :store_id AND status IN ('waiting','calling','seated') ORDER BY $order");
    $stmt->execute([':store_id' => $storeId]);
    $rows = $stmt->fetchAll();

    $list = array_map(function ($row) use ($role) {
        return [
            'id' => (int) $row['id'],
            'ticket' => (int) $row['ticket_no'],
            'people' => (int) $row['party_size'],
            'phone' => $role === 'admin' ? $row['phone'] : maskPhone($row['phone']),
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    echo json_encode(['items' => $list], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('reservations_api_error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'unexpected_error']);
}
