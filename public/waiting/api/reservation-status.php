<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
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
        return;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PORT, $port);
    curl_setopt($ch, CURLOPT_URL, $smsUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
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

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $id = (int) ($payload['id'] ?? 0);
    $status = strtolower((string) ($payload['status'] ?? ''));
    $allowed = ['waiting', 'calling', 'seated', 'cancelled'];

    if ($id <= 0 || !in_array($status, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_params']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE reservations SET status = :status, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);

    $stmt = $pdo->prepare("SELECT id, store_id, ticket_no, party_size, phone, status, created_at FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    if ($status === 'calling') {
        $storeName = getStoreName($pdo, (int) $row['store_id']);
        $msg = sprintf('[%s] 입장 안내 #%d · %d명, 지금 입장해주세요.', $storeName, $row['ticket_no'], $row['party_size']);
        sendSms($row['phone'], $msg);
    }

    if ($status === 'cancelled') {
        $storeName = getStoreName($pdo, (int) $row['store_id']);
        $msg = sprintf('[%s] 대기가 취소되었습니다. 필요 시 다시 등록해주세요.', $storeName);
        sendSms($row['phone'], $msg);
    }

    echo json_encode([
        'id' => (int) $row['id'],
        'ticket' => (int) $row['ticket_no'],
        'people' => (int) $row['party_size'],
        'phone' => $row['phone'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('reservation_status_error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'unexpected_error']);
}
